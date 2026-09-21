<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/public-detail.php';
require_once __DIR__ . '/../admin/includes/blog-renderer.php';
$slug = detail_request_slug();
try {
    $blog = public_blog_find($slug);
    if (!$blog) detail_json(['ok'=>false,'error'=>'Blog not found.'],404);
    detail_json(public_blog_payload($blog));
} catch (Throwable $error) {
    error_log('Public blog detail unavailable.');
    detail_json(['ok'=>false,'error'=>'Blog unavailable.'],500);
}
