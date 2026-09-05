<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/blog-storage.php';

const BLOG_POST_LIST_CACHE_TTL = 60;

function blog_post_list_cache_dir(): string
{
    return blog_storage_dir() . '/api-cache';
}

function blog_post_list_cache_key(int $count, int $page, ?string $category): string
{
    $dbMtime = is_file(blogs_db_path()) ? (int) @filemtime(blogs_db_path()) : 0;

    return hash('sha256', json_encode([
        'endpoint' => 'blog-post-list-v2',
        'count' => $count,
        'page' => $page,
        'category' => $category,
        'status' => 'published',
        'dbMtime' => $dbMtime,
    ], JSON_UNESCAPED_SLASHES));
}

function blog_post_list_cache_path(string $key): string
{
    return blog_post_list_cache_dir() . '/' . $key . '.json';
}

function blog_post_list_cache_read(string $key): ?string
{
    $path = blog_post_list_cache_path($key);
    if (!is_file($path) || (time() - (int) @filemtime($path)) > BLOG_POST_LIST_CACHE_TTL) {
        return null;
    }

    $cached = @file_get_contents($path);
    if (!is_string($cached) || $cached === '') {
        return null;
    }

    return $cached;
}

function blog_post_list_cache_write(string $key, string $payload): void
{
    $dir = blog_post_list_cache_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return;
    }

    $denyFile = $dir . '/.htaccess';
    if (!is_file($denyFile)) {
        @file_put_contents($denyFile, "Require all denied\n", LOCK_EX);
    }

    @file_put_contents(blog_post_list_cache_path($key), $payload, LOCK_EX);
}

function blog_post_list_param(string $key): ?string
{
    $source = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' ? $_GET : $_POST;
    if (!isset($source[$key]) || is_array($source[$key])) {
        return null;
    }

    return trim((string) $source[$key]);
}

function blog_post_list_positive_int(string $key, int $default, int $min, int $max): int
{
    $value = blog_post_list_param($key);
    if ($value === null || !ctype_digit($value)) {
        return $default;
    }

    return max($min, min($max, (int) $value));
}

function blog_post_list_item(array $post): array
{
    return [
        'id' => $post['id'] ?? '',
        'slug' => $post['slug'] ?? '',
        'title' => $post['title'] ?? '',
        'seoTitle' => $post['seoTitle'] ?? '',
        'category' => $post['category'] ?? '',
        'author' => $post['author'] ?? '',
        'excerpt' => $post['excerpt'] ?? '',
        'featuredImage' => $post['featuredImage'] ?? BLOG_DEFAULT_IMAGE,
        'status' => $post['status'] ?? 'published',
        'date' => $post['date'] ?? '',
        'publishedAt' => $post['publishedAt'] ?? null,
        'scheduledAt' => $post['scheduledAt'] ?? null,
        'isPublic' => (bool) ($post['isPublic'] ?? false),
        'createdAt' => $post['createdAt'] ?? null,
        'updatedAt' => $post['updatedAt'] ?? null,
        'views' => (int) ($post['views'] ?? 0),
        'likes' => (int) ($post['likes'] ?? 0),
        'dislikes' => (int) ($post['dislikes'] ?? 0),
        'readTime' => blog_read_minutes((string) ($post['content'] ?? '')),
    ];
}

if (!in_array(($_SERVER['REQUEST_METHOD'] ?? ''), ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    header('X-Robots-Tag: noindex, nofollow');
    exit;
}

header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=' . BLOG_POST_LIST_CACHE_TTL);

$count = blog_post_list_positive_int('count', 10, 1, 100);
$page = blog_post_list_positive_int('page', 1, 1, 1000000);
$category = normalize_blog_category_filter(blog_post_list_param('category'));

try {
    $cacheKey = blog_post_list_cache_key($count, $page, $category);
    $allowCache = !blogs_have_future_scheduled_posts();
    $cachedPayload = $allowCache ? blog_post_list_cache_read($cacheKey) : null;
    if ($cachedPayload !== null) {
        header('X-API-Cache: HIT');
        echo $cachedPayload;
        exit;
    }

    $result = blogs_page($count, $page, $category, 'published');
    $payload = json_encode([
        'ok' => true,
        'blogs' => array_map('blog_post_list_item', $result['items']),
        'pagination' => [
            'total' => $result['total'],
            'count' => $result['count'],
            'page' => $result['page'],
            'category' => $result['category'],
            'totalPages' => $result['totalPages'],
        ],
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        throw new RuntimeException('Blog response encoding failed.');
    }

    if ($allowCache) {
        blog_post_list_cache_write($cacheKey, $payload);
    }
    header('X-API-Cache: MISS');
    echo $payload;
} catch (Throwable $exception) {
    blog_storage_log_error('public blog list', $exception);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Blog storage is unavailable.'], JSON_UNESCAPED_SLASHES);
}
