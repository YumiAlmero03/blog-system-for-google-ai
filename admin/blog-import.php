<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/blog-storage.php';
require_once __DIR__ . '/includes/image-validation.php';

require_auth();
require_post();
require_valid_csrf();
header('Content-Type: application/json; charset=UTF-8');

const BLOG_IMPORT_MAX_BYTES = 100 * 1024 * 1024;
const BLOG_IMPORT_MAX_IMAGE_BYTES = 3 * 1024 * 1024;

function blog_import_error(string $message, int $status = 422): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message, 'csrfToken' => csrf_token()], JSON_UNESCAPED_SLASHES);
    exit;
}

function blog_import_log_image_failure(string $name, string $reason): void
{
    error_log('Blog import image validation failed: file="' . $name . '" reason="' . $reason . '"');
}

function blog_import_image_error(string $name, string $reason): never
{
    blog_import_log_image_failure($name, $reason);
    blog_import_error('An image failed validation: ' . basename($name) . ' (' . $reason . ').');
}

if (!class_exists('ZipArchive')) {
    blog_import_error('ZIP import is unavailable.', 503);
}
if (!isset($_FILES['import_file']) || !is_array($_FILES['import_file'])) {
    blog_import_error('Select a blog export ZIP file.');
}
$file = $_FILES['import_file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
    blog_import_error('The ZIP upload could not be read.');
}
if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > BLOG_IMPORT_MAX_BYTES) {
    blog_import_error('The ZIP file is too large.');
}

$zip = new ZipArchive();
if ($zip->open($file['tmp_name']) !== true) {
    blog_import_error('Invalid blog export ZIP.');
}
$entryNames = [];
for ($index = 0; $index < $zip->numFiles; $index++) {
    $stat = $zip->statIndex($index);
    $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
    if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('#(^|/)\.\.?(/|$)#', $name)) {
        $zip->close();
        blog_import_error('Unsafe ZIP path detected.');
    }
    if (str_ends_with($name, '/')) {
        continue;
    }
    $entryNames[$name] = $index;
}
if (!isset($entryNames['blogs.json']) || array_filter(array_keys($entryNames), static fn (string $name): bool => $name !== 'blogs.json' && !str_starts_with($name, 'images/')) !== []) {
    $zip->close();
    blog_import_error('ZIP structure is not a supported blog export.');
}

$json = $zip->getFromName('blogs.json');
$manifest = is_string($json) ? json_decode($json, true) : null;
if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'blogs-export-v1' || !is_array($manifest['blogs'] ?? null)) {
    $zip->close();
    blog_import_error('blogs.json is invalid or unsupported.');
}

$uploadDir = __DIR__ . '/uploads/blogs';
if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    $zip->close();
    blog_import_error('Upload storage is unavailable.', 500);
}
$imageMap = [];
$imagesRestored = 0;
foreach (array_keys($entryNames) as $name) {
    if (!str_starts_with($name, 'images/')) {
        continue;
    }
    $entryStat = $zip->statIndex($entryNames[$name]);
    if (!is_array($entryStat) || (int) ($entryStat['size'] ?? 0) <= 0 || (int) $entryStat['size'] > BLOG_IMPORT_MAX_IMAGE_BYTES) {
        $zip->close();
        blog_import_error('An image entry is too large or invalid.');
    }
    $basename = basename($name);
    if ($basename === '' || $basename !== preg_replace('/[^A-Za-z0-9._ -]/', '_', $basename) || substr_count($basename, '.') !== 1 || !preg_match('/\.(jpe?g|png|webp|avif)$/i', $basename)) {
        blog_import_log_image_failure($name, 'unsupported filename or extension');
        $zip->close();
        blog_import_error('Unsupported image entry in ZIP.');
    }
    $data = $zip->getFromIndex($entryNames[$name]);
    if (!is_string($data) || strlen($data) === 0 || strlen($data) > BLOG_IMPORT_MAX_IMAGE_BYTES) {
        $zip->close();
        blog_import_error('An image entry is too large or invalid.');
    }
    $temporaryImage = tempnam(sys_get_temp_dir(), 'blog-import-image-');
    if ($temporaryImage === false || file_put_contents($temporaryImage, $data) === false) {
        $zip->close();
        blog_import_error('An image could not be prepared.', 500);
    }
    $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
    $validation = validate_blog_image_file($temporaryImage, $extension);
    if (($validation['ok'] ?? false) !== true) {
        $reason = (string) ($validation['reason'] ?? 'unknown validation failure');
        @unlink($temporaryImage);
        $zip->close();
        blog_import_image_error($name, $reason);
    }
    $destinationName = bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = $uploadDir . '/' . $destinationName;
    if (!@rename($temporaryImage, $destination)) {
        @unlink($temporaryImage);
        $zip->close();
        blog_import_error('An image could not be restored.', 500);
    }
    @chmod($destination, 0644);
    $imageMap['/' . ltrim((string) (($manifest['images'][$name] ?? '') ?: '/uploads/blogs/' . $basename), '/')] = '/uploads/blogs/' . $destinationName;
    $imageMap['/uploads/blogs/' . $basename] = '/uploads/blogs/' . $destinationName;
    $imagesRestored++;
}
$zip->close();

$pdo = blogs_pdo();
$counts = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
try {
    $pdo->beginTransaction();
    foreach ($manifest['blogs'] as $record) {
        if (!is_array($record)) {
            $counts['failed']++;
            continue;
        }
        $normalized = blog_normalize_existing($record);
        $slug = is_string($record['slug'] ?? null) ? normalize_slug($record['slug']) : '';
        if ($normalized === null || $slug === '') {
            $counts['failed']++;
            continue;
        }
        $originalContent = $record['content'] ?? null;
        if (!is_string($originalContent) || strlen($originalContent) > 60000) {
            $counts['failed']++;
            continue;
        }
        $normalized['content'] = preg_replace_callback('#/uploads/blogs/[A-Za-z0-9._/-]+#', static fn (array $match): string => $imageMap[$match[0]] ?? $match[0], $originalContent) ?? $originalContent;
        $normalized['featuredImage'] = $imageMap[$normalized['featuredImage']] ?? $normalized['featuredImage'];
        $existingStmt = $pdo->prepare('SELECT id FROM blog_posts WHERE slug = :slug LIMIT 1');
        $existingStmt->execute([':slug' => $slug]);
        $existingId = $existingStmt->fetchColumn();
        if ($existingId !== false && (string) $existingId !== (string) ($record['id'] ?? $slug)) {
            $counts['skipped']++;
            continue;
        }
        $normalized['id'] = $existingId !== false ? (string) $existingId : $slug;
        blog_taxonomy_import_category($pdo, $normalized['category']);
        blogs_upsert_with_pdo($pdo, $normalized);
        $existingId !== false ? $counts['updated']++ : $counts['imported']++;
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Blog import error: ' . $exception->getMessage());
    blog_import_error('Blog import failed.', 500);
}

csrf_rotate();
echo json_encode(['ok' => true, 'counts' => $counts + ['imagesRestored' => $imagesRestored], 'csrfToken' => csrf_token()], JSON_UNESCAPED_SLASHES);
