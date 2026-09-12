<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

function jwt_base64url_decode(string $value): string|false
{
    $remainder = strlen($value) % 4;
    if ($remainder > 0) {
        $value .= str_repeat('=', 4 - $remainder);
    }

    return base64_decode(strtr($value, '-_', '+/'), true);
}

function jwt_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function jwt_secret(): ?string
{
    $secret = env_value('BLOG_API_JWT_SECRET');
    return is_string($secret) && strlen($secret) >= 32 ? $secret : null;
}

function jwt_sign(array $claims, ?string $secret = null): string
{
    $secret ??= jwt_secret();
    if ($secret === null) {
        throw new RuntimeException('JWT secret is unavailable.');
    }

    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $encodedHeader = jwt_base64url_encode(json_encode($header, JSON_THROW_ON_ERROR));
    $encodedPayload = jwt_base64url_encode(json_encode($claims, JSON_THROW_ON_ERROR));
    $signature = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $secret, true);

    return $encodedHeader . '.' . $encodedPayload . '.' . jwt_base64url_encode($signature);
}

function jwt_authorization_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (is_string($header) && preg_match('/^Bearer\s+([A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+)$/', $header, $matches)) {
        return $matches[1];
    }

    if (isset($_POST['jwt']) && is_string($_POST['jwt'])) {
        return $_POST['jwt'];
    }

    return null;
}

function jwt_verify(?string $token, string $audience, string $scope = 'blog:read', ?string $secret = null): ?array
{
    $secret ??= jwt_secret();
    if ($secret === null || $token === null) {
        return null;
    }

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
    $headerJson = jwt_base64url_decode($encodedHeader);
    $payloadJson = jwt_base64url_decode($encodedPayload);
    $signature = jwt_base64url_decode($encodedSignature);
    if ($headerJson === false || $payloadJson === false || $signature === false) {
        return null;
    }

    $header = json_decode($headerJson, true);
    $claims = json_decode($payloadJson, true);
    if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'HS256') {
        return null;
    }

    $expected = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $secret, true);
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $now = time();
    if (!isset($claims['exp']) || !is_int($claims['exp']) || $claims['exp'] <= $now) {
        return null;
    }
    if (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now)) {
        return null;
    }
    if (($claims['aud'] ?? '') !== $audience) {
        return null;
    }
    if (($claims['scope'] ?? '') !== $scope) {
        return null;
    }

    return $claims;
}

function require_blog_read_jwt(): array
{
    $claims = jwt_verify(jwt_authorization_token(), 'blog-post-list');
    if ($claims !== null) {
        return $claims;
    }

    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing API token.'], JSON_UNESCAPED_SLASHES);
    exit;
}
