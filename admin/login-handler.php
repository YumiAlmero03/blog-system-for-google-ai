<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rate-limit.php';

require_post();

$username = request_string('username', 80);
$password = isset($_POST['password']) && is_string($_POST['password']) && strlen($_POST['password']) <= 256 && $_POST['password'] !== '' ? $_POST['password'] : null;
$token = request_string('csrf_token', 128);
$ip = login_client_ip();

$failed = static function () use ($username, $ip): void {
    if (is_string($username)) {
        login_record_failure($username, $ip);
    }
    header('Location: /admin/login.php?error=1', true, 302);
    exit;
};

$locked = static function () use ($username, $ip): void {
    $retryAfter = is_string($username) ? login_retry_after_seconds($username, $ip) : LOGIN_RATE_LIMIT_WINDOW;
    $minutes = max(1, (int) ceil($retryAfter / 60));
    header('Location: /admin/login.php?locked=1&retry=' . $minutes, true, 302);
    exit;
};

if ($username === null || $password === null) {
    error_log('[Admin Login] Failed: empty username or password submitted.');
    $failed();
}

if (!csrf_validate($token)) {
    error_log(sprintf(
        '[Admin Login] Failed: CSRF mismatch or lost session (session_cookie=%s, https=%s, forwarded_proto=%s).',
        isset($_COOKIE[session_name()]) ? 'present' : 'missing',
        auth_is_https_request() ? 'true' : 'false',
        (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'none')
    ));
    $failed();
}

if (!auth_credentials_available()) {
    error_log('[Admin Login] Locked: ADMIN_JWT_SECRET / BLOG_API_JWT_SECRET is missing or shorter than 32 characters.');
    $locked();
}

if (login_rate_limited($username, $ip)) {
    error_log(sprintf(
        '[Admin Login] Locked: rate-limited or attempts file not writable (path=%s, writable=%s) for user=%s ip=%s.',
        login_attempts_path(),
        is_writable(dirname(login_attempts_path())) ? 'yes' : 'no',
        $username,
        $ip
    ));
    $locked();
}

try {
    $envUser = env_value('ADMIN_USERNAME');
    $envHash = env_value('ADMIN_PASSWORD_HASH');
    $user = auth_authenticate_credentials($username, $password);
    if ($user === null) {
        if ($envUser === null || $envHash === null) {
            error_log(sprintf(
                '[Admin Login] Failed for user=%s: .env credentials not loaded (ADMIN_USERNAME=%s, ADMIN_PASSWORD_HASH=%s, env_readable=%s).',
                $username,
                $envUser !== null ? 'set' : 'null',
                $envHash !== null ? 'set' : 'null',
                is_readable(__DIR__ . '/.env') ? 'yes' : 'no'
            ));
        } elseif (strcasecmp($username, $envUser) === 0) {
            $algoInfo = password_get_info($envHash);
            error_log(sprintf(
                '[Admin Login] Failed for user=%s: password_verify returned false (hash_algo=%s, argon2id_supported=%s).',
                $username,
                (string) ($algoInfo['algoName'] ?? 'unknown'),
                in_array('argon2id', password_algos(), true) ? 'yes' : 'no'
            ));
        } else {
            error_log(sprintf('[Admin Login] Failed for user=%s: username does not match ADMIN_USERNAME or any active DB user.', $username));
        }
        $failed();
    }
    auth_mark_authenticated($user);
} catch (Throwable $e) {
    error_log('[Admin Login] Exception: ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    $failed();
}
login_clear_failures($username, $ip);
header('Location: /admin/blogs.php', true, 302);
exit;
