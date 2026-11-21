<?php
/**
 * import.php: loads a backup file (from the old calendar's backup page or migrate.php,
 * or from this one) into the calendar. Also finishes first-time setup.
 *
 *   php bin/import.php <file.json> [--replace] [--dry-run]
 *
 *   --replace  delete the bookings and activity already here before importing
 *   --dry-run  check the file and say what would happen, without changing anything
 *
 * In Docker, copy the file into the container first:
 *   docker compose cp micalendar-backup.json web:/tmp/backup.json
 *   docker compose exec web php bin/import.php /tmp/backup.json
 */
require __DIR__ . '/_cli.php';

$args = array_slice($argv, 1);
$flags = array_values(array_filter($args, fn($a) => str_starts_with($a, '--')));
$files = array_values(array_diff($args, $flags));
if (count($files) !== 1 || array_diff($flags, ['--replace', '--dry-run'])) {
    fwrite(STDERR, "Usage: php bin/import.php <file.json> [--replace] [--dry-run]\n");
    exit(2);
}
$replace = in_array('--replace', $flags, true);
$dry_run = in_array('--dry-run', $flags, true);

$path = $files[0];
if (!is_readable($path)) {
    fail("Can't read {$path}");
}
$pdo = cli_db();
try {
    $backup = backup_parse(file_get_contents($path));
} catch (RuntimeException $e) {
    fail($e->getMessage());
}
printf("Backup from %s, exported %s: %s.\n", $backup['source'] ?? 'unknown', $backup['exported_at'] ?? 'unknown', backup_summary($backup));

$existing = calendar_counts($pdo);
if (array_sum($existing) > 0 && !$replace) {
    fail(sprintf('This calendar already has %d bookings and %d activity entries. Re-run with --replace to delete them and import the backup.',
        $existing['bookings'], $existing['changelog']));
}
if ($dry_run) {
    if (array_sum($existing) > 0) {
        printf("Would delete %d bookings and %d activity entries first.\n", $existing['bookings'], $existing['changelog']);
    }
    echo "Dry run: the backup is valid, nothing was changed.\n";
    exit(0);
}
try {
    $counts = backup_import($pdo, $backup, $replace);
} catch (Throwable $e) {
    fail('Import failed, nothing was changed: ' . $e->getMessage());
}
printf("Imported %d bookings and %d activity entries.\n", $counts['bookings'], $counts['changelog']);
