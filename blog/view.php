<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/blog-storage.php';

$slug = isset($_GET['slug']) && is_string($_GET['slug']) ? normalize_slug($_GET['slug']) : '';
$post = $slug !== '' ? blogs_find($slug) : null;

if ($post === null || ($post['status'] ?? 'published') !== 'published') {
    http_response_code(404);
    readfile(__DIR__ . '/404.html');
    exit;
}

function blog_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function blog_render_inline_markdown(string $text): string
{
    $text = preg_replace('/\[!\[([^\]]*)\]\((\/uploads\/blogs\/[A-Za-z0-9._\/-]+)\)\]\((https?:\/\/[^)\s]+|\/[^)\s]*)\)/', '<a href="$3" target="_blank" rel="noopener noreferrer"><img src="$2" alt="$1" width="1200" height="675" loading="lazy" decoding="async"></a>', $text) ?? $text;
    $text = preg_replace('/!\[([^\]]*)\]\((\/uploads\/blogs\/[A-Za-z0-9._\/-]+)\)/', '<img src="$2" alt="$1" width="1200" height="675" loading="lazy" decoding="async">', $text) ?? $text;
    $text = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $text) ?? $text;
    $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text) ?? $text;
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;

    return $text;
}

function blog_render_markdown(string $markdown): string
{
    $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
    $escaped = blog_h($markdown);
    $blocks = preg_split('/\n\s*\n/', $escaped) ?: [];
    $html = [];

    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') {
            continue;
        }

        if (str_starts_with($block, '```')) {
            $code = trim($block, "`\n");
            $html[] = '<pre><code>' . $code . '</code></pre>';
            continue;
        }

        if (preg_match('/^###\s+(.+)$/s', $block, $matches)) {
            $html[] = '<h3>' . blog_render_inline_markdown($matches[1]) . '</h3>';
            continue;
        }

        if (preg_match('/^##\s+(.+)$/s', $block, $matches)) {
            $html[] = '<h2>' . blog_render_inline_markdown($matches[1]) . '</h2>';
            continue;
        }

        if (preg_match('/^#\s+(.+)$/s', $block, $matches)) {
            $html[] = '<h1>' . blog_render_inline_markdown($matches[1]) . '</h1>';
            continue;
        }

        if (preg_match('/^&gt;\s+(.+)$/s', $block, $matches)) {
            $html[] = '<blockquote>' . blog_render_inline_markdown($matches[1]) . '</blockquote>';
            continue;
        }

        $lines = preg_split('/\n/', $block) ?: [];
        $isUnorderedList = $lines !== [] && array_reduce($lines, static fn (bool $carry, string $line): bool => $carry && preg_match('/^\s*[-*]\s+/', $line) === 1, true);
        $isOrderedList = $lines !== [] && array_reduce($lines, static fn (bool $carry, string $line): bool => $carry && preg_match('/^\s*\d+\.\s+/', $line) === 1, true);

        if ($isUnorderedList || $isOrderedList) {
            $items = array_map(static function (string $line): string {
                $line = preg_replace('/^\s*(?:[-*]|\d+\.)\s+/', '', $line) ?? $line;
                return '<li>' . blog_render_inline_markdown($line) . '</li>';
            }, $lines);
            $tag = $isOrderedList ? 'ol' : 'ul';
            $html[] = '<' . $tag . '>' . implode('', $items) . '</' . $tag . '>';
            continue;
        }

        $html[] = '<p>' . blog_render_inline_markdown(str_replace("\n", '<br>', $block)) . '</p>';
    }

    return implode("\n", $html);
}

function blog_base_url(): string
{
    $siteBaseUrl = env_value('SITE_BASE_URL');
    if (!is_string($siteBaseUrl) || trim($siteBaseUrl) === '') {
        $siteBaseUrl = 'https://gperya-apk.com';
    }

    return rtrim($siteBaseUrl, '/');
}

