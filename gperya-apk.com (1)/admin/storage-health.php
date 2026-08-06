<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/blog-storage.php';
require_once __DIR__ . '/../includes/api-rate-limit.php';

require_auth();

header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');

function storage_health_path_status(string $path): array
{
    return [
        'path' => $path,
        'exists' => file_exists($path),
        'isDir' => is_dir($path),
        'isFile' => is_file($path),
        'readable' => is_readable($path),
        'writable' => is_writable($path),
        'permissions' => file_exists($path) ? substr(sprintf('%o', (int) fileperms($path)), -4) : null,
        'owner' => file_exists($path) ? @fileowner($path) : null,
        'group' => file_exists($path) ? @filegroup($path) : null,
        'size' => is_file($path) ? @filesize($path) : null,
    ];
}

function storage_health_write_test(string $dir): array
{
    $testPath = rtrim($dir, '/\\') . '/.storage-health-test';
    $result = [
        'path' => $testPath,
        'ok' => false,
        'error' => null,
    ];

    try {
        if (!is_dir($dir)) {
            $result['error'] = 'Directory does not exist.';
            return $result;
        }

        if (@file_put_contents($testPath, (string) time(), LOCK_EX) === false) {
            $result['error'] = 'file_put_contents failed.';
            return $result;
        }

        $result['ok'] = true;
        @unlink($testPath);
    } catch (Throwable $exception) {
        $result['error'] = $exception->getMessage();
    }

    return $result;
}

$storageDir = blog_storage_dir();
$apiCacheDir = $storageDir . '/api-cache';

$blogRead = ['ok' => false, 'error' => null];
try {
    $page = blogs_page(1, 1);
    $blogRead = [
        'ok' => true,
        'total' => $page['total'],
        'firstSlug' => $page['items'][0]['slug'] ?? null,
    ];
} catch (Throwable $exception) {
    $blogRead['error'] = $exception->getMessage();
}

echo json_encode([
    'ok' => true,
    'phpUser' => function_exists('get_current_user') ? get_current_user() : null,
    'storage' => storage_health_path_status($storageDir),
    'sqlite' => storage_health_path_status(blogs_db_path()),
    'rateLimit' => storage_health_path_status(api_rate_limit_path()),
    'apiCache' => storage_health_path_status($apiCacheDir),
    'writeTests' => [
        'storage' => storage_health_write_test($storageDir),
        'apiCache' => storage_health_write_test($apiCacheDir),
    ],
    'blogRead' => $blogRead,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
