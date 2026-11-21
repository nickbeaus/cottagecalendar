<?php
/**
 * digest.php
 *
 * The daily digest email: yesterday's bookings activity and the stays arriving in the
 * next 7 days. Sent once a day at DIGEST_TIME by bin/scheduler.php, to DIGEST_RECIPIENTS.
 * Quiet days are skipped, except that a stay starting exactly a week out always gets a
 * heads-up.
 */

require_once __DIR__ . '/mail.php';

const COTTAGE_COLORS = ['front' => '#3788d8', 'back' => '#28a745', 'both' => '#6f42c1'];
const ACTION_COLORS = ['Created' => '#059669', 'Updated' => '#2563eb', 'Canceled' => '#dc2626'];

/** A UTC "Y-m-d H:i:s" database time, in APP_TIMEZONE. */
function local_time($utc) {
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(APP_TIMEZONE));
}

/** Whole days from one Y-m-d date to another. */
function days_between($from, $to) {
    return (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%r%a');
}

/**
 * Builds the digest for the day $now falls on (in APP_TIMEZONE).
 * Returns ['subject' =>, 'html' =>, 'text' =>], or null when there's nothing to report.
 */
function digest_build(PDO $pdo, DateTimeImmutable $now) {
    $tz = new DateTimeZone(APP_TIMEZONE);
    $today = $now->setTimezone($tz)->setTime(0, 0);
    $yesterday = $today->modify('-1 day');
    $week_out = $today->modify('+7 days');
    $utc = fn(DateTimeImmutable $d) => $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

    // Yesterday's activity (times are stored in UTC, so convert the local day's edges)
    $stmt = $pdo->prepare("SELECT action, details, created_at FROM changelog
        WHERE created_at >= ? AND created_at < ? AND action != 'Viewed'
        ORDER BY created_at, id");
    $stmt->execute([$utc($yesterday), $utc($today)]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stays arriving today through a week from today
    $stmt = $pdo->prepare('SELECT renter_name, cottage_type, start_date, end_date FROM bookings
        WHERE start_date BETWEEN ? AND ? ORDER BY start_date, id');
    $stmt->execute([$today->format('Y-m-d'), $week_out->format('Y-m-d')]);
    $upcoming = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $week_ahead_arrivals = array_filter($upcoming, fn($b) => $b['start_date'] === $week_out->format('Y-m-d'));
    if (!$logs && !$week_ahead_arrivals) {
        return null;
    }

    $date_str = $yesterday->format('l, F jS');
    $subject = $logs
        ? "Cottage Booking Update - {$date_str}"
        : 'Cottage Heads-Up - Arrivals Next ' . $week_out->format('l');
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $text = [];

    $html = "
    <div style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05);'>
        <div style='background-color: #3788d8; padding: 24px; text-align: center;'>
            <h2 style='color: #ffffff; margin: 0; font-size: 22px; font-weight: 600; letter-spacing: 0.5px;'>Cottage Booking Update</h2>
        </div>
        <div style='padding: 32px 24px;'>";

    $heading = fn($title) => "
            <h3 style='color: #1f2937; margin-top: 0; border-bottom: 2px solid #f3f4f6; padding-bottom: 12px; font-size: 16px; text-transform: uppercase; letter-spacing: 0.5px;'>{$title}</h3>";

    if ($upcoming) {
        $html .= $heading('Arriving in the Next 7 Days') . "
            <ul style='list-style-type: none; padding: 0; margin: 0 0 32px 0;'>";
        $text[] = "ARRIVING IN THE NEXT 7 DAYS";
        foreach ($upcoming as $b) {
            $nights = days_between($b['start_date'], $b['end_date']) - 1;
            $nights_str = $nights === 0 ? 'day visit' : ($nights === 1 ? '1 night' : "{$nights} nights");
            $when = $b['start_date'] === $today->format('Y-m-d') ? 'Today' : date('D, M j', strtotime($b['start_date']));
            $leaving = date('M j', strtotime($b['end_date'] . ' -1 day'));
            $cottage = ucfirst($b['cottage_type']);
            $color = COTTAGE_COLORS[$b['cottage_type']] ?? '#6b7280';
            $html .= "
                <li style='padding: 12px 0; border-bottom: 1px solid #f3f4f6; font-size: 15px; line-height: 1.6;'>
                    <span style='color: #9ca3af; font-size: 13px; font-weight: 500; display: inline-block; width: 100px;'>{$when}</span>
                    <strong style='color: #374151;'>{$e($b['renter_name'])}</strong>
                    <span style='color: {$color}; font-size: 13px; font-weight: 600; margin-left: 6px;'>{$cottage}</span>
                    <span style='color: #9ca3af; font-size: 13px;'> · {$nights_str}, leaving {$leaving}</span>
                </li>";
            $text[] = "  {$when}: {$b['renter_name']} ({$cottage}) - {$nights_str}, leaving {$leaving}";
        }
        $html .= "
            </ul>";
        $text[] = '';
    }

    if ($logs) {
        $html .= $heading("Activity for {$date_str}") . "
            <ul style='list-style-type: none; padding: 0; margin: 0;'>";
        $text[] = strtoupper("Activity for {$date_str}");
        foreach ($logs as $log) {
            $time = $log['created_at'] ? local_time($log['created_at'])->format('g:i A') : '';
            $color = ACTION_COLORS[$log['action']] ?? '#6b7280';
            $html .= "
                <li style='padding: 16px 0; border-bottom: 1px solid #f3f4f6; font-size: 15px; line-height: 1.6;'>
                    <span style='color: #9ca3af; font-size: 13px; font-weight: 500; display: inline-block; width: 75px;'>{$time}</span>
                    <strong style='color: {$color}; background-color: {$color}15; padding: 2px 8px; border-radius: 4px; font-size: 13px; margin-right: 8px;'>{$e($log['action'])}</strong>
                    <span style='color: #374151;'>{$e($log['details'])}</span>
                </li>";
            $text[] = "  {$time}  {$log['action']}: {$log['details']}";
        }
        $html .= "
            </ul>";
        $text[] = '';
    }

    $url = $e(BASE_URL);
    $html .= "
        </div>
        <div style='background-color: #ffffff; padding: 0 24px 32px 24px; text-align: center;'>
            <a href='{$url}' style='display: inline-block; background-color: #3788d8; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 6px; font-size: 15px; font-weight: 600; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>
                Open Calendar
            </a>
        </div>
        <div style='background-color: #f9fafb; padding: 20px; text-align: center; color: #9ca3af; font-size: 13px; border-top: 1px solid #e5e7eb;'>
            This is an automated summary from the Cottage Booking System.
        </div>
    </div>";
    $text[] = 'Open the calendar: ' . BASE_URL;

    return ['subject' => $subject, 'html' => $html, 'text' => implode("\n", $text) . "\n"];
}

/** Why the digest can't be sent, or null if it's set up. */
function digest_problem() {
    if (!DIGEST_RECIPIENTS) return 'DIGEST_RECIPIENTS is empty in .env, so no digest is sent.';
    if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', DIGEST_TIME)) return 'DIGEST_TIME must look like 07:00.';
    return mail_problem();
}

/**
 * Called every minute by bin/scheduler.php. Sends today's digest once DIGEST_TIME has
 * passed, unless it was already handled today. A failed send is retried every 30 minutes
 * for the rest of the day. Returns a line for the log, or null if there was nothing to do.
 */
function digest_run_if_due(PDO $pdo, DateTimeImmutable $now) {
    $now = $now->setTimezone(new DateTimeZone(APP_TIMEZONE));
    $today = $now->format('Y-m-d');
    if (digest_problem() || !setup_done($pdo) || $now->format('H:i') < str_pad(DIGEST_TIME, 5, '0', STR_PAD_LEFT)) {
        return null;
    }
    if (setting_get($pdo, 'digest_last_date') === $today || $now->getTimestamp() < (int)setting_get($pdo, 'digest_retry_after')) {
        return null;
    }

    $digest = digest_build($pdo, $now);
    if ($digest === null) {
        setting_set($pdo, 'digest_last_date', $today);
        return 'Digest: nothing to report today.';
    }
    try {
        mail_send(DIGEST_RECIPIENTS, $digest['subject'], $digest['html'], $digest['text']);
    } catch (RuntimeException $e) {
        setting_set($pdo, 'digest_retry_after', (string)($now->getTimestamp() + 1800));
        return 'Digest NOT sent, retrying in 30 minutes. ' . $e->getMessage();
    }
    setting_set($pdo, 'digest_last_date', $today);
    setting_set($pdo, 'digest_retry_after', null);
    return "Digest sent to " . count(DIGEST_RECIPIENTS) . " recipient(s): {$digest['subject']}";
}
