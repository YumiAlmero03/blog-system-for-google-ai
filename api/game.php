<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/includes/public-detail.php';
$slug = detail_request_slug();
try {
    $game = public_game_find($slug);
    if (!$game) detail_json(['ok'=>false,'error'=>'Game not found.'],404);
    detail_json(['ok'=>true,'game'=>public_game_payload($game)]);
} catch (Throwable $error) {
    error_log('Public game detail unavailable.');
    detail_json(['ok'=>false,'error'=>'Game unavailable.'],500);
}
