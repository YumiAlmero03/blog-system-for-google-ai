<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/blog-storage.php';
require_once __DIR__ . '/includes/image-validation.php';

require_auth();
if (!class_exists('ZipArchive')) {
    http_response_code(503);
    exit('ZIP export is unavailable.');
}

$pdo = blogs_pdo();
$rows = $pdo->query('SELECT * FROM blog_posts ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
$blogs = array_map('blog_row_to_array', $rows);
$uploadRoot = realpath(__DIR__ . '/uploads/blogs');
$images = [];

foreach ($blogs as $index => $blog) {
    $references = [];
    $source = (string) ($blog['featuredImage'] ?? '');
    if ($source !== '') {
        $references[] = $source;
    }
    if (preg_match_all('#/uploads/blogs/[A-Za-z0-9._/-]+#', (string) ($blog['content'] ?? ''), $matches)) {
        $references = array_merge($references, $matches[0]);
    }

    foreach (array_unique($references) as $reference) {
        $relative = ltrim(parse_url($reference, PHP_URL_PATH) ?: '', '/');
        if (!str_starts_with($relative, 'uploads/blogs/') || !$uploadRoot) {
            continue;
        }
        $filename = basename($relative);
        $sourcePath = realpath(__DIR__ . '/../' . $relative);
        if (!$sourcePath || !is_file($sourcePath) || !str_starts_with($sourcePath, $uploadRoot . DIRECTORY_SEPARATOR)) {
            continue;
        }
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!array_filter(allowed_blog_image_mimes(), static fn(array $extensions): bool => in_array($extension, $extensions, true))) {
            continue;
        }
        $archiveName = 'images/' . $filename;
        $images[$archiveName] = ['source' => $sourcePath, 'path' => '/' . $relative];
    }
}

$temporaryZip = tempnam(sys_get_temp_dir(), 'blogs-export-');
if ($temporaryZip === false) {
    http_response_code(500);
    exit('Export could not be created.');
}

try {
    $zip = new ZipArchive();
    if ($zip->open($temporaryZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('ZIP could not be opened.');
    }
    $zipBlogs = [
        'format' => 'blogs-export-v1',
        'exportedAt' => gmdate('c'),
        'blogs' => $blogs,
        'images' => array_map(static fn (array $image): string => $image['path'], $images),
    ];
    $zip->addFromString('blogs.json', json_encode($zipBlogs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    foreach ($images as $archiveName => $image) {
        $zip->addFile($image['source'], $archiveName);
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="blogs-export-' . gmdate('Y-m-d') . '.zip"');
    header('Content-Length: ' . (string) filesize($temporaryZip));
    header('X-Content-Type-Options: nosniff');
    readfile($temporaryZip);
} catch (Throwable $exception) {
    http_response_code(500);
    error_log('Blog export error: ' . $exception->getMessage());
    echo 'Export could not be created.';
} finally {
    @unlink($temporaryZip);
}
