<?php
// backup.php: download a backup of the whole calendar (every booking plus the full
// activity log). Needs EXPORT_PIN from .env, which is separate from the family PINs;
// with no EXPORT_PIN, web backups are turned off. Loading a backup is never possible
// from here, so a leaked PIN can download the data but never overwrite it.

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/backup.php';
require __DIR__ . '/../includes/page.php';

header('Cache-Control: no-store');

$error = '';
$enabled = EXPORT_PIN !== '';
if (!$enabled) {
    http_response_code(403);
    $error = 'Web backups are turned off. Set EXPORT_PIN in the .env file on the server to turn them on.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (auth_locked_out($pdo, 'export')) {
        http_response_code(429);
        $error = 'Too many wrong PINs. Please wait ' . lockout_minutes() . ' minutes and try again.';
    } elseif (!secret_matches($_POST['pin'] ?? '', [EXPORT_PIN])) {
        auth_record_failure($pdo, 'export');
        http_response_code(401);
        $error = 'Incorrect PIN.';
    } else {
        try {
            $json = backup_encode(backup_build($pdo));
            // Shows up in Recent Activity, so everyone can see when the data leaves
            $pdo->prepare("INSERT INTO changelog (action, details, ip_address) VALUES ('Exported', 'Someone downloaded a full backup of the calendar.', ?)")
                ->execute([client_ip()]);

            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="micalendar-backup-' . date('Y-m-d') . '.json"');
            header('Content-Length: ' . strlen($json));
            echo $json;
            exit;
        } catch (Throwable $e) {
            error_log('Cottage Calendar backup.php: ' . $e->getMessage());
            http_response_code(500);
            $error = 'Server error. Please try again.';
        }
    }
}

$disabled = $enabled ? '' : ' disabled';
page_start('Back Up Calendar');
?>
        <h1>Back Up Calendar</h1>
        <p>Downloads every booking, past and future, plus the full activity log, as one file you can load into a new copy of the calendar.</p>
        <?php if ($error !== ''): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif; ?>
        <form method="post" autocomplete="off">
            <label for="pin">Backup PIN</label>
            <input type="password" id="pin" name="pin" required autofocus<?= $disabled ?>>
            <button type="submit"<?= $disabled ?>>Download Backup</button>
        </form>
        <p class="note">The file includes names and the IP addresses from the activity log, so keep it private. Each download shows up in Recent Activity. A new copy of the calendar can load it on its first-time setup page. <a href="./">Back to calendar</a></p>
<?php page_end();
