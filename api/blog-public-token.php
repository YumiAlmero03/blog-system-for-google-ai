<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/jwt.php';

if (!in_array(($_SERVER['REQUEST_METHOD'] ?? ''), ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    header('X-Robots-Tag: noindex, nofollow');
    exit;
}

header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, max-age=240');

try {
    $now = time();
    echo json_encode([
        'ok' => true,
        'token' => jwt_sign([
            'aud' => 'blog-post-list',
            'scope' => 'blog:read',
            'sub' => 'public-blog-sample',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 300,
        ]),
        'expiresIn' => 300,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('Blog token error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'API token is unavailable.'], JSON_UNESCAPED_SLASHES);
}
