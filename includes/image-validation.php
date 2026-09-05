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
