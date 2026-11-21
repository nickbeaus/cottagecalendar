<?php
// health.php: used by Docker's HEALTHCHECK. Prints "ok" if PHP and the database answer.
require __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: text/plain');
header('Cache-Control: no-store');
echo $pdo->query('SELECT 1')->fetchColumn() == 1 ? 'ok' : 'error';
