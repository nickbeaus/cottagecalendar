<?php
/**
 * digest.php: the daily digest email, by hand. It's normally sent automatically at
 * DIGEST_TIME by bin/scheduler.php.
 *
 *   php bin/digest.php preview          print today's digest (subject and text); sends nothing
 *   php bin/digest.php send             send today's digest now, to DIGEST_RECIPIENTS
 *   php bin/digest.php test <address>   send a short test email, to check the SMTP settings
 *
 * In Docker:  docker compose exec web php bin/digest.php test you@example.org
 */
require __DIR__ . '/_cli.php';
require_once __DIR__ . '/../includes/digest.php';

$command = $argv[1] ?? '';
$pdo = cli_db();
$now = new DateTimeImmutable('now');

switch ($command) {
    case 'preview':
        $digest = digest_build($pdo, $now);
        if ($digest === null) {
            echo "Nothing to report today (no activity yesterday and nobody arrives a week from today), so no digest would be sent.\n";
        } else {
            echo "Subject: {$digest['subject']}\n\n{$digest['text']}";
        }
        echo "\nSettings: " . (digest_problem() ?? 'sent at ' . DIGEST_TIME . ' to ' . implode(', ', DIGEST_RECIPIENTS)) . "\n";
        break;

    case 'send':
        if ($problem = digest_problem()) {
            fail($problem);
        }
        $digest = digest_build($pdo, $now);
        if ($digest === null) {
            echo "Nothing to report today, so no digest was sent.\n";
            break;
        }
        try {
            mail_send(DIGEST_RECIPIENTS, $digest['subject'], $digest['html'], $digest['text']);
        } catch (RuntimeException $e) {
            fail($e->getMessage());
        }
        setting_set($pdo, 'digest_last_date', $now->format('Y-m-d')); // so the scheduler doesn't send it again
        echo "Sent \"{$digest['subject']}\" to " . implode(', ', DIGEST_RECIPIENTS) . "\n";
        break;

    case 'test':
        $to = $argv[2] ?? '';
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            fail('Usage: php bin/digest.php test <email address>');
        }
        try {
            mail_send([$to], 'Cottage Calendar test email',
                '<p>This is a test from the Cottage Calendar at <a href="' . htmlspecialchars(BASE_URL) . '">' . htmlspecialchars(BASE_URL) . '</a>. Email works.</p>',
                'This is a test from the Cottage Calendar at ' . BASE_URL . ". Email works.\n");
        } catch (RuntimeException $e) {
            fail($e->getMessage());
        }
        echo "Sent a test email to {$to} through " . SMTP_HOST . ':' . SMTP_PORT . ' (' . SMTP_SECURITY . ").\n";
        break;

    default:
        fwrite(STDERR, "Usage:\n  php bin/digest.php preview\n  php bin/digest.php send\n  php bin/digest.php test <email address>\n");
        exit(2);
}