function blog_absolute_url(string $url, string $baseUrl): string
{
    $url = trim($url);
    if ($url === '') {
        return $baseUrl . str_replace(' ', '%20', BLOG_DEFAULT_IMAGE);
    }
    if (preg_match('/^https?:\/\//i', $url) === 1) {
        return str_replace(' ', '%20', $url);
    }
    if ($url[0] !== '/') {
        $url = '/' . $url;
    }

    return $baseUrl . str_replace(' ', '%20', $url);
}

function blog_meta_text(string $text, int $maxLength): string
{
    $text = trim(preg_replace('/\s+/', ' ', html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?? '');
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length <= $maxLength) {
        return $text;
    }

    $trimmed = function_exists('mb_substr') ? mb_substr($text, 0, $maxLength - 1, 'UTF-8') : substr($text, 0, $maxLength - 1);
    return rtrim($trimmed, " \t\n\r\0\x0B.,;:-") . '...';
}

function blog_iso_datetime(mixed $timestamp): string
{
    $timestamp = is_int($timestamp) ? $timestamp : (is_numeric($timestamp) ? (int) $timestamp : time());
    return gmdate('c', max(0, $timestamp));
}

function blog_image_type(string $image): string
{
    $path = strtolower(parse_url($image, PHP_URL_PATH) ?: $image);
    if (str_ends_with($path, '.png')) {
        return 'image/png';
    }
    if (str_ends_with($path, '.jpg') || str_ends_with($path, '.jpeg')) {
        return 'image/jpeg';
    }
    if (str_ends_with($path, '.svg')) {
        return 'image/svg+xml';
    }

    return 'image/webp';
}

