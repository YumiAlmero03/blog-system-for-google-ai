<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/env.php';
require_once __DIR__ . '/../admin/includes/slotslaunch-client.php';

header('Content-Type: application/json; charset=utf-8');
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if (preg_match('~^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?$~i', $origin) === 1) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

function slotlaunch_embed_json(array $payload, int $status): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$rawUrl = $_GET['url'] ?? null;
$rawId = $_GET['id'] ?? $_GET['game_id'] ?? null;
$gameId = 0;

if (is_numeric($rawId) && (int) $rawId > 0) {
    $gameId = (int) $rawId;
} elseif (is_string($rawUrl) && trim($rawUrl) !== '') {
    $parts = parse_url(trim($rawUrl));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');
    if (($parts['scheme'] ?? '') === 'https' && $host === 'slotslaunch.com' && preg_match('~^/iframe/(\d+)(?:/)?$~', $path, $matches) === 1) {
        $gameId = (int) $matches[1];
    }
}

if ($gameId <= 0) {
    slotlaunch_embed_json(['error' => 'A valid SlotLaunch iframe URL or game ID is required.'], 400);
}

try {
    $apiKey = trim((string) (env_value('SLOTSLAUNCH_API_KEY') ?: env_value('SLOTSLAUNCH_API_TOKEN')));
    $apiSecret = trim((string) env_value('SLOTSLAUNCH_API_SECRET'));
    $siteDomain = trim((string) env_value('SLOTSLAUNCH_SITE_DOMAIN'));
    $ttl = max(60, min(86400, (int) (env_value('SLOTSLAUNCH_IFRAME_TTL') ?: 3600)));
    if ($apiKey === '' || $apiSecret === '' || $siteDomain === '') throw new RuntimeException('SlotLaunch signing credentials are not configured.');
    $client = new \SlotsLaunch\Client($apiKey, $apiSecret, $siteDomain, 'https://slotslaunch.com', $ttl);
    slotlaunch_embed_json(['url' => $client->iframeUrl($gameId, $ttl)], 200);
} catch (Throwable $error) {
    error_log('SlotLaunch embed signing failed: ' . $error->getMessage());
    slotlaunch_embed_json(['error' => 'Unable to sign the SlotLaunch embed URL.'], 500);
}
