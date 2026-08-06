<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/blog-storage.php';

require_auth();
require_post();
require_valid_csrf();

header('Content-Type: application/json; charset=UTF-8');

$payload = blog_payload_from_post();
if (!$payload['ok']) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'errors' => $payload['errors']], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $saved = blogs_upsert($payload['blog']);
} catch (Throwable $exception) {
    error_log('Blog storage error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Blog storage is unavailable.', 'csrfToken' => csrf_token()], JSON_UNESCAPED_SLASHES);
    exit;
}

csrf_rotate();
echo json_encode(['ok' => true, 'blog' => $saved, 'csrfToken' => csrf_token()], JSON_UNESCAPED_SLASHES);
