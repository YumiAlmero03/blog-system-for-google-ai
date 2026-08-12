<?php
declare(strict_types=1);

const AUTH_INACTIVITY_TIMEOUT = 1800;
const AUTH_ABSOLUTE_LIFETIME = 28800;
const AUTH_REGENERATION_INTERVAL = 900;
const LOGIN_FAILURE_MESSAGE = 'Invalid login credentials or login temporarily unavailable.';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

require_once __DIR__ . '/env.php';

function auth_is_https_request(): bool
{
    $https = $_SERVER['HTTPS'] ?? '';
    if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
        return true;
    }

    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if (is_string($forwardedProto) && strtolower($forwardedProto) === 'https') {
        return true;
    }

    $forwardedSsl = $_SERVER['HTTP_X_FORWARDED_SSL'] ?? '';
    if (is_string($forwardedSsl) && strtolower($forwardedSsl) === 'on') {
        return true;
    }

    $port = $_SERVER['SERVER_PORT'] ?? '';
    return (string) $port === '443';
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => auth_is_https_request(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

require_once __DIR__ . '/csrf.php';

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function request_string(string $key, int $maxLength): ?string
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) {
        return null;
    }

    $value = trim((string) $_POST[$key]);
    if ($value === '' || strlen($value) > $maxLength) {
        return null;
    }

    return $value;
}

function auth_login_url(): string
{
    return '/login.php';
}

function auth_credentials_available(): bool
{
    $username = env_value('ADMIN_USERNAME');
    $passwordHash = env_value('ADMIN_PASSWORD_HASH');

    return is_string($username)
        && is_string($passwordHash)
        && $username !== ''
        && $passwordHash !== '';
}

function auth_check_credentials(string $username, string $password): bool
{
    $expectedUsername = env_value('ADMIN_USERNAME');
    $expectedPasswordHash = env_value('ADMIN_PASSWORD_HASH');

    if (!is_string($expectedUsername) || !is_string($expectedPasswordHash) || $expectedUsername === '' || $expectedPasswordHash === '') {
        return false;
    }

    return hash_equals($expectedUsername, $username) && password_verify($password, $expectedPasswordHash);
}

function auth_is_authenticated(): bool
{
    if (empty($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        return false;
    }

    $now = time();
    $loginTime = isset($_SESSION['login_time']) && is_int($_SESSION['login_time']) ? $_SESSION['login_time'] : 0;
    $lastActivity = isset($_SESSION['last_activity']) && is_int($_SESSION['last_activity']) ? $_SESSION['last_activity'] : 0;

    if ($loginTime <= 0 || $lastActivity <= 0) {
        return false;
    }

    if (($now - $lastActivity) > AUTH_INACTIVITY_TIMEOUT || ($now - $loginTime) > AUTH_ABSOLUTE_LIFETIME) {
        auth_destroy_session();
        return false;
    }

    $_SESSION['last_activity'] = $now;

    $lastRegeneration = isset($_SESSION['last_regeneration']) && is_int($_SESSION['last_regeneration']) ? $_SESSION['last_regeneration'] : 0;
    if (($now - $lastRegeneration) > AUTH_REGENERATION_INTERVAL) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = $now;
    }

    return true;
}

function auth_mark_authenticated(): void
{
    session_regenerate_id(true);
    $now = time();
    $_SESSION['authenticated'] = true;
    $_SESSION['login_time'] = $now;
    $_SESSION['last_activity'] = $now;
    $_SESSION['last_regeneration'] = $now;
    csrf_rotate();
}

function auth_destroy_session(): void
{
    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => auth_is_https_request(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_destroy();
    }
}

function require_auth(): void
{
    if (auth_is_authenticated()) {
        return;
    }

    if (expects_json()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Access denied.']);
        exit;
    }

    header('Location: ' . auth_login_url(), true, 302);
    exit;
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        return;
    }

    http_response_code(405);
    header('Allow: POST');
    exit;
}

function require_valid_csrf(): void
{
    $token = request_string('csrf_token', 128);
    if (csrf_validate($token)) {
        return;
    }

    http_response_code(403);
    if (expects_json()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Invalid request token.']);
    }
    exit;
}

function expects_json(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    return str_contains($accept, 'application/json') || strtolower($requestedWith) === 'fetch';
}
