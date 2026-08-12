<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/blog-storage.php';

require_auth();
require_post();
require_valid_csrf();

header('Content-Type: application/json; charset=UTF-8');

$count = request_positive_int('count', 10, 1, 100);
$page = request_positive_int('page', 1, 1, 1000000);
$category = request_blog_category_filter();
$search = request_string('search', 120) ?? '';

try {
    $result = blogs_page($count, $page, $category, null, $search);
    echo json_encode([
        'ok' => true,
        'blogs' => $result['items'],
        'pagination' => [
            'total' => $result['total'],
            'count' => $result['count'],
            'page' => $result['page'],
            'category' => $result['category'],
            'search' => $result['search'] ?? $search,
            'totalPages' => $result['totalPages'],
        ],
        'csrfToken' => csrf_token(),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('Blog storage error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Blog storage is unavailable.', 'csrfToken' => csrf_token()], JSON_UNESCAPED_SLASHES);
}
