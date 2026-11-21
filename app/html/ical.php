<?php
// ical.php: the calendar feed that phones subscribe to. Protected by the shared
// username and password in .env (ICAL_USER, ICAL_PASS); empty ICAL_PASS turns it off.

require __DIR__ . '/../includes/bootstrap.php';

function deny($code, $message) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    exit($message);
}

if (!setup_done($pdo)) {
    deny(503, 'The calendar is not set up yet.');
}
if (ICAL_PASS === '') {
    deny(404, 'The calendar feed is turned off.');
}
if (auth_locked_out($pdo, 'ical')) {
    deny(429, 'Too many wrong passwords. Please wait ' . lockout_minutes() . ' minutes and try again.');
}

// Credentials sent by the calendar app (HTTP Basic authentication)
$authUser = $_SERVER['PHP_AUTH_USER'] ?? '';
$authPass = $_SERVER['PHP_AUTH_PW'] ?? '';
if (!secret_matches($authUser, [ICAL_USER]) || !secret_matches($authPass, [ICAL_PASS])) {
    // A first request without credentials is normal (it makes the app ask), so don't count it.
    if ($authUser !== '' || $authPass !== '') {
        auth_record_failure($pdo, 'ical');
    }
    // Makes the phone or browser show its login prompt
    header('WWW-Authenticate: Basic realm="Family Cottage Calendar"');
    deny(401, 'Unauthorized: Valid username and password required.');
}

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="cottages.ics"');
header('Cache-Control: no-store');

try {
    $bookings = $pdo->query('SELECT id, renter_name, start_date, end_date, cottage_type, created_at FROM bookings ORDER BY start_date')->fetchAll();
} catch (Throwable $e) {
    error_log('Cottage Calendar ical.php: ' . $e->getMessage());
    deny(500, 'Error generating calendar feed.');
}

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//Family Cottages//Booking System//EN\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";
echo "X-WR-CALNAME:Family Cottages\r\n";
echo "X-WR-TIMEZONE:UTC\r\n";

foreach ($bookings as $booking) {
    // Same event ids as the original version, so subscribed calendars don't see duplicates
    $uid = 'booking-' . $booking['id'] . '@cottages.local';
    $dtstamp = gmdate('Ymd\THis\Z', $booking['created_at'] ? strtotime($booking['created_at'] . ' UTC') : time());
    $dtstart = str_replace('-', '', $booking['start_date']);
    $dtend = str_replace('-', '', $booking['end_date']);

    // iCal text escaping (not HTML): backslash, semicolon, comma and newlines
    $name = str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n"], $booking['renter_name']);
    $summary = $name . ' (' . ucfirst($booking['cottage_type']) . ')';

    echo "BEGIN:VEVENT\r\n";
    echo "UID:{$uid}\r\n";
    echo "DTSTAMP:{$dtstamp}\r\n";
    echo "DTSTART;VALUE=DATE:{$dtstart}\r\n";
    echo "DTEND;VALUE=DATE:{$dtend}\r\n";
    echo "SUMMARY:{$summary}\r\n";
    echo "TRANSP:TRANSPARENT\r\n";
    echo "END:VEVENT\r\n";
}

echo "END:VCALENDAR\r\n";
