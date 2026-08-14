<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/blog-storage.php';

function game_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function game_base_url(): string
{
    $siteBaseUrl = env_value('SITE_BASE_URL');
    if (!is_string($siteBaseUrl) || trim($siteBaseUrl) === '') {
        $siteBaseUrl = 'https://gperya-apk.com';
    }

    return rtrim($siteBaseUrl, '/');
}

function game_iframe_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || preg_match('/^https?:\/\//i', $url) !== 1) {
        return '';
    }

    $token = env_value('SLOTSLAUNCH_API_TOKEN');
    if (is_string($token) && $token !== '' && str_contains($url, 'slotslaunch.com/iframe/') && !str_contains($url, 'token=')) {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'token=' . rawurlencode($token);
    }

    return $url;
}

function game_text_blocks(string $value): array
{
    $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
    if ($value === '') {
        return [];
    }

    $blocks = preg_split("/\n{2,}/", $value) ?: [];
    return array_values(array_filter(array_map('trim', $blocks), static fn (string $block): bool => $block !== ''));
}

function game_compact_number(mixed $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    if (is_numeric($value)) {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.');
    }

    return trim((string) $value);
}

function game_stat_text(mixed $value, string $suffix = ''): string
{
    $text = game_compact_number($value);
    if ($text === '') {
        return '';
    }

    if ($suffix !== '' && !str_ends_with($text, $suffix)) {
        $text .= $suffix;
    }

    return $text;
}

function game_bet_range(mixed $minBet, mixed $maxBet): string
{
    $min = game_compact_number($minBet);
    $max = game_compact_number($maxBet);

    if ($min === '' && $max === '') {
        return '';
    }

    if ($min === '') {
        return 'Up to PHP ' . $max;
    }

    if ($max === '') {
        return 'From PHP ' . $min;
    }

    return 'PHP ' . $min . ' - PHP ' . $max;
}

function game_stat_item(string $label, string $value, string $class = ''): ?array
{
    $value = trim($value);
    if ($value === '' || strcasecmp($value, 'N/A') === 0) {
        return null;
    }

    return [
        'label' => $label,
        'value' => $value,
        'class' => $class,
    ];
}

$slug = isset($_GET['slug']) && is_string($_GET['slug']) ? normalize_slug($_GET['slug']) : '';
$game = null;
if ($slug !== '') {
    $stmt = blogs_pdo()->prepare(
        'SELECT *
         FROM games
         WHERE slug = :slug
         ORDER BY published DESC, updated_at DESC, id DESC
         LIMIT 1'
    );
    $stmt->execute([':slug' => $slug]);
    $row = $stmt->fetch();
    $game = is_array($row) ? $row : null;
}

if ($game === null) {
    http_response_code(404);
    readfile(__DIR__ . '/404.html');
    exit;
}

$baseUrl = game_base_url();
$canonical = $baseUrl . '/game/' . rawurlencode((string) $game['slug']) . '/';
$iframeUrl = game_iframe_url((string) ($game['url'] ?? ''));
$title = trim((string) ($game['name'] ?? 'Game'));
$provider = trim((string) ($game['provider'] ?? ''));
$type = trim((string) ($game['type'] ?? ''));
$thumb = trim((string) ($game['thumb'] ?? ''));
$descriptionParts = array_filter([$provider, $type, $game['rtp'] !== null ? 'RTP ' . $game['rtp'] . '%' : '']);
$shortDescription = trim((string) ($game['short_description'] ?? ''));
$longDescription = trim((string) ($game['long_description'] ?? ''));
$description = $shortDescription !== ''
    ? $shortDescription
    : trim($title . ($descriptionParts !== [] ? ' - ' . implode(' | ', $descriptionParts) : '') . ' on GperyaPH.');
