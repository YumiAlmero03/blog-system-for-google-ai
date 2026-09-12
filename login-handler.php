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
    header('Location: /login.php?error=1', true, 302);
    exit;
};

$locked = static function () use ($username, $ip): void {
    $retryAfter = is_string($username) ? login_retry_after_seconds($username, $ip) : LOGIN_RATE_LIMIT_WINDOW;
    $minutes = max(1, (int) ceil($retryAfter / 60));
    header('Location: /login.php?locked=1&retry=' . $minutes, true, 302);
    exit;
};

if ($username === null || $password === null || !csrf_validate($token)) {
    $failed();
}

if (login_rate_limited($username, $ip) || !auth_credentials_available()) {
    $locked();
}

try {
    $user = auth_authenticate_credentials($username,$password);
    if ($user === null) $failed();
    auth_mark_authenticated($user);
} catch (Throwable $e) {
    error_log('Login temporarily unavailable.');
    $failed();
}
login_clear_failures($username, $ip);
header('Location: /admin/blogs.php', true, 302);
exit;
