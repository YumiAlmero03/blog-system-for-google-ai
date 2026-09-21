<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/blog-storage.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('X-Robots-Tag: noindex, nofollow');
    exit;
}

header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$raw = file_get_contents('php://input');
$json = json_decode(is_string($raw) ? $raw : '', true);
$source = is_array($json) ? $json : $_POST;

$postId = isset($source['postId']) && !is_array($source['postId']) ? normalize_slug((string) $source['postId']) : '';
$action = isset($source['action']) && !is_array($source['action']) ? strtolower(trim((string) $source['action'])) : '';

try {
    $result = blog_record_engagement($postId, $action);
    if (!$result['ok']) {
        http_response_code($result['error'] === 'Blog post not found.' ? 404 : 422);
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('Blog engagement error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Blog engagement is unavailable.'], JSON_UNESCAPED_SLASHES);
}
