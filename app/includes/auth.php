<?php
/**
 * auth.php
 *
 * The calendar has no accounts: family PINs unlock it, a shared username/password
 * protects the iCal feed, and a separate PIN protects the web backup page. All of them
 * are set in .env. Wrong guesses are counted per IP address and lead to a lockout.
 */

/** True if the request came through one of TRUSTED_PROXIES. */
function from_trusted_proxy() {
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', TRUSTED_PROXIES, true);
}

/** The visitor's IP address (the real one when behind a trusted reverse proxy). */
function client_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (from_trusted_proxy() && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // The proxy appends the address it saw, so the last entry is the trustworthy one.
        $forwarded = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        $candidate = end($forwarded);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            $ip = $candidate;
        }
    }
    return $ip;
}

/** True if $given equals one of $valid, compared in constant time. */
function secret_matches($given, array $valid) {
    $given = (string)$given;
    $match = false;
    foreach ($valid as $secret) {
        $secret = (string)$secret;
        if ($given !== '' && $secret !== '' && hash_equals($secret, $given)) {
            $match = true;
        }
    }
    return $match;
}

/** True if this IP has made too many wrong guesses for $scope recently. */
function auth_locked_out(PDO $pdo, $scope) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM auth_failures WHERE ip_address = ? AND scope = ? AND attempted_at > ?');
    $stmt->execute([client_ip(), $scope, time() - AUTH_LOCKOUT_SECONDS]);
    return (int)$stmt->fetchColumn() >= AUTH_MAX_FAILURES;
}

/** Records a wrong guess, and forgets ones too old to matter. */
function auth_record_failure(PDO $pdo, $scope) {
    $pdo->prepare('INSERT INTO auth_failures (ip_address, scope, attempted_at) VALUES (?, ?, ?)')
        ->execute([client_ip(), $scope, time()]);
    $pdo->prepare('DELETE FROM auth_failures WHERE attempted_at < ?')->execute([time() - AUTH_LOCKOUT_SECONDS]);
}

function lockout_minutes() {
    return (int)ceil(AUTH_LOCKOUT_SECONDS / 60);
}

// --- First-run setup ---

/** True once setup.php (or bin/import.php) has finished. */
function setup_done(PDO $pdo) {
    return setting_get($pdo, 'setup_done') !== null;
}

function mark_setup_done(PDO $pdo) {
    setting_set($pdo, 'setup_done', gmdate('Y-m-d H:i:s'));
    setting_set($pdo, 'setup_code', null);
}

/** The one-time code for setup.php, created on first use. */
function setup_code(PDO $pdo) {
    $code = setting_get($pdo, 'setup_code');
    if ($code === null) {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $code = implode('-', str_split($code, 4));
        setting_set($pdo, 'setup_code', $code);
    }
    return $code;
}

function setup_code_matches(PDO $pdo, $entered) {
    $code = setting_get($pdo, 'setup_code');
    $normalize = fn($c) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$c));
    return $code !== null && hash_equals($normalize($code), $normalize($entered));
}
