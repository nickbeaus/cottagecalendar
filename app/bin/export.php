<?php
/**
 * export.php: writes a backup of every booking and the full activity log, in the same
 * format as the web backup page. Use "-" to write it to the screen (stdout).
 *
 *   php bin/export.php <file.json>
 *
 * In Docker (-T keeps the file byte-for-byte; without it the terminal changes line endings):
 *   docker compose exec -T web php bin/export.php - > micalendar-backup-$(date +%F).json
 */
require __DIR__ . '/_cli.php';

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php bin/export.php <file.json | ->\n");
    exit(2);
}
$backup = backup_build(cli_db());
$json = backup_encode($backup);
if ($argv[1] === '-') {
    echo $json;
    fwrite(STDERR, 'Exported ' . backup_summary($backup) . ".\n");
    exit(0);
}

// Write to a temporary file first, so a failed export never leaves half a backup behind.
$path = $argv[1];
$tmp = $path . '.tmp';
$old = umask(0077); // readable by the owner only: it has names and IP addresses
$ok = file_put_contents($tmp, $json) !== false && rename($tmp, $path);
umask($old);
if (!$ok) {
    @unlink($tmp);
    fail("Could not write {$path}");
}
echo 'Exported ' . backup_summary($backup) . " to {$path}\n";
