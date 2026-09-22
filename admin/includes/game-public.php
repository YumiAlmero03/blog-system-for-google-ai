<?php
declare(strict_types=1);
require_once __DIR__ . '/blog-storage.php';
require_once __DIR__ . '/game-restrictions.php';
const SLOT_IFRAME_TOKEN = 'KljjshkJEwm9XlVUTiGCzsyYkQw4mG22pOKNzS3enaABIHoTdj';

function slot_list_json_array(mixed $value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter($decoded, static fn (mixed $item): bool => is_scalar($item) && trim((string) $item) !== ''));
}

function slot_list_number(mixed $value): null|int|float
{
    if ($value === null || $value === '') {
        return null;
    }

    $number = (float) $value;
    return floor($number) === $number ? (int) $number : $number;
}

function slot_list_iframe_url(mixed $value): string
{
    if (!is_string($value) || trim($value) === '') {
        return '';
    }

    $url = trim($value);
    if (preg_match('/(?:[?&])token=/', $url) === 1) {
        return $url;
    }

    $fragment = '';
    $hashPosition = strpos($url, '#');
    if ($hashPosition !== false) {
        $fragment = substr($url, $hashPosition);
        $url = substr($url, 0, $hashPosition);
    }

    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . 'token=' . rawurlencode(SLOT_IFRAME_TOKEN) . $fragment;
}

function slot_list_thumbnail_url(string $thumbnail): string
{
    $thumbnail = trim($thumbnail);
    // Local game uploads work on the current host without a configured site domain.
    if (preg_match('~^/?uploads/games/[a-zA-Z0-9._-]+\.(?:jpe?g|png|webp|avif)$~iD', $thumbnail)) {
        return '/' . ltrim($thumbnail, '/');
    }

    return $thumbnail !== '' ? public_detail_url($thumbnail) : '';
}

function slot_list_item(array $slot): array
{
    $slug = (string) ($slot['slug'] ?? '');

    return [
        'id' => (int) ($slot['id'] ?? 0),
        'apiId' => (int) ($slot['api_id'] ?? 0),
        'name' => (string) ($slot['name'] ?? ''),
        'slug' => $slug,
        'gameUrl' => $slug !== '' ? '/game/' . rawurlencode($slug) . '/' : '',
        'iframeUrl' => slot_list_iframe_url($slot['url'] ?? ''),
        'thumbnail' => slot_list_thumbnail_url((string) ($slot['thumb'] ?? '')),
        'shortDescription' => (string) ($slot['short_description'] ?? ''),
        'longDescription' => (string) ($slot['long_description'] ?? ''),
        'provider' => [
            'name' => (string) ($slot['provider'] ?? ''),
            'slug' => (string) ($slot['provider_slug'] ?? ''),
        ],
        'type' => [
            'name' => (string) ($slot['type'] ?? ''),
            'slug' => (string) ($slot['type_slug'] ?? ''),
        ],
        'themes' => slot_list_json_array($slot['themes'] ?? ''),
        'rtp' => slot_list_number($slot['rtp'] ?? null),
        'volatility' => (string) ($slot['volatility'] ?? ''),
        'featured' => (int) ($slot['featured'] ?? 0) === 1,
        'progressive' => (int) ($slot['progressive'] ?? 0) === 1,
        'published' => (int) ($slot['published'] ?? 0) === 1,
        'upcoming' => (int) ($slot['upcoming'] ?? 0) === 1,
        'release' => (string) ($slot['release'] ?? ''),
        'paylines' => (string) ($slot['paylines'] ?? ''),
        'minBet' => slot_list_number($slot['min_bet'] ?? null),
        'maxBet' => slot_list_number($slot['max_bet'] ?? null),
        'maxWinPerSpin' => slot_list_number($slot['max_win_per_spin'] ?? null),
        'updatedAt' => (int) ($slot['updated_at'] ?? 0),
    ];
}

function public_detail_slug(mixed $slug): ?string
{
    return is_string($slug) && $slug !== '' && normalize_slug($slug) === $slug ? $slug : null;
}

function public_game_find(string $slug): ?array
{
    if (public_detail_slug($slug) === null) return null;
    $allowed = game_public_eligibility_sql();
    $stmt = blogs_pdo()->prepare("SELECT * FROM games WHERE slug=:slug AND ($allowed) ORDER BY updated_at DESC,id DESC LIMIT 1");
    $stmt->execute([':slug'=>$slug]);
    return $stmt->fetch() ?: null;
}

function public_game_payload(array $row): array
{
    $game = slot_list_item($row);
    foreach (['gameUrl','thumbnail','iframeUrl'] as $field) $game[$field] = public_detail_url($game[$field]);
    return $game;
}

function public_detail_url(string $url): string
{
    $url = public_url($url);
    if (str_starts_with($url,'//')) $url = 'https:' . $url;
    return preg_match('~^https?://~i',$url) ? $url : '';
}
