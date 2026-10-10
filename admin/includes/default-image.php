<?php
declare(strict_types=1);
require_once __DIR__ . '/blog-storage.php';
require_once __DIR__ . '/image-validation.php';

/** Save a verified raster upload; publishing the setting invalidates blog API caches. */
function site_default_image_upload(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !isset($file['tmp_name']) || !is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('Choose an image that uploaded successfully.');
    }
    return site_default_image_save_file($file['tmp_name']);
}

function site_default_image_save_file(string $path): string
{
    $size = is_file($path) ? filesize($path) : 0;
    if (!$size || $size > 10*1024*1024) throw new InvalidArgumentException('Image must be between 1 byte and 10 MB.');
    $check = validate_blog_image_file($path);
    $types = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/avif'=>'avif'];
    $dimensions = @getimagesize($path);
    if (!$check['ok'] || !isset($types[$check['mime'] ?? '']) || !$dimensions || $dimensions[0]<16 || $dimensions[1]<16 || $dimensions[0]>8000 || $dimensions[1]>8000 || $dimensions[0]*$dimensions[1]>25000000) {
        throw new InvalidArgumentException('Use a valid PNG, JPEG, WebP or AVIF image, 16–8000 pixels per side and at most 25 megapixels.');
    }
    $dir = __DIR__.'/../uploads/blogs';
    if (!is_dir($dir) && !mkdir($dir,0755,true) && !is_dir($dir)) throw new RuntimeException('Cannot create image directory.');
    $name = 'site-default-'.bin2hex(random_bytes(12)).'.'.$types[$check['mime']];
    $destination = $dir.'/'.$name;
    if (!copy($path,$destination)) throw new RuntimeException('Cannot save default image.');
    chmod($destination,0644);
    try { blog_setting_set('default_image','/uploads/blogs/'.$name); }
    catch (Throwable $e) { unlink($destination); throw $e; }
    // Mirror existing public upload convention for servers without the Apache upload rewrite.
    $webDir = dirname(__DIR__,2).'/uploads/blogs';
    if (is_dir($webDir) || @mkdir($webDir,0755,true)) @copy($destination,$webDir.'/'.$name);
    return '/uploads/blogs/'.$name;
}
