<?php
// api.php: the calendar's JSON API. Every request needs a family PIN in the X-App-Pin
// header (APP_PINS in .env). Because it's a custom header, other websites can't make a
// visitor's browser send one, so no CSRF token is needed.
//
//   GET                       all bookings (FullCalendar event objects)
//   GET ?fetch=changelog      Recent Activity (?limit=, 20 by default, 200 max)
//   POST ?action=log_view     records a hidden page view
//   POST / PUT / DELETE       create / change / delete a booking (JSON body)

require __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function fail($code, $message, array $extra = []) {
    respond(['status' => 'error', 'message' => $message] + $extra, $code);
}

try {
    // Until first-time setup is done, the page sends visitors to setup.php.
    if (!setup_done($pdo)) {
        fail(503, 'The calendar is not set up yet.', ['setup' => true]);
    }
    if (!APP_PINS) {
        fail(503, 'No family PINs are set. Add APP_PINS to the .env file on the server.');
    }

    // --- PIN check, with a lockout after too many wrong guesses ---
    $clientPin = $_SERVER['HTTP_X_APP_PIN'] ?? '';
    if ($clientPin === '') {
        fail(401, 'PIN required.');
    }
    if (auth_locked_out($pdo, 'pin')) {
        fail(429, 'Too many wrong PINs. Please wait ' . lockout_minutes() . ' minutes and try again.');
    }
    if (!secret_matches($clientPin, APP_PINS)) {
        auth_record_failure($pdo, 'pin');
        fail(401, 'Invalid PIN.');
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $data = json_decode(file_get_contents('php://input'), true);

    // Validates and normalizes a booking payload (used by POST and PUT)
    function validateBooking($data) {
        if (!is_array($data)) fail(400, 'Invalid request.');

        $name = trim((string)($data['renter_name'] ?? ''));
        if ($name === '') fail(400, 'Name is required.');
        if (mb_strlen($name) > 100) fail(400, 'Name is too long (max 100 characters).');

        $type = $data['cottage_type'] ?? '';
        if (!in_array($type, ['front', 'back', 'both'], true)) fail(400, 'Invalid cottage selection.');

        $start = (string)($data['start_date'] ?? '');
        $end = (string)($data['end_date'] ?? '');
        foreach ([$start, $end] as $d) {
            $dt = DateTime::createFromFormat('!Y-m-d', $d);
            if (!$dt || $dt->format('Y-m-d') !== $d) fail(400, 'Invalid date.');
        }
        if ($end <= $start) fail(400, 'Departure must be on or after arrival.');

        return ['renter_name' => $name, 'cottage_type' => $type, 'start_date' => $start, 'end_date' => $end];
    }

    // Rejects bookings that overlap an existing one for the same cottage ('both' clashes with everything)
    function assertNoConflict(PDO $pdo, $b, $excludeId = null) {
        $types = $b['cottage_type'] === 'both' ? ['front', 'back', 'both'] : [$b['cottage_type'], 'both', 'both'];
        $stmt = $pdo->prepare("
            SELECT renter_name, start_date, end_date FROM bookings
            WHERE start_date < ? AND end_date > ?
            AND cottage_type IN (?, ?, ?)
            AND id <> ?
            LIMIT 1
        ");
        $stmt->execute([$b['end_date'], $b['start_date'], $types[0], $types[1], $types[2], $excludeId ?? 0]);
        if ($clash = $stmt->fetch()) {
            $lastNight = date('M j', strtotime($clash['end_date'] . ' -1 day'));
            fail(409, "Those dates overlap with {$clash['renter_name']}'s booking (" . date('M j', strtotime($clash['start_date'])) . " to {$lastNight}).");
        }
    }

    function logActivity(PDO $pdo, $action, $details) {
        $pdo->prepare('INSERT INTO changelog (action, details, ip_address) VALUES (?, ?, ?)')
            ->execute([$action, $details, client_ip()]);
    }

    if ($method === 'GET') {
        if (($_GET['fetch'] ?? '') === 'changelog') {
            // 'Viewed' events stay hidden from the page
            $limit = max(1, min(200, (int)($_GET['limit'] ?? 20)));
            $stmt = $pdo->prepare("SELECT action, details, CAST(strftime('%s', created_at) AS INTEGER) AS unix_time
                FROM changelog WHERE action != 'Viewed' ORDER BY created_at DESC, id DESC LIMIT ?");
            $stmt->bindValue(1, $limit + 1, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            respond(['status' => 'success', 'data' => array_slice($rows, 0, $limit), 'has_more' => count($rows) > $limit]);
        }

        $colors = ['front' => '#3788d8', 'back' => '#28a745', 'both' => '#6f42c1'];
        $bookings = $pdo->query('SELECT id, renter_name, start_date AS start, end_date AS "end", cottage_type FROM bookings')->fetchAll();
        foreach ($bookings as &$booking) {
            $booking['id'] = (int)$booking['id'];
            $booking['extendedProps'] = ['renter_name' => $booking['renter_name'], 'cottage_type' => $booking['cottage_type']];
            $booking['color'] = $colors[$booking['cottage_type']];
            // FullCalendar renders titles as plain text, so no HTML escaping here
            $booking['title'] = $booking['renter_name'] . ' (' . ucfirst($booking['cottage_type']) . ')';
        }
        respond($bookings);
    }
    elseif ($method === 'POST' && ($_GET['action'] ?? '') === 'log_view') {
        logActivity($pdo, 'Viewed', 'User accessed the booking application.');
        respond(['status' => 'success']);
    }
    elseif ($method === 'POST') {
        $data = validateBooking($data);
        // New stays can't start in the past (one day of slack for visitors in other time zones)
        $earliest = (new DateTime('yesterday'))->format('Y-m-d');
        if ($data['start_date'] < $earliest) fail(400, "New bookings can't start in the past.");

        $pdo->exec('BEGIN IMMEDIATE'); // so two people can't book the same dates at once
        assertNoConflict($pdo, $data);
        $pdo->prepare('INSERT INTO bookings (renter_name, cottage_type, start_date, end_date) VALUES (?, ?, ?, ?)')
            ->execute([$data['renter_name'], $data['cottage_type'], $data['start_date'], $data['end_date']]);
        logActivity($pdo, 'Created', "{$data['renter_name']} booked the " . ucfirst($data['cottage_type']) . " cottage from {$data['start_date']} to {$data['end_date']}.");
        $pdo->exec('COMMIT');
        respond(['status' => 'success']);
    }
    elseif ($method === 'PUT') {
        $id = (int)($data['id'] ?? 0);
        $data = validateBooking($data);
        $newName = $data['renter_name'];

        $pdo->exec('BEGIN IMMEDIATE');
        $stmt = $pdo->prepare('SELECT renter_name, cottage_type, start_date, end_date FROM bookings WHERE id = ?');
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) fail(404, 'That booking no longer exists. Refresh the page.');

        assertNoConflict($pdo, $data, $id);
        $pdo->prepare('UPDATE bookings SET renter_name = ?, cottage_type = ?, start_date = ?, end_date = ? WHERE id = ?')
            ->execute([$newName, $data['cottage_type'], $data['start_date'], $data['end_date'], $id]);

        $changes = [];
        if ($old['renter_name'] !== $newName) $changes[] = "name from '{$old['renter_name']}' to '{$newName}'";
        if ($old['cottage_type'] !== $data['cottage_type']) $changes[] = 'cottage from ' . ucfirst($old['cottage_type']) . ' to ' . ucfirst($data['cottage_type']);
        if ($old['start_date'] !== $data['start_date'] || $old['end_date'] !== $data['end_date']) $changes[] = "dates from [{$old['start_date']} to {$old['end_date']}] to [{$data['start_date']} to {$data['end_date']}]";
        if ($changes) {
            logActivity($pdo, 'Updated', "Modified booking for {$newName}: Changed " . implode(', ', $changes) . '.');
        }
        $pdo->exec('COMMIT');
        respond(['status' => 'success']);
    }
    elseif ($method === 'DELETE') {
        $id = (int)(is_array($data) ? ($data['id'] ?? 0) : 0);
        $pdo->exec('BEGIN IMMEDIATE');
        $stmt = $pdo->prepare('SELECT renter_name, cottage_type, start_date, end_date FROM bookings WHERE id = ?');
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        $pdo->prepare('DELETE FROM bookings WHERE id = ?')->execute([$id]);
        if ($target) {
            logActivity($pdo, 'Canceled', "Deleted booking for {$target['renter_name']} ({$target['start_date']} to {$target['end_date']}).");
        }
        $pdo->exec('COMMIT');
        respond(['status' => 'success']);
    }
    else {
        fail(405, 'Method Not Allowed');
    }

} catch (Throwable $e) {
    // Full details go to the container log; the browser only gets a generic message
    error_log('Cottage Calendar api.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    fail(500, 'Server error. Please try again.');
}
