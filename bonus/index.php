<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/blog-storage.php';

function bonus_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bonus_base_url(): string
{
    $siteBaseUrl = env_value('SITE_BASE_URL');
    if (!is_string($siteBaseUrl) || trim($siteBaseUrl) === '') {
        $host = isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'freeonlinegames.info';
        $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'https';
        $siteBaseUrl = $scheme . '://' . $host;
    }

    return rtrim($siteBaseUrl, '/');
}

function bonus_absolute_url(string $url, string $baseUrl): string
{
    $url = trim($url);
    if ($url === '') {
        $url = '/assets/bonus-img.png';
    }
    if (preg_match('/^https?:\/\//i', $url) === 1) {
        return str_replace(' ', '%20', $url);
    }
    if ($url[0] !== '/') {
        $url = '/' . $url;
    }

    return $baseUrl . str_replace(' ', '%20', $url);
}

$code = isset($_GET['code']) && is_string($_GET['code']) ? $_GET['code'] : '';
$bonus = bonus_link_find($code, true);

if ($bonus === null) {
    http_response_code(404);
    header('X-Robots-Tag: noindex, nofollow');
    echo 'Bonus link not found.';
    exit;
}

bonus_link_record_click($bonus['code']);

$baseUrl = bonus_base_url();
$canonical = $baseUrl . '/bonus/' . rawurlencode($bonus['code']) . '/';
$pageTitle = $bonus['metaTitle'] !== '' ? $bonus['metaTitle'] : $bonus['title'];
$description = $bonus['metaDescription'];
$socialTitle = $bonus['socialTitle'] !== '' ? $bonus['socialTitle'] : $pageTitle;
$socialDescription = $bonus['socialDescription'] !== '' ? $bonus['socialDescription'] : $description;
$image = bonus_absolute_url($bonus['imagePath'], $baseUrl);
$targetUrl = $bonus['targetUrl'];
$siteName = blog_website_title();
?>
<!DOCTYPE html>
<html lang="en-PH">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= bonus_h($pageTitle) ?></title>
  <meta name="description" content="<?= bonus_h($description) ?>">
  <link rel="canonical" href="<?= bonus_h($canonical) ?>">
  <meta property="og:site_name" content="<?= bonus_h($siteName) ?>">
  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= bonus_h($socialTitle) ?>">
  <meta property="og:description" content="<?= bonus_h($socialDescription) ?>">
  <meta property="og:url" content="<?= bonus_h($canonical) ?>">
  <meta property="og:image" content="<?= bonus_h($image) ?>">
  <meta property="og:image:secure_url" content="<?= bonus_h($image) ?>">
  <meta property="og:image:alt" content="<?= bonus_h($socialTitle) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= bonus_h($socialTitle) ?>">
  <meta name="twitter:description" content="<?= bonus_h($socialDescription) ?>">
  <meta name="twitter:image" content="<?= bonus_h($image) ?>">
  <meta http-equiv="refresh" content="0;url=<?= bonus_h($targetUrl) ?>">
  <link rel="icon" href="/assets/icons/favicon.ico">
  <style>
    body {
      min-height: 100vh;
      margin: 0;
      display: grid;
      place-items: center;
      background: #12090a;
      color: #fff;
      font-family: Arial, Helvetica, sans-serif;
      text-align: center;
      padding: 24px;
    }
    a {
      color: #f3c64c;
      font-weight: 800;
    }
  </style>
  <script>
    window.location.replace(<?= json_encode($targetUrl, JSON_UNESCAPED_SLASHES) ?>);
  </script>
</head>
<body>
  <main>
    <h1><?= bonus_h($bonus['title']) ?></h1>
    <p>Redirecting to your bonus offer...</p>
    <p><a href="<?= bonus_h($targetUrl) ?>" rel="sponsored nofollow noopener">Continue to bonus</a></p>
  </main>
</body>
</html>
