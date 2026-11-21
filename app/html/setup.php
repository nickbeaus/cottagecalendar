<?php
// setup.php: first-time setup. Only works until setup is finished, and needs the setup
// code printed in the container log (docker compose logs web). Optionally loads a backup
// from the old calendar; otherwise the calendar starts empty.

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/backup.php';
require __DIR__ . '/../includes/page.php';

header('Cache-Control: no-store');

if (setup_done($pdo) && !isset($_GET['done'])) {
    header('Location: ./');
    exit;
}

$errors = [];
$finished = isset($_GET['done']) && setup_done($pdo);

if (!$finished && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // The setup code is a password too, so wrong guesses lead to a lockout.
    if (auth_locked_out($pdo, 'setup')) {
        $errors[] = 'Too many wrong setup codes. Please wait ' . lockout_minutes() . ' minutes and try again.';
    } elseif (!setup_code_matches($pdo, $_POST['setup_code'] ?? '')) {
        auth_record_failure($pdo, 'setup');
        $errors[] = "That setup code isn't right. It's shown in the container log: docker compose logs web";
    }

    $backup_text = null;
    $upload = $_FILES['backup'] ?? null;
    if ($upload && $upload['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            $errors[] = in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'The backup file is too large (the limit is ' . ini_get('upload_max_filesize') . ').'
                : 'The backup file did not upload completely. Please try again.';
        } else {
            $backup_text = file_get_contents($upload['tmp_name']);
        }
    } elseif (empty($_POST['start_empty'])) {
        $errors[] = 'Choose a backup file to load, or tick "Start with an empty calendar".';
    }

    if (!$errors) {
        try {
            if ($backup_text !== null) {
                $backup = backup_parse($backup_text);
                $counts = backup_import($pdo, $backup); // also marks setup as done
                error_log('Cottage Calendar setup: loaded ' . backup_summary($backup));
            } else {
                mark_setup_done($pdo);
            }
            header('Location: setup.php?done=' . ($backup_text !== null ? $counts['bookings'] . '-' . $counts['changelog'] : '0'));
            exit;
        } catch (RuntimeException $e) {
            $errors[] = 'The backup was not loaded: ' . $e->getMessage() . ' Nothing was saved, so you can fix the file and try again.';
        } catch (Throwable $e) {
            error_log('Cottage Calendar setup: ' . $e->getMessage());
            $errors[] = 'Setup failed because of a database error; details are in the container log. Nothing was saved.';
        }
    }
}

page_start('First-Time Setup');
if ($finished):
    [$bookings, $activity] = array_map('intval', explode('-', $_GET['done'] . '-0'));
?>
        <h1>Setup complete</h1>
        <p class="ok" role="status"><?= $bookings || $activity
            ? h("Loaded {$bookings} bookings and {$activity} activity entries from the backup.")
            : 'The calendar is ready and empty.' ?></p>
        <?php if (!APP_PINS): ?>
            <p class="error">No family PINs are set yet, so nobody can unlock the calendar. Add <code>APP_PINS</code> to the .env file and run <code>docker compose up -d</code>.</p>
        <?php else: ?>
            <p>Family members unlock the calendar with one of the PINs from <code>APP_PINS</code> in the .env file.</p>
        <?php endif; ?>
        <a class="button" href="./">Open the Calendar</a>
<?php else: ?>
        <h1>First-time setup</h1>
        <p>Load the bookings from the old calendar, or start with an empty one. This page only works once.</p>
        <?php if ($errors): ?><p class="error" role="alert"><?= implode('<br>', array_map('h', $errors)) ?></p><?php endif; ?>
        <form method="post" enctype="multipart/form-data" autocomplete="off">
            <label for="setup_code">Setup code</label>
            <input type="text" id="setup_code" name="setup_code" placeholder="XXXX-XXXX-XXXX" autocapitalize="characters" required autofocus>
            <p class="hint">Printed in the container log: <code>docker compose logs web</code></p>

            <div class="box">
                <label for="backup">Backup from the old calendar</label>
                <input type="file" id="backup" name="backup" accept=".json,application/json">
                <p class="hint">The <code>micalendar-backup-….json</code> file from the old calendar's backup page (<code>migrate.php</code>). Bookings and activity keep their ids, so subscribed phone calendars don't show duplicates.</p>
                <label style="display: flex; gap: 8px; align-items: center; font-weight: 500;">
                    <input type="checkbox" name="start_empty" value="1"> Start with an empty calendar instead
                </label>
            </div>
            <button type="submit">Finish Setup</button>
        </form>
<?php endif;
page_end();
