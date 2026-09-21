<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/image-validation.php';

require_auth();
require_post();
require_valid_csrf();

header('Content-Type: application/json; charset=UTF-8');

const UPLOAD_MAX_BYTES = 3 * 1024 * 1024;
const UPLOAD_MIN_WIDTH = 120;
const UPLOAD_MIN_HEIGHT = 80;
const UPLOAD_MAX_WIDTH = 5000;
const UPLOAD_MAX_HEIGHT = 5000;
const UPLOAD_IMAGE_QUALITY = 60;
const UPLOAD_OUTPUT_MAX_WIDTH = 1200;
const UPLOAD_OUTPUT_MAX_HEIGHT = 675;

function is_gd_image(mixed $image): bool
{
    return is_resource($image) || (class_exists('GdImage') && $image instanceof GdImage);
}

function optimize_uploaded_image(string $source, string $destination, string $mime): bool
{
    if (!extension_loaded('gd')) {
        return move_uploaded_file($source, $destination);
    }

    $image = match ($mime) {
        'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($source) : false,
        'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($source) : false,
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
        'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($source) : false,
        default => false,
    };

    if (!is_gd_image($image)) {
        return move_uploaded_file($source, $destination);
    }

    $sourceWidth = imagesx($image);
    $sourceHeight = imagesy($image);
    $scale = min(1, UPLOAD_OUTPUT_MAX_WIDTH / max(1, $sourceWidth), UPLOAD_OUTPUT_MAX_HEIGHT / max(1, $sourceHeight));
    $wasResized = $scale < 1;

    if ($wasResized) {
        $targetWidth = max(1, (int) floor($sourceWidth * $scale));
        $targetHeight = max(1, (int) floor($sourceHeight * $scale));
        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        if (is_gd_image($resized)) {
            if ($mime === 'image/png' || $mime === 'image/webp') {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                imagefilledrectangle($resized, 0, 0, $targetWidth, $targetHeight, $transparent);
            }
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
            if (is_resource($image)) imagedestroy($image);
            $image = $resized;
        }
    }

    if ($mime === 'image/jpeg') {
        imageinterlace($image, true);
        $saved = imagejpeg($image, $destination, UPLOAD_IMAGE_QUALITY);
    } elseif ($mime === 'image/png') {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $saved = imagepng($image, $destination, 6);
    } elseif ($mime === 'image/webp' && function_exists('imagewebp')) {
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);
        $saved = imagewebp($image, $destination, UPLOAD_IMAGE_QUALITY);
    } elseif ($mime === 'image/avif' && function_exists('imageavif')) {
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);
        $saved = imageavif($image, $destination, UPLOAD_IMAGE_QUALITY);
    } else {
        if (is_resource($image)) imagedestroy($image);
        return move_uploaded_file($source, $destination);
    }

    if (is_resource($image)) imagedestroy($image);
    if ($saved && is_file($destination)) {
        $sourceSize = @filesize($source);
        $destinationSize = @filesize($destination);
        if (!$wasResized && is_int($sourceSize) && is_int($destinationSize) && $destinationSize > $sourceSize) {
            @unlink($destination);
            return move_uploaded_file($source, $destination);
        }
        return true;
    }

    if (is_file($destination)) {
        @unlink($destination);
    }

    return move_uploaded_file($source, $destination);
}

if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'No image uploaded.']);
    exit;
}

$file = $_FILES['image'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !isset($file['tmp_name'], $file['name'], $file['size'])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Image upload failed.']);
    exit;
}

if (!is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
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
    echo json_encode(['ok' => false, 'error' => 'Image file is too large.']);
    exit;
}

$originalName = is_string($file['name']) ? basename($file['name']) : '';
if (substr_count($originalName, '.') !== 1) {
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
    echo json_encode(['ok' => false, 'error' => 'Unsupported image type.']);
    exit;
}

if (!validate_blog_image_file($file['tmp_name'],pathinfo($originalName,PATHINFO_EXTENSION))['ok']) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'Image extension must match its content.']); exit;
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

$uploadDir = __DIR__ . '/uploads/blogs';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
$destination = $uploadDir . '/' . $filename;

if (!optimize_uploaded_image($file['tmp_name'], $destination, $mime)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Image could not be saved.']);
    exit;
}

chmod($destination, 0644);
csrf_rotate();

echo json_encode([
    'ok' => true,
    'path' => '/uploads/blogs/' . $filename,
    'csrfToken' => csrf_token(),
], JSON_UNESCAPED_SLASHES);
