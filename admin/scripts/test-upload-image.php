<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/image-validation.php';
require_once __DIR__ . '/../includes/writers.php';

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("Assertion failed: $message");
    }
}

// Ensure both upload directories exist
$webUploadDir = __DIR__ . '/../../uploads/blogs';
$adminUploadDir = dirname(__DIR__) . '/uploads/blogs';
if (!is_dir($webUploadDir)) @mkdir($webUploadDir, 0755, true);
if (!is_dir($adminUploadDir)) @mkdir($adminUploadDir, 0755, true);

$createdFiles = [];

try {
    // 1. Test image validation with multi-dot filenames (e.g. macOS screenshots)
    $pngData = imagecreatetruecolor(200, 150);
    $tmpPng = tempnam(sys_get_temp_dir(), 'test_upload_') . '.png';
    imagepng($pngData, $tmpPng);
    $createdFiles[] = $tmpPng;

    $mime = detect_blog_image_mime($tmpPng);
    test_assert($mime === 'image/png', 'PNG MIME detected correctly');

    $val = validate_blog_image_file($tmpPng, 'png');
    test_assert($val['ok'] === true, 'PNG validation passed');
    test_assert($val['width'] === 200 && $val['height'] === 150, 'PNG dimensions match');

    // Multi-dot filename extension extraction
    $multiDotName = 'Screenshot 2026-10-06 at 14.46.24.png';
    $extractedExt = strtolower(pathinfo($multiDotName, PATHINFO_EXTENSION));
    test_assert($extractedExt === 'png', 'Extracted extension from multi-dot filename is png');
    test_assert(validate_blog_image_file($tmpPng, $extractedExt)['ok'] === true, 'Multi-dot filename validation passes');

    // 2. Test saving and optimization to both webroot and admin directories
    $hash = bin2hex(random_bytes(16));
    $destWeb = $webUploadDir . '/' . $hash . '.png';
    $destAdmin = $adminUploadDir . '/' . $hash . '.png';
    $createdFiles[] = $destWeb;
    $createdFiles[] = $destAdmin;

    $saved = optimize_uploaded_image($tmpPng, $destWeb, 'image/png');
    test_assert($saved === true, 'optimize_uploaded_image succeeded');
    test_assert(is_file($destWeb), 'Saved image exists in web upload directory');

    @copy($destWeb, $destAdmin);
    test_assert(is_file($destAdmin), 'Saved image copied to admin upload directory');

    // 3. Test writer_validate_image with the saved path
    $publicPath = '/uploads/blogs/' . $hash . '.png';
    $validatedPath = writer_validate_image($publicPath);
    test_assert($validatedPath === $publicPath, 'writer_validate_image successfully validated public path');

    // 4. Test writer_validate_image rejects directory traversal and invalid paths
    $traversalTested = false;
    try {
        writer_validate_image('/uploads/blogs/../bad.png');
    } catch (InvalidArgumentException $e) {
        $traversalTested = true;
    }
    test_assert($traversalTested, 'writer_validate_image rejects path traversal');

    // 5. Test JPEG optimization
    $jpgData = imagecreatetruecolor(300, 200);
    $tmpJpg = tempnam(sys_get_temp_dir(), 'test_upload_') . '.jpg';
    imagejpeg($jpgData, $tmpJpg, 85);
    $createdFiles[] = $tmpJpg;

    $jpgHash = bin2hex(random_bytes(16));
    $jpgDestWeb = $webUploadDir . '/' . $jpgHash . '.jpg';
    $jpgDestAdmin = $adminUploadDir . '/' . $jpgHash . '.jpg';
    $createdFiles[] = $jpgDestWeb;
    $createdFiles[] = $jpgDestAdmin;

    $jpgSaved = optimize_uploaded_image($tmpJpg, $jpgDestWeb, 'image/jpeg');
    test_assert($jpgSaved === true, 'JPEG optimization succeeded');
    test_assert(is_file($jpgDestWeb), 'JPEG exists in web directory');
    @copy($jpgDestWeb, $jpgDestAdmin);
    test_assert(is_file($jpgDestAdmin), 'JPEG exists in admin directory');

    $jpgValidated = writer_validate_image('/uploads/blogs/' . $jpgHash . '.jpg');
    test_assert($jpgValidated === '/uploads/blogs/' . $jpgHash . '.jpg', 'JPEG validated by writer_validate_image');

    // 6. Test WebP optimization
    if (function_exists('imagewebp')) {
        $webpData = imagecreatetruecolor(100, 100);
        $tmpWebp = tempnam(sys_get_temp_dir(), 'test_upload_') . '.webp';
        imagewebp($webpData, $tmpWebp, 80);
        $createdFiles[] = $tmpWebp;

        $webpHash = bin2hex(random_bytes(16));
        $webpDestWeb = $webUploadDir . '/' . $webpHash . '.webp';
        $webpDestAdmin = $adminUploadDir . '/' . $webpHash . '.webp';
        $createdFiles[] = $webpDestWeb;
        $createdFiles[] = $webpDestAdmin;

        $webpSaved = optimize_uploaded_image($tmpWebp, $webpDestWeb, 'image/webp');
        test_assert($webpSaved === true, 'WebP optimization succeeded');
        test_assert(is_file($webpDestWeb), 'WebP exists in web directory');
        @copy($webpDestWeb, $webpDestAdmin);
        test_assert(is_file($webpDestAdmin), 'WebP exists in admin directory');

        $webpValidated = writer_validate_image('/uploads/blogs/' . $webpHash . '.webp');
        test_assert($webpValidated === '/uploads/blogs/' . $webpHash . '.webp', 'WebP validated by writer_validate_image');
    }

    // 7. Test small avatar / icon dimensions (32x32, 64x64)
    $avatarData = imagecreatetruecolor(64, 64);
    $tmpAvatar = tempnam(sys_get_temp_dir(), 'test_avatar_') . '.png';
    imagepng($avatarData, $tmpAvatar);
    $createdFiles[] = $tmpAvatar;
    $avatarVal = validate_blog_image_file($tmpAvatar, 'png');
    test_assert($avatarVal['ok'] === true && $avatarVal['width'] === 64, 'Avatar dimensions accepted');

    // 8. Test invalid / malicious file rejection
    $tmpBad = tempnam(sys_get_temp_dir(), 'test_bad_') . '.jpg';
    file_put_contents($tmpBad, '<?php echo "evil"; ?>');
    $createdFiles[] = $tmpBad;
    $badVal = validate_blog_image_file($tmpBad, 'jpg');
    test_assert($badVal['ok'] === false, 'Disguised PHP file rejected');

    echo "PASS: image validation (multi-dot, MIME, dimensions, avatars, malicious files), dual-directory storage, optimization (PNG, JPEG, WebP), and writer path validation.\n";
} finally {
    foreach ($createdFiles as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
}

