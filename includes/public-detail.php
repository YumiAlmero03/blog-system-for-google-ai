<?php
declare(strict_types=1);
require_once __DIR__ . '/game-public.php';

function public_blog_find(string $slug): ?array
{
    if (public_detail_slug($slug) === null) return null;
    // Reuse effective visibility; blogs_pdo() retains its existing due-publication lifecycle.
    $visibility = blog_public_visibility_sql();
    $stmt = blogs_pdo()->prepare("SELECT * FROM blog_posts WHERE slug=:slug AND $visibility LIMIT 1");
    $stmt->execute([':slug'=>$slug,':visibility_now'=>time()]);
    $row = $stmt->fetch();
    return $row ? blogs_add_writers(blogs_pdo(),[blog_row_to_array($row)])[0] : null;
}

function detail_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode($payload,JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function detail_request_slug(): string
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET'); detail_json(['ok'=>false,'error'=>'Method not allowed.'],405);
    }
    $slug = public_detail_slug($_GET['slug'] ?? null);
    if ($slug === null) detail_json(['ok'=>false,'error'=>'Not found.'],404);
    return $slug;
}