$siteBaseUrl = blog_base_url();
$siteName = blog_website_title();
$displayTitle = (string) $post['title'];
$seoTitleSource = normalize_seo_title($post['seoTitle'] ?? '');
$seoTitleSource = $seoTitleSource !== '' ? $seoTitleSource : $displayTitle;
if ($siteName !== '' && stripos($seoTitleSource, $siteName) === false) {
    $seoTitleSource .= ' | ' . $siteName;
}
$articleTitle = blog_meta_text($displayTitle, 90);
$seoTitle = blog_meta_text($seoTitleSource, 120);
$pageTitle = $seoTitle;
$excerpt = blog_meta_text($post['excerpt'], 160);
$image = $post['featuredImage'] ?: BLOG_DEFAULT_IMAGE;
$canonical = $siteBaseUrl . '/blog/' . rawurlencode($post['slug']) . '/';
$blogIndexUrl = $siteBaseUrl . '/blog/';
$homeUrl = $siteBaseUrl . '/';
$absoluteImage = blog_absolute_url($image, $siteBaseUrl);
$absoluteLogo = blog_absolute_url('/assets/images/gperya logo.webp', $siteBaseUrl);
$imageType = blog_image_type($absoluteImage);
$authorName = blog_meta_text((string) ($post['author'] ?? BLOG_DEFAULT_AUTHOR), 80);
$publishedIso = blog_iso_datetime($post['createdAt'] ?? null);
$modifiedIso = blog_iso_datetime($post['updatedAt'] ?? ($post['createdAt'] ?? null));
$focusKeyphrase = isset($post['focusKeyphrase']) && is_string($post['focusKeyphrase']) ? blog_meta_text($post['focusKeyphrase'], 120) : '';
$fbAppId = env_value('FACEBOOK_APP_ID');
$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'Organization',
            '@id' => $homeUrl . '#organization',
            'name' => $siteName,
            'url' => $homeUrl,
            'logo' => [
                '@type' => 'ImageObject',
                'url' => $absoluteLogo,
            ],
        ],
        [
            '@type' => 'WebSite',
            '@id' => $homeUrl . '#website',
            'url' => $homeUrl,
            'name' => $siteName,
            'publisher' => [
                '@id' => $homeUrl . '#organization',
            ],
        ],
        [
            '@type' => 'BreadcrumbList',
            '@id' => $canonical . '#breadcrumb',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => 'Home',
                    'item' => $homeUrl,
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => 'Blog',
                    'item' => $blogIndexUrl,
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 3,
                    'name' => $articleTitle,
                    'item' => $canonical,
                ],
            ],
        ],
        [
            '@type' => 'BlogPosting',
            '@id' => $canonical . '#article',
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $canonical,
            ],
            'headline' => $seoTitle,
            'alternativeHeadline' => $articleTitle,
            'description' => $excerpt,
            'image' => [
                $absoluteImage,
            ],
            'url' => $canonical,
            'datePublished' => $publishedIso,
            'dateModified' => $modifiedIso,
            'articleSection' => $post['category'],
            'author' => [
                '@type' => 'Person',
                'name' => $authorName,
            ],
            'publisher' => [
                '@id' => $homeUrl . '#organization',
            ],
        ],
    ],
];
if ($focusKeyphrase !== '') {
    $jsonLd['@graph'][3]['keywords'] = $focusKeyphrase;
}
?>
<!DOCTYPE html>
<html lang="en-PH">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= blog_h($pageTitle) ?></title>
  <meta name="description" content="<?= blog_h($excerpt) ?>">
  <link rel="canonical" href="<?= blog_h($canonical) ?>">
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
  <?php if ($focusKeyphrase !== ''): ?>
  <meta name="keywords" content="<?= blog_h($focusKeyphrase) ?>">
  <?php endif; ?>
  <meta property="og:type" content="article">
  <meta property="og:locale" content="en_PH">
  <meta property="og:site_name" content="<?= blog_h($siteName) ?>">
  <meta property="og:title" content="<?= blog_h($pageTitle) ?>">
  <meta property="og:description" content="<?= blog_h($excerpt) ?>">
  <meta property="og:url" content="<?= blog_h($canonical) ?>">
  <meta property="og:image" content="<?= blog_h($absoluteImage) ?>">
  <meta property="og:image:secure_url" content="<?= blog_h($absoluteImage) ?>">
  <meta property="og:image:type" content="<?= blog_h($imageType) ?>">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="675">
  <meta property="og:image:alt" content="<?= blog_h($articleTitle) ?>">
  <meta property="article:published_time" content="<?= blog_h($publishedIso) ?>">
  <meta property="article:modified_time" content="<?= blog_h($modifiedIso) ?>">
  <meta property="og:updated_time" content="<?= blog_h($modifiedIso) ?>">
  <meta property="article:author" content="<?= blog_h($authorName) ?>">
  <meta property="article:section" content="<?= blog_h($post['category']) ?>">
  <?php if ($focusKeyphrase !== ''): ?>
  <meta property="article:tag" content="<?= blog_h($focusKeyphrase) ?>">
  <?php endif; ?>
  <?php if (is_string($fbAppId) && trim($fbAppId) !== ''): ?>
  <meta property="fb:app_id" content="<?= blog_h(trim($fbAppId)) ?>">
  <?php endif; ?>
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:url" content="<?= blog_h($canonical) ?>">
  <meta name="twitter:title" content="<?= blog_h($pageTitle) ?>">
  <meta name="twitter:description" content="<?= blog_h($excerpt) ?>">
  <meta name="twitter:image" content="<?= blog_h($absoluteImage) ?>">
  <meta name="twitter:image:alt" content="<?= blog_h($articleTitle) ?>">
  <meta name="theme-color" content="#632121">
  <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
  <link rel="preload" href="/assets/css/styles.min.css" as="style"><link rel="stylesheet" href="/assets/css/styles.min.css">
  <link rel="icon" href="/assets/icons/favicon.ico">
