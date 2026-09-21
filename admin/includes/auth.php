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
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/admin-users.php';
require_once __DIR__ . '/admin-permissions.php';

const AUTH_ACCESS_TTL = 900;
const AUTH_ACCESS_COOKIE = 'admin_access';

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
    return '/admin/login.php';
}

function auth_jwt_secret(): ?string
{
    $secret = env_value('ADMIN_JWT_SECRET') ?? env_value('BLOG_API_JWT_SECRET');
    return is_string($secret) && strlen($secret) >= 32 ? $secret : null;
}

function auth_credentials_available(): bool
{
    return auth_jwt_secret() !== null;
}

function auth_authenticate_credentials(string $username, string $password): ?array
{
    $adminName = env_value('ADMIN_USERNAME');
    $adminHash = env_value('ADMIN_PASSWORD_HASH');
    if ($adminName !== null && strcasecmp($username,$adminName) === 0 && $adminHash !== null) {
        if (!password_verify($password,$adminHash)) return null;
        $owner = admin_environment_user();
        return ['id'=>'admin:env','account_id'=>(int)$owner['id'],'role'=>'super_user','display_name'=>$owner['display_name'],'auth_version'=>1,
            'credential_fingerprint'=>hash('sha256',$adminHash)];
    }
    $stmt = admin_users_pdo()->prepare('SELECT * FROM admin_users WHERE username=? COLLATE NOCASE LIMIT 1');
    $stmt->execute([trim($username)]);
    $row = $stmt->fetch();
    // Perform password work for unknown usernames as well; this is a dummy hash, not a credential.
    $valid = password_verify($password,$row['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
    if (!$row || !$valid || (int)$row['active'] !== 1) return null;
    return ['id'=>'editor:' . $row['id'],'account_id'=>(int)$row['id'],'role'=>$row['role'],'display_name'=>$row['display_name'],'auth_version'=>(int)$row['auth_version']];
}

function auth_check_credentials(string $username, string $password): bool
{
    return auth_authenticate_credentials($username,$password) !== null;
}

function auth_issue_access_token(): void
{
    $user = $_SESSION['user'];
    $now = time();
    $expires = min($now + AUTH_ACCESS_TTL,$_SESSION['login_time'] + AUTH_ABSOLUTE_LIFETIME);
    $token = jwt_sign(['aud'=>'admin','scope'=>'admin:access','sub'=>$user['id'],'user_id'=>$user['id'],
        'role'=>$user['role'],'ver'=>$user['auth_version'],'sid'=>$_SESSION['auth_binding'],
        'iat'=>$now,'nbf'=>$now,'exp'=>$expires],auth_jwt_secret());
    setcookie(AUTH_ACCESS_COOKIE,$token,['expires'=>$expires,'path'=>'/','secure'=>auth_is_https_request(),'httponly'=>true,'samesite'=>'Strict']);
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

    $secret = auth_jwt_secret();
    $token = $_COOKIE[AUTH_ACCESS_COOKIE] ?? null;
    if ($secret === null || !is_string($token)) return false;
    $claims = jwt_verify($token,'admin','admin:access',$secret);
    $user = $_SESSION['user'] ?? null;
    if (!$claims || !is_array($user) || !is_int($claims['iat'] ?? null) || $claims['iat'] > $now
        || $claims['exp'] - $claims['iat'] > AUTH_ACCESS_TTL
        || ($claims['sub'] ?? null) !== $user['id'] || ($claims['user_id'] ?? null) !== $user['id']
        || ($claims['role'] ?? null) !== $user['role'] || ($claims['ver'] ?? null) !== $user['auth_version']
        || !is_string($claims['sid'] ?? null) || !hash_equals($_SESSION['auth_binding'] ?? '',$claims['sid'])) return false;
    try {
        if (str_starts_with($user['id'],'editor:')) {
            $current = admin_user_find((int)substr($user['id'],7));
            if (!$current || $current['role'] !== $user['role'] || (int)$current['active'] !== 1 || (int)$current['auth_version'] !== $user['auth_version']) return false;
        } elseif (in_array($user['role'],['admin','super_user'],true) && $user['id'] === 'admin:env') {
            if (!hash_equals($user['credential_fingerprint'] ?? '',hash('sha256',env_value('ADMIN_PASSWORD_HASH') ?? ''))) return false;
        } else return false;
    } catch (Throwable $e) { error_log('Authentication account lookup failed.'); return false; }
    // Renew only a still-valid token within the existing session lifetime; no refresh token.
    if ($claims['exp'] - $now < 300) auth_issue_access_token();

    $_SESSION['last_activity'] = $now;

    $lastRegeneration = isset($_SESSION['last_regeneration']) && is_int($_SESSION['last_regeneration']) ? $_SESSION['last_regeneration'] : 0;
    if (($now - $lastRegeneration) > AUTH_REGENERATION_INTERVAL) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = $now;
    }

    return true;
}

function auth_mark_authenticated(array $user): void
{
    session_regenerate_id(true);
    $now = time();
    $_SESSION['user'] = $user;
    $_SESSION['auth_binding'] = bin2hex(random_bytes(32));
    $_SESSION['authenticated'] = true;
    $_SESSION['login_time'] = $now;
    $_SESSION['last_activity'] = $now;
    $_SESSION['last_regeneration'] = $now;
    csrf_rotate();
    auth_issue_access_token();
    if (str_starts_with($user['id'],'editor:')) {
        $stmt = admin_users_pdo()->prepare('UPDATE admin_users SET last_login_at=? WHERE id=?');
        $stmt->execute([$now,(int)substr($user['id'],7)]);
    }
}

function auth_destroy_session(): void
{
    setcookie(AUTH_ACCESS_COOKIE,'',['expires'=>time()-3600,'path'=>'/','secure'=>auth_is_https_request(),'httponly'=>true,'samesite'=>'Strict']);
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

function require_auth(?string $capability = null): void
{
    if (auth_is_authenticated()) {
        if (!auth_can($capability ?? auth_route_capability())) auth_deny();
        header('Cache-Control: no-store');
        auth_rate_limit();
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
