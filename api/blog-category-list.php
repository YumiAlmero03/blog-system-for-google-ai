<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/blog-storage.php';

const BLOG_CATEGORY_LIST_CACHE_TTL = 60;

function blog_category_list_allow_local_dev_origin(): void
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if (preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin) === 1) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
}

function blog_category_list_cache_dir(): string
{
    return blog_storage_dir() . '/api-cache';
}

function blog_category_list_cache_key(): string
{
    $dbMtime = is_file(blogs_db_path()) ? (int) @filemtime(blogs_db_path()) : 0;

    return hash('sha256', json_encode([
        'endpoint' => 'blog-category-list-v1',
        'dbMtime' => $dbMtime,
    ], JSON_UNESCAPED_SLASHES));
}

function blog_category_list_cache_path(string $key): string
{
    return blog_category_list_cache_dir() . '/' . $key . '.json';
}

function blog_category_list_cache_read(string $key): ?string
{
    $path = blog_category_list_cache_path($key);
    if (!is_file($path) || (time() - (int) @filemtime($path)) > BLOG_CATEGORY_LIST_CACHE_TTL) {
        return null;
    }

    $cached = @file_get_contents($path);
    if (!is_string($cached) || $cached === '') {
        return null;
    }

    return $cached;
}

function blog_category_list_cache_write(string $key, string $payload): void
{
    $dir = blog_category_list_cache_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return;
    }

    $denyFile = $dir . '/.htaccess';
    if (!is_file($denyFile)) {
        @file_put_contents($denyFile, "Require all denied\n", LOCK_EX);
    }

    @file_put_contents(blog_category_list_cache_path($key), $payload, LOCK_EX);
}

function blog_category_list_item(array $category): array
{
    return [
        'id' => (string) ($category['id'] ?? ''),
        'name' => (string) ($category['name'] ?? ''),
        'description' => (string) ($category['description'] ?? ''),
        'postCount' => (int) ($category['postCount'] ?? 0),
    ];
}

if (!in_array(($_SERVER['REQUEST_METHOD'] ?? ''), ['GET', 'POST'], true)) {
    http_response_code(405);
    blog_category_list_allow_local_dev_origin();
    header('Allow: GET, POST');
    header('X-Robots-Tag: noindex, nofollow');
    exit;
}

blog_category_list_allow_local_dev_origin();
header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=' . BLOG_CATEGORY_LIST_CACHE_TTL);

try {
    $cacheKey = blog_category_list_cache_key();
    $cachedPayload = blog_category_list_cache_read($cacheKey);
    if ($cachedPayload !== null) {
        header('X-API-Cache: HIT');
        echo $cachedPayload;
        exit;
    }

    $categories = array_map('blog_category_list_item', blog_categories_page());
    $payload = json_encode([
        'ok' => true,
        'categories' => $categories,
        'total' => count($categories),
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        throw new RuntimeException('Blog category response encoding failed.');
    }

    blog_category_list_cache_write($cacheKey, $payload);
    header('X-API-Cache: MISS');
    echo $payload;
} catch (Throwable $exception) {
    error_log('Blog category API error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Blog categories are unavailable.'], JSON_UNESCAPED_SLASHES);
}
