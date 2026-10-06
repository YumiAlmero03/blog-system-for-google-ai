<?php
require_once __DIR__ . '/blog-storage.php';
function public_detail_slug(mixed $slug): ?string
{
    return is_string($slug) && $slug !== '' && normalize_slug($slug) === $slug ? $slug : null;
}

function public_detail_url(string $url): string
{
    $url = public_url($url);
    if (str_starts_with($url,'//')) $url = 'https:' . $url;
    return preg_match('~^https?://~i',$url) ? $url : '';
}
if (is_file(__DIR__ . '/../plugin/games/includes/game-public.php')) {
    require_once __DIR__ . '/../plugin/games/includes/game-public.php';
} else {
    function public_game_find(string $slug): ?array { return null; }
}
