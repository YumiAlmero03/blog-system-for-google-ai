<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rate-limit.php';

require_post();

$username = request_string('username', 80);
$password = request_string('password', 256);
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

if (!auth_check_credentials($username, $password)) {
    $failed();
}

login_clear_failures($username, $ip);
auth_mark_authenticated();
header('Location: /admin/blogs.php', true, 302);
exit;
