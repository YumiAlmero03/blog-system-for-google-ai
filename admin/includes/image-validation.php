<?php
declare(strict_types=1);

function allowed_blog_image_mimes(): array
{
    $allowed = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    if (function_exists('imageavif') || function_exists('imagecreatefromavif') || defined('IMAGETYPE_AVIF')) {
        $allowed['image/avif'] = ['avif'];
    }

    return $allowed;
}

function detect_blog_image_mime(string $path): ?string
{
    $allowed = allowed_blog_image_mimes();

    if (class_exists('finfo') && defined('FILEINFO_MIME_TYPE')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        if (is_string($mime) && isset($allowed[$mime])) {
            return $mime;
        }
    }

    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($path);
        if (is_string($mime) && isset($allowed[$mime])) {
            return $mime;
        }
    }

    $dimensions = @getimagesize($path);
    if (is_array($dimensions) && isset($dimensions['mime']) && is_string($dimensions['mime']) && isset($allowed[$dimensions['mime']])) {
        return $dimensions['mime'];
    }

    return null;
}

function validate_blog_image_file(string $path, ?string $expectedExtension = null): array
{
    $mime = detect_blog_image_mime($path);
    if (!is_string($mime)) {
        return ['ok' => false, 'reason' => 'unsupported or unknown MIME type'];
    }

    $allowed = allowed_blog_image_mimes();
    $extension = strtolower((string) $expectedExtension);
    if ($extension !== '' && !in_array($extension, $allowed[$mime] ?? [], true)) {
        return ['ok' => false, 'reason' => 'extension does not match detected MIME type ' . $mime];
    }

    $dimensions = @getimagesize($path);
    if ($dimensions === false || !isset($dimensions[0], $dimensions[1])) {
        if ($mime === 'image/avif') {
            return [
                'ok' => true,
                'mime' => $mime,
                'width' => null,
                'height' => null,
            ];
        }

        return ['ok' => false, 'reason' => 'image dimensions could not be read'];
    }

    return [
        'ok' => true,
        'mime' => $mime,
        'width' => (int) $dimensions[0],
        'height' => (int) $dimensions[1],
    ];
}

function is_gd_image(mixed $image): bool
{
    return is_resource($image) || (class_exists('GdImage') && $image instanceof GdImage);
}

function optimize_uploaded_image(string $source, string $destination, string $mime, int $maxWidth = 1200, int $maxHeight = 675, int $quality = 60): bool
{
    if (!extension_loaded('gd')) {
        return @move_uploaded_file($source, $destination) || @copy($source, $destination);
    }

    $image = match ($mime) {
        'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($source) : false,
        'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($source) : false,
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
        'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($source) : false,
        default => false,
    };

    if (!is_gd_image($image)) {
        return @move_uploaded_file($source, $destination) || @copy($source, $destination);
    }

    $sourceWidth = imagesx($image);
    $sourceHeight = imagesy($image);
    $scale = min(1, $maxWidth / max(1, $sourceWidth), $maxHeight / max(1, $sourceHeight));
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
            $image = $resized;
        }
    }

    if ($mime === 'image/jpeg') {
        imageinterlace($image, true);
        $saved = imagejpeg($image, $destination, $quality);
    } elseif ($mime === 'image/png') {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $saved = imagepng($image, $destination, 6);
    } elseif ($mime === 'image/webp' && function_exists('imagewebp')) {
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);
        $saved = imagewebp($image, $destination, $quality);
    } elseif ($mime === 'image/avif' && function_exists('imageavif')) {
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);
        $saved = imageavif($image, $destination, $quality);
    } else {
        return @move_uploaded_file($source, $destination) || @copy($source, $destination);
    }

    if ($saved && is_file($destination)) {
        $sourceSize = @filesize($source);
        $destinationSize = @filesize($destination);
        if (!$wasResized && is_int($sourceSize) && is_int($destinationSize) && $destinationSize > $sourceSize) {
            @unlink($destination);
            return @move_uploaded_file($source, $destination) || @copy($source, $destination);
        }
        return true;
    }

    if (is_file($destination)) {
        @unlink($destination);
    }

    return @move_uploaded_file($source, $destination) || @copy($source, $destination);
}
