<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/public-detail.php';
require_once __DIR__ . '/../includes/blog-renderer.php';
require_once __DIR__ . '/../includes/public-head.php';
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
try {
    $slug = public_detail_slug($_GET['slug'] ?? null);
    $post = $slug !== null ? public_blog_find($slug) : null;
    if (!$post) { http_response_code(404); echo 'Blog not found.'; exit; }
    $payload = public_blog_payload($post);
} catch (Throwable $error) {
    error_log('Public blog view unavailable.');
    http_response_code(500); echo 'Blog unavailable.'; exit;
}
?>
<!doctype html>
<html lang="en-PH"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<?= public_head_html(['title'=>$post['seoTitle'] ?: $post['title'],'description'=>$post['excerpt'],'image'=>$post['featuredImage'] === BLOG_DEFAULT_IMAGE ? '' : $post['featuredImage'],'fallback_image'=>BLOG_DEFAULT_IMAGE,'canonical'=>$payload['blog']['blogUrl'],'type'=>'article']) ?>
<?php if ($payload['faq_schema']): ?><script type="application/ld+json"><?= json_encode($payload['faq_schema'],JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script><?php endif; ?>
</head><body><main><article><h1><?= blog_h($post['title']) ?></h1>
<div class="blog-article-content"><?= $payload['blog']['content_html'] ?></div>
<aside class="blog-article-content" aria-label="About the writer">
<?php if ($payload['blog']['writer']['profile_image'] !== ''): ?><img src="<?= blog_h($payload['blog']['writer']['profile_image']) ?>" alt="<?= blog_h($payload['blog']['writer']['name']) ?>" width="96" height="96" loading="lazy" style="max-width:100%;object-fit:cover;aspect-ratio:1"><?php endif; ?>
<p><strong>Written by <?= blog_h($payload['blog']['writer']['name']) ?></strong><?php if ($payload['blog']['writer']['role_name'] !== ''): ?><br><span><?= blog_h($payload['blog']['writer']['role_name']) ?></span><?php endif; ?></p>
<?php if ($payload['blog']['writer']['bio'] !== ''): ?><p><?= nl2br(blog_h($payload['blog']['writer']['bio'])) ?></p><?php endif; ?>
<?php foreach ($payload['blog']['writer']['socials'] as $social): ?><a href="<?= blog_h($social['url']) ?>" rel="noopener noreferrer"><?= blog_h($social['label'] ?: $social['platform']) ?></a> <?php endforeach; ?>
</aside>
</article></main></body></html>