</head>
<body>
  <a href="#main-content" class="skip-link">Skip to main content</a>

  <div class="page-shell">
    <div class="announcement-bar" style="background: linear-gradient(135deg, #632121, #802e2e); border-bottom: 1px solid #b55454;">
      <div class="marquee-wrapper">
        <div class="marquee-content">
          <p class="winner-alert-msg">📢 Big Winner Alert! Player [user_82**] won ₱128,500 on Dragon Fortune Slot!</p>
          <p>🔒 Official Verified Gperya Portal | 🎰 Welcome Pack 100% Deposit Bonus up to ₱3,888! <a href="https://gperya-apk.com/playnow" rel="sponsored nofollow noopener" target="_blank">Join Now &rarr;</a></p>
          <p>⚡ Play At our Official Link: <a href="https://gperya-apk.com/playnow" rel="sponsored nofollow noopener" target="_blank">gperya-apk.com/playnow</a></p>
          <p>🛡️ <a href="/is-gperya-legit/">Legitimacy & PAGCOR Safety Check &rarr;</a></p>
        </div>
        <div class="marquee-content">
          <p class="winner-alert-msg">📢 Big Winner Alert! Player [user_82**] won ₱128,500 on Dragon Fortune Slot!</p>
          <p>🔒 Official Verified Gperya Portal | 🎰 Welcome Pack 100% Deposit Bonus up to ₱3,888! <a href="https://gperya-apk.com/playnow" rel="sponsored nofollow noopener" target="_blank">Join Now &rarr;</a></p>
          <p>⚡ Official Verified Link: <a href="https://gperya-apk.com/playnow" rel="sponsored nofollow noopener" target="_blank">gperya-apk.com/playnow</a></p>
          <p>🛡️ <a href="/is-gperya-legit/">Legitimacy & PAGCOR Safety Check &rarr;</a></p>
        </div>
      </div>
    </div>

    <header class="site-header jackpot-header">
      <div class="header-inner">
        <a href="/" class="brand-logo" aria-label="AceJackpot Gperya Home">
          <img src="/assets/images/gperya logo.webp" alt="Gperya Official Logo" class="brand-logo-img" height="38" width="178">
          <span class="badge-tag" style="background:#f3c64c; color:#3b0e0e;">OFFICIAL</span>
        </a>

        <nav class="desktop-nav">
          <a href="/" class="nav-link">Home</a>
          <div class="nav-dropdown">
            <button class="nav-link dropdown-toggle" aria-expanded="false" aria-haspopup="true">
              Games <span class="dropdown-arrow">▾</span>
            </button>
            <div class="dropdown-menu">
              <a href="/slots/" class="dropdown-item">🎰 Slots</a>
              <a href="/live-casino/" class="dropdown-item">🃏 Live Casino</a>
              <a href="/perya-games/" class="dropdown-item">🎡 Perya Games</a>
            </div>
          </div>
          <a href="/sports/" class="nav-link">Sportsbook</a>
          <a href="/promotions/" class="nav-link">Promotions</a>
          <a href="/vip/" class="nav-link">VIP</a>
          <a href="/blog/" class="nav-link active">Blog & Guides</a>
          <a href="/gperya-app-download/" class="nav-link">Download App</a>
        </nav>

        <div class="header-ctas">
          <a href="/gperya-login/" class="btn btn-secondary btn-sm" style="border-color:#b55454;">Login</a>
          <a href="https://gperya-apk.com/playnow" class="btn btn-gold btn-sm" style="background:linear-gradient(180deg,#fce075,#e5a720); color:#3b0e0e; font-weight:900;" rel="sponsored nofollow noopener" target="_blank">Register</a>
          <button class="mobile-menu-btn" aria-label="Toggle Navigation Menu" aria-expanded="false">
            <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
          </button>
        </div>
      </div>
    </header>

    <div class="mobile-drawer" id="mobile-drawer">
      <div class="drawer-content" style="background:#632121; color:#ffffff;">
        <div class="drawer-header" style="background:#4a1515; border-bottom:1px solid #b55454;">
          <img src="/assets/images/gperya logo.webp" alt="Gperya Logo" class="brand-logo-img" height="32" width="150">
          <button class="drawer-close-btn" aria-label="Close Navigation Menu">&times;</button>
        </div>
        <nav class="drawer-nav">
          <a href="/">🏠 Home</a>
          <a href="/slots/">🎰 Online Slots</a>
          <a href="/live-casino/">🃏 Live Casino Dealers</a>
          <a href="/fishing/">🐟 Arcade Fishing Games</a>
          <a href="/perya-games/">🎡 Perya Games & Color Game</a>
          <a href="/sports/">⚽ Sports Betting</a>
          <a href="/promotions/">🎁 Promotions & Bonuses</a>
          <a href="/vip/">💎 VIP Club</a>
          <a href="/blog/" class="active">📚 News & Guides</a>
          <a href="/gperya-login/">🔑 Account Login Help</a>
          <a href="/gperya-register/">📝 Register Account</a>
          <a href="/gperya-app-download/">📱 Download Android / iOS APK</a>
          <a href="/gperya-kyc-verification/">🆔 KYC Verification</a>
          <a href="/gperya-withdrawal/">💸 Fast Cashout Guide</a>
          <a href="/is-gperya-legit/">🛡️ Safety & Legitimacy Audit</a>
        </nav>
        <div class="drawer-footer">
          <p>Gperya Official Portal. 21+ Play Responsibly.</p>
        </div>
      </div>
    </div>

    <main id="main-content">
      <div class="page-hero">
        <div style="max-width: var(--content-max); margin: 0 auto;">
          <nav class="breadcrumbs" aria-label="Breadcrumbs">
            <a href="/">Home</a> &rsaquo;
            <a href="/blog/">Blog</a> &rsaquo;
            <span><?= blog_h($displayTitle) ?></span>
          </nav>
          <h1 style="font-size: clamp(1.5rem, 3.5vw, 2.2rem); font-weight: 800;"><?= blog_h($displayTitle) ?></h1>
          <p style="font-size:0.85rem; color:#ffe6e8; margin-top:8px;">Published: <?= blog_h($post['date']) ?> &bull; By <?= blog_h($authorName) ?></p>
        </div>
      </div>

      <article class="section-padding">
        <div style="max-width: 860px; margin: 0 auto;">
          <div style="border-radius: var(--radius-md); overflow: hidden; margin-bottom: 20px; border: 1px solid var(--border); background: var(--surface-soft);">
            <img src="<?= blog_h($image) ?>" alt="<?= blog_h($displayTitle) ?>" width="1200" height="675" fetchpriority="high" decoding="async" style="width: 100%; height: auto; max-height: 420px; object-fit: cover; display: block;">
          </div>

          <div class="card" style="margin-bottom: 20px;">
            <span class="badge badge-yellow"><?= blog_h($post['category']) ?></span>
            <p style="color: var(--text-muted); margin-top: 10px;"><?= blog_h($excerpt) ?></p>
          </div>

          <div class="markdown-rendered-body card">
            <?= blog_render_markdown($post['content']) ?>
          </div>
        </div>

        <!-- Play Now Banner inside Article -->
        <div class="playnow-blog-banner">
          <div class="playnow-banner-inner">
            <div class="playnow-banner-content">
              <span class="playnow-badge">🎰 Official Gperya Portal</span>
              <h3 class="playnow-banner-title">Ready to Play Real Money Slots &amp; Live Casino?</h3>
              <ul class="playnow-perks-list">
                <li class="playnow-perk-item">🎁 <strong>100% Welcome Bonus</strong> up to ₱10,000</li>
                <li class="playnow-perk-item">⚡ <strong>1-Min Cashouts</strong> via GCash &amp; Maya</li>
                <li class="playnow-perk-item">🛡️ <strong>PAGCOR Compliant</strong> &amp; 24/7 Support</li>
              </ul>
            </div>
            <div class="playnow-banner-action">
              <a href="https://gperya-apk.com/playnow" class="playnow-btn-gold" rel="sponsored nofollow noopener" target="_blank">
                ▶ PLAY NOW &amp; CLAIM BONUS
              </a>
              <span class="playnow-subnote">🔒 Fast 30-Second Mobile Registration</span>
            </div>
          </div>
        </div>

        <!-- About Author Section -->
        <div class="blog-author-card">
          <div class="author-avatar-wrap">
            <img src="/assets/images/Tabitha Cruz.webp" alt="Tabitha Cruz - Writer" class="author-avatar-img" width="96" height="96" loading="lazy" decoding="async">
            <span class="author-verified-badge" title="Verified iGaming Auditor">✓</span>
          </div>
          <div class="author-details">
            <div class="author-header-row">
              <div class="author-name">
                <span id="author-display-name">Tabitha Cruz</span>
                <span class="author-role-tag">Writer</span>
              </div>
            </div>
            <p class="author-bio">
              Tabitha Cruz is a seasoned iGaming and writer with extensive experience across iGaming, casino, and travel niches. Her expertise has given her a strong understanding of audience behavior, content trends, and conversion-focused optimization strategies that drive measurable growth.
            </p>
            <div class="author-credentials">
              <span class="author-cred-badge">⚡ GCash &amp; Maya Payment Expert</span>
              <span class="author-cred-badge">🎰 RNG Fair Play Specialist</span>
            </div>
          </div>
        </div>

        <!-- Follow Social Media Section -->
        <div class="blog-social-card">
          <h3 class="social-card-title">
            📢 Follow Gperya Official Community Channels
          </h3>
          <p class="social-card-subtitle">
            Connect with over 50,000+ active players across the Philippines. Get exclusive Telegram red packets, daily promo redemption codes, instant maintenance alerts, and jackpot win announcements!
          </p>

          <div class="social-channels-grid">
            <!-- Telegram -->
            <a href="https://t.me/+ON7sbKSUI2g0YmU1" class="social-channel-box" target="_blank" rel="sponsored nofollow noopener">
              <div class="social-channel-info">
                <div class="social-icon-btn social-icon-telegram">✈</div>
                <div class="social-channel-text">
                  <span class="social-channel-name">Telegram Channel</span>
                  <span class="social-channel-sub">Daily Red Packets &amp; Codes</span>
                </div>
              </div>
              <span class="social-action-arrow">&rarr;</span>
            </a>

            <!-- Facebook -->
            <a href="https://www.facebook.com/gperyagames/" class="social-channel-box" target="_blank" rel="sponsored nofollow noopener">
              <div class="social-channel-info">
                <div class="social-icon-btn social-icon-facebook">f</div>
                <div class="social-channel-text">
                  <span class="social-channel-name">Facebook Page</span>
                  <span class="social-channel-sub">Community News &amp; Winners</span>
                </div>
              </div>
              <span class="social-action-arrow">&rarr;</span>
            </a>

            <!-- YouTube -->
            <a href="https://gperya-apk.com/playnow" class="social-channel-box" target="_blank" rel="sponsored nofollow noopener">
              <div class="social-channel-info">
                <div class="social-icon-btn social-icon-youtube">▶</div>
                <div class="social-channel-text">
                  <span class="social-channel-name">YouTube Hub</span>
                  <span class="social-channel-sub">Video Tutorials &amp; Reviews</span>
                </div>
              </div>
              <span class="social-action-arrow">&rarr;</span>
            </a>

            <!-- X / Twitter -->
            <a href="https://gperya-apk.com/playnow" class="social-channel-box" target="_blank" rel="sponsored nofollow noopener">
              <div class="social-channel-info">
                <div class="social-icon-btn social-icon-twitter">𝕏</div>
                <div class="social-channel-text">
                  <span class="social-channel-name">X (Twitter) Official</span>
                  <span class="social-channel-sub">24/7 Flash Promo Alerts</span>
                </div>
              </div>
              <span class="social-action-arrow">&rarr;</span>
            </a>
          </div>
        </div>
      </article>
      <div style="margin:32px; display:flex; gap:12px; flex-wrap:wrap; justify-content: space-between; align-items: center; background: var(--surface-soft); padding: 16px 20px; border-radius: var(--radius-md); border: 1px solid var(--border);">
        <a href="/blog/" class="btn btn-secondary">&larr; Back to Blog Hub</a>
        <a href="https://gperya-apk.com/playnow" class="btn btn-primary" rel="sponsored nofollow noopener" target="_blank">🚀 Play on Official Gperya Portal</a>
      </div>
    </main>

        <!-- Footer -->
    <footer class="site-footer" style="background:#4a1515; border-top:1px solid #b55454; color:#ffd6d6;">
      <div class="footer-inner">
        <div class="footer-col">
          <span class="brand-logo"><img src="/assets/images/gperya logo.webp" alt="Gperya Logo" class="brand-logo-img" height="34" width="160"> <span class="badge-tag" style="background:#f3c64c; color:#3b0e0e;">OFFICIAL</span></span>
          <p style="font-size:0.82rem; margin-top:8px;">
            Gperya Official Entertainment &amp; Casino Gaming Portal in the Philippines. Experience online slots, live dealer games, and fast GCash cashouts.
          </p>
          <p style="font-size:0.8rem; color:#ffd6d6; margin-top:4px;">21+ Only. Please play responsibly.</p>
        </div>

        <div class="footer-col">
          <h4 style="color:#fff;">Core Features</h4>
          <a href="/slots/" style="color:#ffd6d6;">Online Slots</a>
          <a href="/live-casino/" style="color:#ffd6d6;">Live Casino</a>
          <a href="/perya-games/" style="color:#ffd6d6;">Perya Games</a>
          <a href="/gperya-app-download/" style="color:#ffd6d6;">Download App Guide</a>
        </div>

        <div class="footer-col">
          <h4 style="color:#fff;">Customer Center</h4>
          <a href="/gperya-login/" style="color:#ffd6d6;">Gperya Login</a>
          <a href="/gperya-register/" style="color:#ffd6d6;">Gperya Register</a>
          <a href="/customer-support/" style="color:#ffd6d6;">24/7 Customer Support</a>
          <a href="/gperya-withdrawal/" style="color:#ffd6d6;">Withdrawal Help</a>
          <a href="/is-gperya-legit/" style="color:#ffd6d6;">Legitimacy Audit</a>
        </div>

        <div class="footer-col">
          <h4 style="color:#fff;">Safety &amp; Legal</h4>
          <a href="/privacy-policy/" style="color:#ffd6d6;">Privacy Policy</a>
          <a href="/terms-and-conditions/" style="color:#ffd6d6;">Terms &amp; Conditions</a>
          <a href="/responsible-gaming/" style="color:#ffd6d6;">Responsible Gaming</a>
          <a href="https://gperya-apk.com/playnow" style="color:#f3c64c; font-weight:800;" rel="sponsored nofollow noopener" target="_blank">Visit Verified Gperya</a>
        </div>
      </div>

      <div class="footer-bottom" style="border-top:1px solid #b55454;">
        <p>© 2026 Gperya. All rights reserved. 21+ Play Responsibly.</p>
      </div>
    </footer>

    <!-- Mobile Persistent Sticky Bottom Navigation Bar -->
    <nav class="ace-bottom-nav">
      <a href="/" class="ace-nav-item">
        <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
        <span>Home</span>
      </a>
      <a href="/slots/" class="ace-nav-item">
        <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 14l-5-5 1.41-1.41L12 14.17l7.59-7.59L21 8l-9 9z"/></svg>
        <span>Slots</span>
      </a>
      <a href="https://gperya-apk.com/playnow" class="ace-nav-center-play" rel="sponsored nofollow noopener" target="_blank" aria-label="Play Now">
        ▶
      </a>
      <a href="/sports/" class="ace-nav-item active">
        <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93z"/></svg>
        <span>Sports</span>
      </a>
      <a href="/gperya-login/" class="ace-nav-item">
        <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 10.48 2 16c0 3.31 2.69 6 6 6s6-2.69 6-6c0-5.52-4.48-14-10-14zm0 18c-2.21 0-4-1.79-4-4 0-3.31 2.69-8 4-10.22 1.31 2.22 4 6.91 4 10.22 0 2.21-1.79 4-4 4z"/></svg>
        <span>Account</span>
      </a>
    </nav>
  </div>

  <script src="/assets/js/config.min.js" defer></script>
  <script src="/assets/js/playnow-clicks.min.js" defer></script>
  <script src="/assets/js/main.min.js" defer></script>
</body>
</html>
