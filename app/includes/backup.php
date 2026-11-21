<?php
/**
 * backup.php
 *
 * The backup file shared with the original MySQL version (its migrate.php): every
 * booking, past and future, plus the full activity log, with their original ids.
 *
 *   {"format": "micalendar-backup", "version": 1, "exported_at": "...Z",
 *    "timestamps": "UTC", "source": "...", "tables": {"bookings": [...], "changelog": [...]}}
 *
 * Used by setup.php, backup.php (web export) and bin/import.php / bin/export.php.
 */

const BACKUP_FORMAT = 'micalendar-backup';
const BACKUP_VERSION = 1;

const BACKUP_COLUMNS = [
    'bookings'  => ['id', 'renter_name', 'cottage_type', 'start_date', 'end_date', 'created_at'],
    'changelog' => ['id', 'action', 'details', 'created_at', 'ip_address'],
];

/** Row counts per table, e.g. ['bookings' => 12, 'changelog' => 80]. */
function calendar_counts(PDO $pdo) {
    $counts = [];
    foreach (array_keys(BACKUP_COLUMNS) as $table) {
        $counts[$table] = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
    return $counts;
}

/** Reads both tables into the backup structure. */
function backup_build(PDO $pdo) {
    $tables = [];
    foreach (BACKUP_COLUMNS as $table => $columns) {
        $rows = $pdo->query('SELECT ' . implode(', ', $columns) . " FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id']; // the original wrote ids as numbers
        }
        unset($row);
        $tables[$table] = $rows;
    }
    return [
        'format' => BACKUP_FORMAT,
        'version' => BACKUP_VERSION,
        'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'timestamps' => 'UTC',
        'source' => gethostname() ?: 'unknown',
        'tables' => $tables,
    ];
}

function backup_encode(array $backup) {
    return json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

/**
 * Reads and checks a backup file's text. Returns the backup with every row checked.
 * Throws RuntimeException with a message that's safe to show.
 */
function backup_parse($json_text) {
    try {
        $backup = json_decode((string)$json_text, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException("That file isn't valid JSON. Use the backup file downloaded from the old calendar's backup page.");
    }
    if (!is_array($backup) || ($backup['format'] ?? null) !== BACKUP_FORMAT) {
        throw new RuntimeException("That file isn't a cottage calendar backup.");
    }
    if (($backup['version'] ?? null) !== BACKUP_VERSION) {
        throw new RuntimeException('Unsupported backup version: ' . json_encode($backup['version'] ?? null) . '.');
    }

    $is_date = fn($d) => is_string($d) && ($dt = DateTime::createFromFormat('!Y-m-d', $d)) && $dt->format('Y-m-d') === $d;
    $is_stamp = fn($t) => $t === null || (is_string($t) && ($dt = DateTime::createFromFormat('!Y-m-d H:i:s', $t)) && $dt->format('Y-m-d H:i:s') === $t);

    foreach (BACKUP_COLUMNS as $table => $columns) {
        $rows = $backup['tables'][$table] ?? null;
        if (!is_array($rows) || ($rows && !array_is_list($rows))) {
            throw new RuntimeException("The backup is missing the \"{$table}\" table.");
        }
        $seen = [];
        foreach ($rows as $i => $row) {
            $where = "{$table} row " . ($i + 1);
            foreach ($columns as $column) {
                if (!is_array($row) || !array_key_exists($column, $row)) {
                    throw new RuntimeException("{$where} is missing \"{$column}\".");
                }
            }
            $id = filter_var($row['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new RuntimeException("{$where} has an invalid id.");
            if (isset($seen[$id])) throw new RuntimeException("{$where} repeats id {$id}.");
            $seen[$id] = true;
            if ($row['created_at'] === '0000-00-00 00:00:00') { // MySQL's "no time"
                $row['created_at'] = $backup['tables'][$table][$i]['created_at'] = null;
            }
            if (!$is_stamp($row['created_at'])) throw new RuntimeException("{$where} has an invalid created_at.");

            if ($table === 'bookings') {
                if (!is_string($row['renter_name']) || trim($row['renter_name']) === '' || mb_strlen($row['renter_name']) > 100) throw new RuntimeException("{$where} has an invalid name.");
                if (!in_array($row['cottage_type'], ['front', 'back', 'both'], true)) throw new RuntimeException("{$where} has an invalid cottage.");
                if (!$is_date($row['start_date']) || !$is_date($row['end_date']) || $row['end_date'] <= $row['start_date']) throw new RuntimeException("{$where} has invalid dates.");
            } else {
                if (!is_string($row['action']) || $row['action'] === '' || mb_strlen($row['action']) > 50) throw new RuntimeException("{$where} has an invalid action.");
                if (!is_string($row['details'])) throw new RuntimeException("{$where} has invalid details.");
                if ($row['ip_address'] !== null && (!is_string($row['ip_address']) || strlen($row['ip_address']) > 45)) throw new RuntimeException("{$where} has an invalid IP address.");
            }
        }
    }
    return $backup;
}

/**
 * Loads a checked backup (from backup_parse) into the database. Refuses if the calendar
 * already has bookings or activity, unless $replace is true (then they're deleted first).
 * Joins the caller's transaction if one is open, otherwise all or nothing on its own.
 * Returns the number of rows per table.
 */
function backup_import(PDO $pdo, array $backup, $replace = false) {
    $existing = calendar_counts($pdo);
    if (array_sum($existing) > 0 && !$replace) {
        throw new RuntimeException(sprintf(
            'This calendar already has %d bookings and %d activity entries. A backup can only be loaded into an empty calendar.',
            $existing['bookings'], $existing['changelog']));
    }

    $own_transaction = !$pdo->inTransaction();
    if ($own_transaction) {
        $pdo->beginTransaction();
    }
    try {
        $counts = [];
        foreach (BACKUP_COLUMNS as $table => $columns) {
            if ($replace) {
                $pdo->exec("DELETE FROM {$table}");
            }
            $stmt = $pdo->prepare("INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
            foreach ($backup['tables'][$table] as $row) {
                $stmt->execute(array_map(fn($c) => $c === 'id' ? (int)$row[$c] : $row[$c], $columns));
            }
            $counts[$table] = count($backup['tables'][$table]);
        }
        mark_setup_done($pdo);
        if ($own_transaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own_transaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $counts;
}

/** "12 bookings (3 current or upcoming) and 80 activity entries" */
function backup_summary(array $backup) {
    $bookings = $backup['tables']['bookings'];
    $today = date('Y-m-d');
    $upcoming = count(array_filter($bookings, fn($b) => $b['end_date'] > $today));
    return sprintf('%d bookings (%d current or upcoming) and %d activity entries',
        count($bookings), $upcoming, count($backup['tables']['changelog']));
}
