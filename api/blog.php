<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/public-detail.php';
require_once __DIR__ . '/../admin/includes/blog-renderer.php';
$slug = detail_request_slug();
try {
    $blog = public_blog_find($slug);
    if (!$blog) detail_json(['ok'=>false,'error'=>'Blog not found.'],404);
    $payload = public_blog_payload($blog);

    // Guarantee countdown is completely stripped from content_html and provided in separate keys
    if (isset($payload['blog']['content_html']) && is_string($payload['blog']['content_html'])) {
        $contentHtml = $payload['blog']['content_html'];
        $countdownHtml = $payload['blog']['countdown_html'] ?? $payload['countdown_html'] ?? null;
        if (preg_match('/<section[^>]*class="[^"]*blog-countdown-block[^"]*"[^>]*>[\s\S]*?<\/section>/i', $contentHtml, $match)) {
            if (!$countdownHtml) {
                $countdownHtml = $match[0];
            }
            $contentHtml = str_replace($match[0], '', $contentHtml);
            $payload['blog']['content_html'] = $contentHtml;
        }

        $countdown = $payload['blog']['countdown'] ?? $payload['countdown'] ?? null;
        if (!$countdown && (!empty($blog['countdown_enabled']) && !empty($blog['countdown_target']))) {
            $banner = blog_render_countdown_banner([
                'title' => (string)($blog['countdown_title'] ?? '') ?: 'Limited Time Offer',
                'target' => (string)($blog['countdown_target'] ?? ''),
                'end_text' => (string)($blog['countdown_end_text'] ?? '') ?: 'This promotion has ended.',
            ]);
            $countdown = [
                'enabled' => true,
                'target' => (string)($blog['countdown_target'] ?? ''),
                'title' => (string)($blog['countdown_title'] ?? '') ?: 'Limited Time Offer',
                'end_text' => (string)($blog['countdown_end_text'] ?? '') ?: 'This promotion has ended.',
                'html' => $banner,
            ];
            $countdownHtml = $banner;
        } elseif ($countdown && is_array($countdown) && $countdownHtml && empty($countdown['html'])) {
            $countdown['html'] = $countdownHtml;
        }

        $payload['blog']['countdown'] = $countdown;
        $payload['blog']['countdown_html'] = $countdownHtml;
        $payload['countdown'] = $countdown;
        $payload['countdown_html'] = $countdownHtml;
    }

    detail_json($payload);
} catch (Throwable $error) {
    error_log('Public blog detail unavailable.');
    detail_json(['ok'=>false,'error'=>'Blog unavailable.'],500);
}
