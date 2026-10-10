<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../../includes/blog-storage.php';
require_once __DIR__ . '/../includes/game-rankings.php';

try {
    $token = env_value('SLOTSLAUNCH_API_TOKEN') ?: env_value('SLOTSLAUNCH_TOKEN') ?: env_value('GAMES_API_TOKEN');
    if (!$token) throw new RuntimeException('Set SLOTSLAUNCH_API_TOKEN before syncing rankings.');
    $origin = env_value('SLOTSLAUNCH_ORIGIN') ?: site_base_url();
    if (!filter_var($origin, FILTER_VALIDATE_URL)) throw new RuntimeException('Set SLOTSLAUNCH_ORIGIN to your registered website origin.');
    $headers = ['Accept: application/json', 'Origin: ' . $origin];
    $secret = env_value('SLOTSLAUNCH_API_SECRET');
    if ($secret) {
        $timestamp = time();
        $headers[] = 'X-SL-Timestamp: ' . $timestamp;
        $headers[] = 'X-SL-Signature: ' . hash_hmac('sha256', $timestamp . "\nGET\n/api/rankings", $secret);
    }
    $curl = curl_init('https://slotslaunch.com/api/rankings?token=' . rawurlencode($token));
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>45, CURLOPT_FOLLOWLOCATION=>false]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($body === false || $status !== 200) throw new RuntimeException('Rankings fetch failed (HTTP ' . $status . '); previous snapshot preserved.');
    $rows = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($rows)) throw new RuntimeException('Unexpected rankings response.');
    echo 'Synced ' . game_rankings_save($rows) . " SlotsLaunch play counts.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
