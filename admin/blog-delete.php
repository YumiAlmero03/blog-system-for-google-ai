<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/blog-storage.php';

require_auth();
require_post();
require_valid_csrf();

header('Content-Type: application/json; charset=UTF-8');

$id = request_string('id', 110);
if ($id === null) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid blog id.']);
    exit;
}

$id = normalize_slug($id);
try {
    $deleted = blogs_delete($id);
} catch (Throwable $exception) {
    error_log('Blog storage error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Blog storage is unavailable.', 'csrfToken' => csrf_token()], JSON_UNESCAPED_SLASHES);
    exit;
}

csrf_rotate();
echo json_encode(['ok' => true, 'deleted' => $deleted, 'csrfToken' => csrf_token()], JSON_UNESCAPED_SLASHES);
