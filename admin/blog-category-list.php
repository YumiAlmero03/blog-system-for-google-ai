<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/blog-storage.php';

require_auth();
require_post();
require_valid_csrf();

header('Content-Type: application/json; charset=UTF-8');

try {
    echo json_encode([
        'ok' => true,
        'categories' => blog_categories_page(),
        'csrfToken' => csrf_token(),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('Blog category list error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Blog categories are unavailable.', 'csrfToken' => csrf_token()], JSON_UNESCAPED_SLASHES);
}
