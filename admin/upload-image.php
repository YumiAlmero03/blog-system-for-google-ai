<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/image-validation.php';

require_auth();
require_post();
require_valid_csrf();

header('Content-Type: application/json; charset=UTF-8');

const UPLOAD_MAX_BYTES = 10 * 1024 * 1024;
const UPLOAD_MIN_WIDTH = 16;
const UPLOAD_MIN_HEIGHT = 16;
const UPLOAD_MAX_WIDTH = 8000;
const UPLOAD_MAX_HEIGHT = 8000;
const UPLOAD_IMAGE_QUALITY = 60;
const UPLOAD_OUTPUT_MAX_WIDTH = 1200;
const UPLOAD_OUTPUT_MAX_HEIGHT = 675;

$file = $_FILES['image'] ?? $_FILES['file'] ?? null;
if (!is_array($file)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'No image uploaded.']);
    exit;
}

$uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
if ($uploadError !== UPLOAD_ERR_OK) {
    $errorMessage = match ($uploadError) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Image file exceeds maximum upload size.',
        UPLOAD_ERR_PARTIAL => 'Image file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No image file was received.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temporary folder is missing.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write image file to disk.',
        default => 'Image upload failed.',
    };
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $errorMessage]);
    exit;
}

if (!isset($file['tmp_name'], $file['name'], $file['size']) || !is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid uploaded file.']);
    exit;
}

if (!is_int($file['size']) && !ctype_digit((string) $file['size'])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid uploaded file.']);
    exit;
}

$size = (int) $file['size'];
if ($size <= 0 || $size > UPLOAD_MAX_BYTES) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Image file is too large (max 10MB).']);
    exit;
}

$originalName = is_string($file['name']) ? basename($file['name']) : '';
if ($originalName === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid image filename.']);
    exit;
}

$extensions = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/avif' => 'avif',
];
$mime = detect_blog_image_mime($file['tmp_name']);

if (!is_string($mime) || !isset($extensions[$mime])) {
    http_response_code(415);
    echo json_encode(['ok' => false, 'error' => 'Unsupported image type. Please upload a JPEG, PNG, WebP, or AVIF image.']);
    exit;
}

$originalExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if ($originalExt === '') {
    $originalExt = $extensions[$mime];
}

if (!preg_match('/^[a-z0-9]{2,5}$/', $originalExt) || !validate_blog_image_file($file['tmp_name'], $originalExt)['ok']) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Image extension must match its content.']);
    exit;
}

$dimensions = @getimagesize($file['tmp_name']);
if ($dimensions === false || !isset($dimensions[0], $dimensions[1])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid image file.']);
    exit;
}

[$width, $height] = $dimensions;
if ($width < UPLOAD_MIN_WIDTH || $height < UPLOAD_MIN_HEIGHT || $width > UPLOAD_MAX_WIDTH || $height > UPLOAD_MAX_HEIGHT) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Image dimensions are not allowed.']);
    exit;
}

$webUploadDir = dirname(__DIR__) . '/uploads/blogs';
$adminUploadDir = __DIR__ . '/uploads/blogs';

if (!is_dir($webUploadDir)) {
    @mkdir($webUploadDir, 0755, true);
}
if (!is_dir($adminUploadDir)) {
    @mkdir($adminUploadDir, 0755, true);
}

$filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
$webDestination = $webUploadDir . '/' . $filename;
$adminDestination = $adminUploadDir . '/' . $filename;

// Save and optimize to web directory first, or fallback to admin directory
$saved = optimize_uploaded_image($file['tmp_name'], $webDestination, $mime);
if (!$saved) {
    $saved = optimize_uploaded_image($file['tmp_name'], $adminDestination, $mime);
    if (!$saved) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Image could not be saved to disk.']);
        exit;
    }
    @chmod($adminDestination, 0644);
    @copy($adminDestination, $webDestination);
    if (is_file($webDestination)) {
        @chmod($webDestination, 0644);
    }
} else {
    @chmod($webDestination, 0644);
    @copy($webDestination, $adminDestination);
    if (is_file($adminDestination)) {
        @chmod($adminDestination, 0644);
    }
}

csrf_rotate();

echo json_encode([
    'ok' => true,
    'path' => '/uploads/blogs/' . $filename,
    'csrfToken' => csrf_token(),
], JSON_UNESCAPED_SLASHES);
