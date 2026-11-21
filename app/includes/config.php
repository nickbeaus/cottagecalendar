<?php
/**
 * config.php
 *
 * Site settings, read from environment variables (set them in the .env file next to
 * compose.yaml). Nothing secret is stored in this file.
 */

/** Reads an environment variable, falling back to $default when it's unset or empty. */
function env($name, $default = '') {
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : $value;
}

/** A comma-separated environment variable as a list, without blanks. */
function env_list($name) {
    return array_values(array_filter(array_map('trim', explode(',', env($name))), fn($v) => $v !== ''));
}

// --- Database (SQLite) ---
// The folder must be writable by the web server (it's the /data volume in Docker).
define('DB_PATH', env('DB_PATH', '/data/calendar.sqlite'));

// --- Site ---
// Public address of the site, used in emails and the first-run setup message. Must end with "/".
define('BASE_URL', rtrim(env('BASE_URL', 'http://localhost:8080/'), '/') . '/');
define('APP_TIMEZONE', env('APP_TIMEZONE', 'America/Chicago'));
// Requests from these addresses come through a reverse proxy, so the real client IP is
// taken from its X-Forwarded-For header. Leave empty if none.
define('TRUSTED_PROXIES', env_list('TRUSTED_PROXIES'));

// --- Access ---
define('APP_PINS', env_list('APP_PINS'));      // family PINs; any one unlocks the calendar
define('ICAL_USER', env('ICAL_USER'));          // phone calendar subscription (ical.php)
define('ICAL_PASS', env('ICAL_PASS'));          // empty = feed turned off
define('EXPORT_PIN', env('EXPORT_PIN'));        // web backup page; empty = turned off

// Wrong PINs/passwords from one IP address before it's locked out, and for how long.
// Counted separately for the calendar, the iCal feed, the backup page and setup.
const AUTH_MAX_FAILURES = 10;
const AUTH_LOCKOUT_SECONDS = 900; // 15 minutes

// --- Email ---
define('SMTP_HOST', env('SMTP_HOST'));
define('SMTP_PORT', (int)env('SMTP_PORT', '587'));
define('SMTP_SECURITY', strtolower(env('SMTP_SECURITY', SMTP_PORT === 465 ? 'ssl' : 'starttls')));
define('SMTP_USER', env('SMTP_USER'));
define('SMTP_PASS', env('SMTP_PASS'));
define('MAIL_FROM', env('MAIL_FROM', SMTP_USER));
define('MAIL_FROM_NAME', env('MAIL_FROM_NAME', 'Cottage Booking System'));

// --- Daily digest ---
define('DIGEST_RECIPIENTS', env_list('DIGEST_RECIPIENTS'));
define('DIGEST_TIME', env('DIGEST_TIME', '07:00')); // HH:MM in APP_TIMEZONE