$overlayImage = $thumb !== '' ? $thumb : '/assets/images/gperya logo.webp';
$overviewTitle = 'Overview & Game Mechanics';
$overviewBlocks = game_text_blocks($longDescription);
if ($overviewBlocks !== [] && strtolower($overviewBlocks[0]) === strtolower($overviewTitle)) {
    array_shift($overviewBlocks);
}
if ($overviewBlocks === [] && $description !== '') {
    $overviewBlocks = [$description];
}
$gameStats = array_values(array_filter([
    game_stat_item('RTP Rate', game_stat_text($game['rtp'] ?? null, '%'), 'is-green'),
    game_stat_item('Volatility', game_stat_text($game['volatility'] ?? null)),
    game_stat_item('Max Multiplier', game_stat_text($game['max_win_per_spin'] ?? null, 'x'), 'is-orange'),
    game_stat_item('Paylines / Ways', game_stat_text($game['paylines'] ?? null), 'is-small'),
    game_stat_item('Reels & Grid', game_stat_text($game['reels'] ?? null), 'is-small'),
    game_stat_item('Min - Max Bet', game_bet_range($game['min_bet'] ?? null, $game['max_bet'] ?? null), 'is-small'),
]));
?>
<!DOCTYPE html>
<html lang="en-PH">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= game_h($title) ?> | GperyaPH Game</title>
  <meta name="description" content="<?= game_h($description) ?>">
  <link rel="canonical" href="<?= game_h($canonical) ?>">
  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= game_h($title) ?> | GperyaPH Game">
  <meta property="og:description" content="<?= game_h($description) ?>">
  <meta property="og:url" content="<?= game_h($canonical) ?>">
  <?php if ($thumb !== ''): ?><meta property="og:image" content="<?= game_h($thumb) ?>"><?php endif; ?>
  <meta name="twitter:card" content="summary_large_image">
  <link rel="preload" href="/assets/css/styles.min.css" as="style"><link rel="stylesheet" href="/assets/css/styles.min.css">
  <link rel="icon" href="/assets/icons/favicon.ico">
  <style>
    .game-play-wrap {
      max-width: 1180px;
      margin: 0 auto;
      padding: 20px 16px 36px;
    }
    .game-play-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 16px;
      padding: 16px;
      background: #632121;
      border: 1px solid #b55454;
      border-radius: 8px;
      color: #fff;
    }
    .game-play-title {
      margin: 0;
      color: #fff;
      font-size: clamp(1.25rem, 3vw, 1.9rem);
      line-height: 1.2;
    }
    .game-play-meta {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      margin-top: 8px;
    }
    .game-pill {
      display: inline-flex;
      align-items: center;
      min-height: 24px;
      padding: 3px 9px;
      border-radius: 999px;
      background: rgba(243, 198, 76, 0.16);
      border: 1px solid rgba(243, 198, 76, 0.45);
      color: #f3c64c;
      font-size: 0.75rem;
      font-weight: 900;
    }
    .game-frame-shell {
      position: relative;
      background: #160505;
      border: 2px solid #b55454;
      border-radius: 8px;
      overflow: hidden;
      box-shadow: 0 14px 36px rgba(0, 0, 0, 0.35);
    }
    .game-frame {
      display: block;
      width: 100%;
      height: min(74vh, 760px);
      min-height: 520px;
      border: 0;
      background: #000;
    }
    .game-demo-gate {
      position: absolute;
      inset: 0;
      z-index: 3;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      background:
        linear-gradient(135deg, rgba(22, 5, 5, 0.78), rgba(99, 33, 33, 0.7)),
        var(--game-gate-image, none) center / cover no-repeat;
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      text-align: center;
    }
    .game-demo-gate::before {
      content: "";
      position: absolute;
      inset: 0;
      background: rgba(0, 0, 0, 0.42);
    }
    .game-demo-panel {
      position: relative;
      z-index: 1;
      width: min(100%, 420px);
      padding: 24px;
      border-radius: 8px;
      border: 1px solid rgba(243, 198, 76, 0.38);
      background: rgba(22, 5, 5, 0.82);
      color: #fff;
      box-shadow: 0 16px 46px rgba(0, 0, 0, 0.38);
    }
    .game-demo-panel img {
      width: 132px;
      aspect-ratio: 1 / 1;
      object-fit: cover;
      border-radius: 8px;
      border: 1px solid rgba(243, 198, 76, 0.45);
      margin-bottom: 14px;
      background: #2a0c0c;
    }
    .game-demo-panel h2 {
      margin: 0 0 8px;
      color: #fff;
      font-size: 1.35rem;
      line-height: 1.2;
    }
    .game-demo-panel p {
      margin: 0 0 18px;
      color: #ffd6d6;
      font-size: 0.92rem;
      line-height: 1.45;
    }
    .game-demo-actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
    }
    .game-demo-actions .btn {
      justify-content: center;
      min-height: 44px;
      white-space: normal;
    }
    .game-demo-image-overlay {
      position: absolute;
      right: 16px;
      bottom: 16px;
      z-index: 2;
      width: min(24vw, 180px);
      min-width: 108px;
      aspect-ratio: 1 / 1;
      object-fit: cover;
      border-radius: 8px;
      border: 2px solid rgba(243, 198, 76, 0.7);
      box-shadow: 0 14px 36px rgba(0, 0, 0, 0.45);
      opacity: 0;
      pointer-events: none;
      transform: translateY(8px);
      transition: opacity 180ms ease, transform 180ms ease;
    }
    .game-frame-shell.demo-active .game-demo-image-overlay {
      opacity: 0.88;
      transform: translateY(0);
    }
    .game-frame-shell.demo-overlay-ended .game-demo-image-overlay {
      display: none;
    }
    .game-unavailable {
      padding: 36px 18px;
      color: #ffd6d6;
      text-align: center;
      min-height: 320px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-direction: column;
      gap: 12px;
    }
    .game-stats {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
      margin-top: 18px;
    }
    .game-stat-card {
      min-width: 0;
      padding: 14px 12px;
      border-radius: 8px;
      border: 1px solid #b55454;
      background: rgba(22, 5, 5, 0.72);
      color: #fff;
      text-align: center;
      box-shadow: 0 10px 24px rgba(0, 0, 0, 0.24);
    }
    .game-stat-label {
      display: block;
      margin-bottom: 6px;
      color: #ffd6d6;
      font-size: 0.68rem;
      font-weight: 900;
      letter-spacing: 0.06em;
      line-height: 1.2;
      text-transform: uppercase;
    }
    .game-stat-value {
      display: flex;
      text-transform: uppercase;
      align-items: center;
      justify-content: center;
      gap: 5px;
      min-height: 24px;
      color: #f3c64c;
      font-size: 1rem;
      font-weight: 900;
      line-height: 1.2;
      overflow-wrap: anywhere;
    }
    .game-stat-value.is-green {
      color: #4ade80;
    }
    .game-stat-value.is-orange {
      color: #fb923c;
    }
    .game-stat-value.is-small {
      font-size: 0.84rem;
    }
    @media (min-width: 640px) {
      .game-stats {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
    }
    @media (min-width: 992px) {
      .game-stats {
        grid-template-columns: repeat(6, minmax(0, 1fr));
      }
    }
    .game-overview {
      margin-top: 22px;
      padding: 22px;
      border-radius: 8px;
      border: 1px solid #b55454;
      background: #fff8f0;
      color: #3b0e0e;
    }
    .game-overview h2 {
      margin: 0 0 14px;
      color: #632121;
      font-size: clamp(1.35rem, 2.4vw, 1.8rem);
      line-height: 1.2;
    }
    .game-overview p {
      margin: 0 0 14px;
      color: #4c2020;
      font-size: 1rem;
      line-height: 1.75;
    }
    .game-overview p:last-child {
      margin-bottom: 0;
    }
    .game-real-cta {
      margin-top: 18px;
      padding: 22px;
      border-radius: 8px;
      border: 1px solid rgba(243, 198, 76, 0.48);
      background: #632121;
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
    }
    .game-real-cta-copy {
      display: flex;
      align-items: center;
      gap: 14px;
      min-width: 0;
    }
    .game-real-cta-icon {
      width: 72px;
      height: 72px;
      flex: 0 0 72px;
      object-fit: cover;
      border-radius: 8px;
      border: 1px solid rgba(243, 198, 76, 0.58);
      background: #2a0c0c;
      box-shadow: 0 10px 24px rgba(0, 0, 0, 0.26);
    }
    .game-real-cta h2 {
      margin: 0 0 6px;
      color: #fff;
      font-size: clamp(1.18rem, 2vw, 1.55rem);
      line-height: 1.2;
    }
    .game-real-cta p {
      margin: 0;
      color: #ffd6d6;
      line-height: 1.5;
      font-size: 0.95rem;
    }
    .game-real-cta .btn {
      flex: 0 0 auto;
      justify-content: center;
      min-height: 44px;
    }
    @media (max-width: 760px) {
      .game-play-header {
        align-items: flex-start;
        flex-direction: column;
      }
      .game-frame {
        height: 72vh;
        min-height: 420px;
      }
      .game-demo-actions {
        grid-template-columns: 1fr;
      }
      .game-demo-panel {
        padding: 18px;
      }
      .game-stats {
        gap: 8px;
      }
      .game-stat-card {
        padding: 12px 8px;
      }
      .game-overview {
        padding: 18px;
      }
      .game-real-cta {
        align-items: flex-start;
        flex-direction: column;
        padding: 18px;
      }
      .game-real-cta-copy {
        align-items: flex-start;
      }
      .game-real-cta-icon {
        width: 58px;
        height: 58px;
        flex-basis: 58px;
      }
      .game-real-cta .btn {
        width: 100%;
      }
    }
  </style>
</head>
<body class="dark-jackpot-theme">
  <a href="#main-content" class="skip-link">Skip to main content</a>

  <div class="page-shell" style="background:#993D3D; color:#ffffff;">
    <div class="announcement-bar" style="background: linear-gradient(135deg, #632121, #802e2e); border-bottom: 1px solid #b55454;">
      <div class="marquee-wrapper">
        <div class="marquee-content">
          <p class="winner-alert-msg">Big Winner Alert! Player [user_82**] won PHP 128,500 on Dragon Fortune Slot!</p>
          <p>Official Verified Gperya Portal | Welcome Pack 100% Deposit Bonus up to PHP 3,888! <a href="https://gperya-apk.com/playnow" rel="sponsored nofollow noopener" target="_blank">Join Now &rarr;</a></p>
          <p>Play At our Official Link: <a href="https://gperya-apk.com/playnow" rel="sponsored nofollow noopener" target="_blank">gperya-apk.com/playnow</a></p>
          <p><a href="/is-gperya-legit/">Legitimacy & PAGCOR Safety Check &rarr;</a></p>
        </div>
        <div class="marquee-content">
          <p class="winner-alert-msg">Big Winner Alert! Player [user_82**] won PHP 128,500 on Dragon Fortune Slot!</p>
          <p>Official Verified Gperya Portal | Welcome Pack 100% Deposit Bonus up to PHP 3,888! <a href="https://gperya-apk.com/playnow" rel="sponsored nofollow noopener" target="_blank">Join Now &rarr;</a></p>
          <p>Official Verified Link: <a href="https://gperya-apk.com/playnow" rel="sponsored nofollow noopener" target="_blank">gperya-apk.com/playnow</a></p>
          <p><a href="/is-gperya-legit/">Legitimacy & PAGCOR Safety Check &rarr;</a></p>
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
            <button class="nav-link dropdown-toggle active" aria-expanded="false" aria-haspopup="true">
              Games <span class="dropdown-arrow">▾</span>
            </button>
            <div class="dropdown-menu">
              <a href="/slots/" class="dropdown-item active">Slots</a>
              <a href="/live-casino/" class="dropdown-item">Live Casino</a>
              <a href="/perya-games/" class="dropdown-item">Perya Games</a>
            </div>
          </div>
          <a href="/sports/" class="nav-link">Sportsbook</a>
          <a href="/promotions/" class="nav-link">Promotions</a>
          <a href="/vip/" class="nav-link">VIP</a>
          <a href="/blog/" class="nav-link">Blog & Guides</a>
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
          <a href="/">Home</a>
          <a href="/slots/" class="active">Online Slots</a>
          <a href="/live-casino/">Live Casino Dealers</a>
          <a href="/fishing/">Arcade Fishing Games</a>
          <a href="/perya-games/">Perya Games & Color Game</a>
          <a href="/sports/">Sports Betting</a>
          <a href="/promotions/">Promotions & Bonuses</a>
          <a href="/vip/">VIP Club</a>
          <a href="/blog/">News & Guides</a>
          <a href="/gperya-login/">Account Login Help</a>
          <a href="/gperya-register/">Register Account</a>
          <a href="/gperya-app-download/">Download Android / iOS APK</a>
          <a href="/gperya-kyc-verification/">KYC Verification</a>
          <a href="/gperya-withdrawal/">Fast Cashout Guide</a>
          <a href="/is-gperya-legit/">Safety & Legitimacy Audit</a>
        </nav>
        <div class="drawer-footer">
          <p>Gperya Official Portal. 21+ Play Responsibly.</p>
        </div>
      </div>
    </div>

    <main id="main-content" class="game-play-wrap">
      <section class="game-play-header">
        <div>
          <h1 class="game-play-title"><?= game_h($title) ?></h1>
          <div class="game-play-meta">
            <?php if ($provider !== ''): ?><span class="game-pill"><?= game_h($provider) ?></span><?php endif; ?>
            <?php if ($type !== ''): ?><span class="game-pill"><?= game_h($type) ?></span><?php endif; ?>
            <?php if ($game['rtp'] !== null && $game['rtp'] !== ''): ?><span class="game-pill">RTP <?= game_h($game['rtp']) ?>%</span><?php endif; ?>
            <?php if (($game['volatility'] ?? '') !== ''): ?><span class="game-pill" style="text-transform: capitalize;"><?= game_h($game['volatility']) ?></span><?php endif; ?>
          </div>
        </div>
        <a href="/slots/" class="btn btn-secondary btn-sm">Back to Slots</a>
      </section>

      <section
        class="game-frame-shell"
        aria-label="<?= game_h($title) ?> game frame"
        style="--game-gate-image:url('<?= game_h($overlayImage) ?>');">
        <?php if ($iframeUrl !== ''): ?>
          <iframe
            class="game-frame"
            src="about:blank"
            data-src="<?= game_h($iframeUrl) ?>"
            title="<?= game_h($title) ?>"
            loading="eager"
            allow="fullscreen; autoplay; clipboard-read; clipboard-write"
            allowfullscreen></iframe>
          <img class="game-demo-image-overlay" src="<?= game_h($overlayImage) ?>" alt="" aria-hidden="true" width="180" height="180">
          <div class="game-demo-gate" data-game-gate>
            <div class="game-demo-panel">
              <img src="<?= game_h($overlayImage) ?>" alt="<?= game_h($title) ?>" width="132" height="132">
              <h2><?= game_h($title) ?></h2>
              <p>Choose demo mode to preview the game, or continue to the real play portal.</p>
              <div class="game-demo-actions">
                <button type="button" class="btn btn-gold" data-play-real>Play Real Here</button>
                <button type="button" class="btn btn-secondary" data-play-demo>Play Free Demo</button>
              </div>
            </div>
          </div>
        <?php else: ?>
          <div class="game-unavailable">
            <h2 style="color:#fff; margin:0;">Game URL unavailable</h2>
            <p style="margin:0;">This game does not have an iframe URL saved yet.</p>
          </div>
        <?php endif; ?>
      </section>

      <?php if ($overviewBlocks !== []): ?>
        <?php if ($gameStats !== []): ?>
          <div class="game-stats">
            <?php foreach ($gameStats as $stat): ?>
              <div class="game-stat-card">
                <span class="game-stat-label"><?= game_h($stat['label']) ?></span>
                <div class="game-stat-value <?= game_h($stat['class']) ?>"><?= game_h($stat['value']) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <section class="game-overview" aria-labelledby="game-overview-title">
          <h2 id="game-overview-title"><?= game_h($overviewTitle) ?></h2>
          <?php foreach ($overviewBlocks as $block): ?>
            <p><?= nl2br(game_h($block)) ?></p>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>

      <section class="game-real-cta" aria-labelledby="game-real-cta-title">
        <div class="game-real-cta-copy">
          <img class="game-real-cta-icon" src="<?= game_h($overlayImage) ?>" alt="<?= game_h($title) ?>" width="72" height="72" loading="lazy" decoding="async">
          <div>
            <h2 id="game-real-cta-title">Play <?= game_h($title) ?> for Real</h2>
            <p>Continue to the official play portal when you are ready to switch from demo mode.</p>
          </div>
        </div>
        <a href="https://gperya-apk.com/playnow" class="btn btn-gold" rel="sponsored nofollow noopener" target="_blank">Play Real Game Here</a>
      </section>
    </main>

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
          <span style="color:#fff; font-weight:800;">Core Features</span>
          <a href="/slots/" style="color:#ffd6d6;">Online Slots</a>
          <a href="/live-casino/" style="color:#ffd6d6;">Live Casino</a>
          <a href="/perya-games/" style="color:#ffd6d6;">Perya Games</a>
          <a href="/gperya-app-download/" style="color:#ffd6d6;">Download App Guide</a>
        </div>

        <div class="footer-col">
          <span style="color:#fff; font-weight:800;">Customer Center</span>
          <a href="/gperya-login/" style="color:#ffd6d6;">Gperya Login</a>
          <a href="/gperya-register/" style="color:#ffd6d6;">Gperya Register</a>
          <a href="/customer-support/" style="color:#ffd6d6;">24/7 Customer Support</a>
          <a href="/gperya-withdrawal/" style="color:#ffd6d6;">Withdrawal Help</a>
          <a href="/is-gperya-legit/" style="color:#ffd6d6;">Legitimacy Audit</a>
        </div>

        <div class="footer-col">
          <span style="color:#fff; font-weight:800;">Safety &amp; Legal</span>
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

    <nav class="ace-bottom-nav">
      <a href="/" class="ace-nav-item">
        <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
        <span>Home</span>
      </a>
      <a href="/slots/" class="ace-nav-item active">
        <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 14l-5-5 1.41-1.41L12 14.17l7.59-7.59L21 8l-9 9z"/></svg>
        <span>Slots</span>
      </a>
      <a href="https://gperya-apk.com/playnow" class="ace-nav-center-play" rel="sponsored nofollow noopener" target="_blank" aria-label="Play Now">
        ▶
      </a>
      <a href="/sports/" class="ace-nav-item">
        <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93z"/></svg>
        <span>Sports</span>
      </a>
      <a href="/gperya-login/" class="ace-nav-item">
        <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 10.48 2 16c0 3.31 2.69 6 6 6s6-2.69 6-6c0-5.52-4.48-14-10-14zm0 18c-2.21 0-4-1.79-4-4 0-3.31 2.69-8 4-10.22 1.31 2.22 4 6.91 4 10.22 0 2.21-1.79 4-4 4z"/></svg>
        <span>Login</span>
      </a>
    </nav>
  </div>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      document.querySelectorAll('.game-frame-shell').forEach(function (shell) {
        const frame = shell.querySelector('.game-frame');
        const gate = shell.querySelector('[data-game-gate]');
        const playDemo = shell.querySelector('[data-play-demo]');
        const playReal = shell.querySelector('[data-play-real]');

        if (!frame || !gate || !playDemo || !playReal) {
          return;
        }

        playDemo.addEventListener('click', function () {
          const src = frame.getAttribute('data-src');
          if (src && frame.getAttribute('src') !== src) {
            frame.setAttribute('src', src);
          }

          gate.remove();
          shell.classList.add('demo-active');
          window.setTimeout(function () {
            shell.classList.add('demo-overlay-ended');
          }, 120000);
        });

        playReal.addEventListener('click', function () {
          window.location.href = 'https://gperya-apk.com/playnow';
        });
      });
    });
  </script>
  <script src="/assets/js/config.min.js" defer></script>
  <script src="/assets/js/main.min.js" defer></script>
</body>
</html>
