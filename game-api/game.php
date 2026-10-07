<?php
declare(strict_types=1);
if (!is_dir(__DIR__ . '/../admin/plugin/games')) { http_response_code(404); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'Plugin unavailable.']); exit; }

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
