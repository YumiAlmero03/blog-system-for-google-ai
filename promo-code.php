<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/playnow-promos.php';

function promo_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function promo_base_url(): string
{
    return site_base_url();
}

function promo_absolute_url(string $url, string $baseUrl): string
{
    return public_url($url, $baseUrl);
}

function promo_default_image(): string
{
    return 'https://images.unsplash.com/photo-1518609878373-06d740f60d8b?auto=format&fit=crop&w=1200&q=80';
}

$code = isset($_GET['code']) && is_string($_GET['code']) ? $_GET['code'] : '';
$isPreview = isset($_GET['preview']) && (string) $_GET['preview'] === '1';
$promo = playnow_promo_find($code);
if ($promo === null || playnow_promo_target($promo['targetUrl'] ?? '') === '') {
    http_response_code(404);
    header('X-Robots-Tag: noindex, nofollow');
    echo 'Promo link not found.';
    exit;
}

if (!$isPreview) {
  $promo = playnow_promo_record_click($code) ?? $promo;
}
$baseUrl = promo_base_url();
$canonical = $baseUrl . '/promo-code/' . rawurlencode($code);
$targetUrl = promo_absolute_url(playnow_promo_target($promo['targetUrl']), $baseUrl);
$title = playnow_promo_clean($promo['ogTitle'] ?? '', 180) ?: 'Play Now';
$description = playnow_promo_clean($promo['ogDescription'] ?? '', 300) ?: 'Open Play Now.';
$image = playnow_promo_clean($promo['ogImage'] ?? '', 500);
    $image = $image !== '' ? promo_absolute_url($image, $baseUrl) : promo_default_image();
ob_start(static fn(string $html): string => public_html_absolute_urls($html, $baseUrl));
?>
<!DOCTYPE html>
<html lang="en-PH">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <meta name="description" content="<?= promo_h($description) ?>">
  <link rel="canonical" href="<?= promo_h($canonical) ?>">
  <meta property="og:type" content="website">
  <meta property="og:url" content="<?= promo_h($canonical) ?>">
  <meta property="og:title" content="<?= promo_h($title) ?>">
  <meta property="og:description" content="<?= promo_h($description) ?>">
    <meta property="og:image" content="<?= promo_h($image) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= promo_h($title) ?>">
  <meta name="twitter:description" content="<?= promo_h($description) ?>">
    <meta name="twitter:image" content="<?= promo_h($image) ?>">
  <meta http-equiv="refresh" content="0;url=<?= promo_h($targetUrl) ?>">
  <title><?= promo_h($title) ?></title>
  <script defer src="/assets/absolute-urls.min.js"></script>
  <script>window.location.replace(<?= json_encode($targetUrl, JSON_UNESCAPED_SLASHES) ?>);</script>
  <script defer src="/assets/playnow-click-tracker.min.js"></script>
</head>
<body>
  <p><a href="<?= promo_h($targetUrl) ?>">Continue to Play Now</a></p>
</body>
</html>
