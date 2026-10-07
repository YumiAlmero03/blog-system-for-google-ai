<?php
declare(strict_types=1);
if (!is_dir(__DIR__ . '/../admin/plugin/games')) { http_response_code(404); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'Plugin unavailable.']); exit; }

require_once __DIR__ . '/../admin/includes/public-detail.php';
require_once __DIR__ . '/../admin/includes/api-rate-limit.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    detail_json(['ok'=>false,'error'=>'Method not allowed.'],405);
}
$input=json_decode(file_get_contents('php://input') ?: '',true);
if (!is_array($input)) $input=$_POST;
$slug=public_detail_slug($input['slug'] ?? null);
$visitor=$input['visitor_id'] ?? null;
if ($slug===null || !is_string($visitor) || !preg_match('/^[a-zA-Z0-9_-]{32,80}$/D',$visitor)) {
    detail_json(['ok'=>false,'error'=>'A valid slug and visitor_id (32–80 letters, digits, hyphens or underscores) are required.'],422);
}
try {
    if (api_rate_limit_exceeded(hash('sha256','game-play|'.($_SERVER['REMOTE_ADDR'] ?? 'unknown')),60)) {
        header('Retry-After: 60');
        detail_json(['ok'=>false,'error'=>'Too many requests.'],429);
    }
    $game=public_game_find($slug);
    if (!$game) detail_json(['ok'=>false,'error'=>'Game not found.'],404);
    $pdo=blogs_pdo();
    $stmt=$pdo->prepare('INSERT OR IGNORE INTO game_players(game_id,visitor_hash) VALUES(?,?)');
    $stmt->execute([(int)$game['id'],hash('sha256',$visitor)]);
    $counted=$stmt->rowCount()===1;
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM game_players WHERE game_id=?');
    $stmt->execute([(int)$game['id']]);
    detail_json(['ok'=>true,'counted'=>$counted,'uniquePlayers'=>(int)$stmt->fetchColumn()]);
} catch (Throwable $error) {
    error_log('Game play counter unavailable.');
    detail_json(['ok'=>false,'error'=>'Play counter unavailable.'],500);
}
