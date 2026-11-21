<?php
/**
 * bootstrap.php
 *
 * Shared setup for every web page: settings, the database connection ($pdo) and
 * PIN/lockout helpers. Security headers are set by Apache (docker/apache-site.conf).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

date_default_timezone_set(APP_TIMEZONE);

// Never show PHP errors to visitors; they go to the container log instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

try {
    $pdo = db_connect();
    // The schema is created/updated by bin/migrate.php when the container starts.
    if (db_schema_version($pdo) === 0) {
        throw new RuntimeException('The database has no tables yet (bin/migrate.php has not run).');
    }
} catch (Throwable $e) {
    error_log('Cottage Calendar DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('The calendar is temporarily unavailable. Please try again later.');
}

/** Escapes a value for HTML output. */
function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
