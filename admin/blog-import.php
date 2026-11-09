<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/blog-import-batches.php';

require_auth();
require_post();
require_valid_csrf();
header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = blogs_pdo();
    $owner = hash('sha256', session_id());
    if (isset($_POST['job_id'])) {
        $id = request_string('job_id', 48);
        $revision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT);
        if ($id === null || !preg_match('/^[a-f0-9]{48}$/', $id) || $revision === false || $revision < 0) throw new InvalidArgumentException('Invalid import batch.');
        $result = blog_import_batch($pdo, $id, $owner, $revision);
    } else {
        $file = $_FILES['import_file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Select a blog export ZIP or WordPress XML file.');
        if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > BLOG_IMPORT_MAX_BYTES) throw new InvalidArgumentException('Select an import file up to 100 MB.');
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['zip', 'xml'], true)) throw new InvalidArgumentException('Select a blog export ZIP or WordPress XML file.');
        $result = blog_import_start($pdo, $file['tmp_name'], $extension === 'xml', $owner);
    }
    // Keep this token stable so an interrupted batch can be retried safely.
    echo json_encode($result + ['csrfToken' => csrf_token()], JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code($error instanceof InvalidArgumentException ? 422 : 500);
    error_log('Blog import error: ' . $error->getMessage());
    echo json_encode(['ok' => false, 'error' => get_class($error) === RuntimeException::class || $error instanceof InvalidArgumentException ? $error->getMessage() : 'Blog import failed. Completed batches are saved; please retry.', 'csrfToken' => csrf_token()], JSON_UNESCAPED_SLASHES);
}
