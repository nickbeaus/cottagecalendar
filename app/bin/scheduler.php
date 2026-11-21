<?php
/**
 * scheduler.php: runs in the background next to Apache (started by docker/entrypoint.sh)
 * and sends the daily digest at DIGEST_TIME. Checks once a minute; what it does goes to
 * the container log (docker compose logs web).
 */
require __DIR__ . '/_cli.php';
require_once __DIR__ . '/../includes/digest.php';

function log_line($message) {
    echo '[' . date('Y-m-d H:i:s T') . "] {$message}\n";
}

if ($problem = digest_problem()) {
    log_line("Digest scheduler: {$problem}");
}
while (true) {
    try {
        $pdo = db_connect();
        if ($message = digest_run_if_due($pdo, new DateTimeImmutable('now'))) {
            log_line($message);
        }
    } catch (Throwable $e) {
        log_line('Digest scheduler error: ' . $e->getMessage());
    }
    $pdo = null; // don't hold the database open between checks
    sleep(60 - (time() % 60) + 1);
}
