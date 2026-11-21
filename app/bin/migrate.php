<?php
/**
 * migrate.php: creates the database if needed and applies new schema migrations.
 * Runs automatically every time the container starts (docker/entrypoint.sh).
 * Until first-time setup is done, prints the one-time code for setup.php.
 *
 *   php bin/migrate.php
 */
require __DIR__ . '/_cli.php';

$dir = dirname(DB_PATH);
if (!is_dir($dir) && !mkdir($dir, 0770, true)) {
    fail("Can't create the data folder {$dir}.");
}
$is_new = !file_exists(DB_PATH);
try {
    $pdo = db_connect();
    $applied = db_migrate($pdo);
} catch (Throwable $e) {
    fail($e->getMessage());
}
if ($is_new) {
    echo 'Created a new database at ' . DB_PATH . "\n";
}
foreach ($applied as $file) {
    echo "Applied migration {$file}\n";
}
echo 'Database schema is at version ' . db_schema_version($pdo) . "\n";

if (!setup_done($pdo)) {
    $code = setup_code($pdo);
    $url = BASE_URL . 'setup.php';
    echo <<<TXT

    ==============================================================
      FIRST-TIME SETUP
      Open {$url}
      and enter this setup code:   {$code}
      There you can load a backup from the old calendar, or start empty.
      (Show it again: docker compose exec web php bin/migrate.php)
    ==============================================================

    TXT;
}
if (!APP_PINS) {
    echo "WARNING: APP_PINS is empty in .env, so nobody can unlock the calendar.\n";
}
require_once __DIR__ . '/../includes/digest.php';
echo 'Daily digest: ' . (digest_problem() ?? 'sent at ' . DIGEST_TIME . ' to ' . implode(', ', DIGEST_RECIPIENTS) . '.') . "\n";
