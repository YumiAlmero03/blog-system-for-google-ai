<?php
// Serve the existing React application with API-backed metadata for direct game URLs.
// This template does not render or alter the game UI.
declare(strict_types=1);

function gameSocialImage(array $game, string $siteUrl): string {
    foreach (['thumbnail', 'image', 'cover'] as $field) {
        $value = trim(is_string($game[$field] ?? null) ? $game[$field] : '');
        if ($value === '' || preg_match('/^(undefined|null)$/i', $value) || str_starts_with($value, '/src/')) continue;
        if (str_starts_with($value, '//')) $value = 'https:' . $value;
        elseif (!preg_match('~^[a-z][a-z0-9+.-]*:~i', $value)) $value = $siteUrl . '/' . ltrim($value, '/');
        $value = preg_replace('~^http://~i', 'https://', $value);
        $value = str_replace(' ', '%20', $value);
        if (filter_var($value, FILTER_VALIDATE_URL) && parse_url($value, PHP_URL_SCHEME) === 'https') return $value;
    }
    return $siteUrl . '/assets/images/free-online-games-logo.webp';
}

function gameSeoHtml(string $html, array $game, string $siteUrl, string $slug): string {
    $image = gameSocialImage($game, $siteUrl);
    $name = trim((string)($game['name'] ?? 'Game'));
    $canonical = $siteUrl . '/game/' . rawurlencode($slug) . '/';
    $tags = [
        ['property', 'og:image', $image],
        ['property', 'og:image:secure_url', $image],
        ['property', 'og:image:alt', $name . ' game thumbnail'],
        ['name', 'twitter:image', $image],
        ['name', 'twitter:image:alt', $name . ' game thumbnail'],
        ['name', 'twitter:card', 'summary_large_image'],
        ['property', 'og:url', $canonical],
        ['name', 'twitter:url', $canonical],
    ];
    foreach ($tags as [$attribute, $key, $value]) {
        $html = preg_replace('~<meta\b[^>]*\b' . $attribute . '=["\']' . preg_quote($key, '~') . '["\'][^>]*>~i', '', $html);
        $tag = '<meta ' . $attribute . '="' . $key . '" content="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" />';
        $html = str_replace('</head>', $tag . "\n</head>", $html);
    }
    $html = preg_replace('~<meta\b[^>]*name=["\']twitter:image:src["\'][^>]*>~i', '', $html);
    $html = preg_replace('~<link\b[^>]*rel=["\']canonical["\'][^>]*>~i', '', $html);
    return str_replace('</head>', '<link rel="canonical" href="' . htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') . '" />' . "\n</head>", $html);
}

// Allow the pure metadata functions to be exercised without making API requests.
if (defined('GAME_SEO_TEST')) return;
$config = json_decode((string)file_get_contents(__DIR__ . '/game-seo-config.json'), true);
$siteUrl = rtrim($config['siteUrl'], '/');
$html = (string)file_get_contents(__DIR__ . '/game-loading/index.html');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (!preg_match('~^/games?/([^/]+)/?$~', $path ?? '', $match)) {
    http_response_code(404);
    exit;
}
$slug = rawurldecode($match[1]);
$base = rtrim(getenv('PUBLIC_API_BASE') ?: $siteUrl . '/api', '/');
$query = http_build_query(['count' => 100, 'page' => 1, 'search' => $slug, 'published' => 1]);
$context = stream_context_create(['http' => ['timeout' => 10, 'header' => "Accept: application/json\r\n"]]);
$data = json_decode((string)@file_get_contents($base . '/slot-list.php?' . $query, false, $context), true);
$game = [];
if (($data['ok'] ?? false) && is_array($data['slots'] ?? null)) {
    foreach ($data['slots'] as $candidate) {
        if (strcasecmp((string)($candidate['slug'] ?? ''), $slug) === 0) {
            $game = $candidate;
            break;
        }
    }
}
header('Content-Type: text/html; charset=UTF-8');
echo gameSeoHtml($html, $game, $siteUrl, $slug);
