<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/blog-storage.php';

require_auth();
require_post();
require_valid_csrf();

header('Content-Type: application/json; charset=UTF-8');

$count = request_positive_int('count', 10, 1, 100);
$page = request_positive_int('page', 1, 1, 1000000);
$category = request_blog_category_filter();
$rawStatus = request_string('status', 20) ?? '';
$status = in_array($rawStatus, ['published', 'scheduled', 'draft'], true) ? $rawStatus : null;
$search = request_string('search', 120) ?? '';
$orderBy = request_string('order_by', 32) ?? 'publish_date';

try {
    $result = blogs_page($count, $page, $category, $status, $search, $orderBy);
    echo json_encode([
        'ok' => true,
        'blogs' => $result['items'],
        'pagination' => [
            'total' => $result['total'],
            'count' => $result['count'],
            'page' => $result['page'],
            'category' => $result['category'],
            'status' => $result['status'] ?? $status,
            'search' => $result['search'] ?? $search,
            'orderBy' => $result['orderBy'] ?? $orderBy,
            'totalPages' => $result['totalPages'],
        ],
        'csrfToken' => csrf_token(),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    blog_storage_log_error('admin blog list', $exception);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Blog storage is unavailable.', 'csrfToken' => csrf_token()], JSON_UNESCAPED_SLASHES);
}
