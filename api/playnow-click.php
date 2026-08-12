<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/env.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

function playnow_storage_dir(): string
{
    $storageDir = env_value('APP_STORAGE_DIR');
    if (!is_string($storageDir) || $storageDir === '' || $storageDir === '/absolute/path/outside/public/storage') {
        $storageDir = dirname(__DIR__) . '/storage';
    }

    return rtrim($storageDir, '/\\');
}

function playnow_client_ip(): string
{
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR',
    ];

    foreach ($headers as $header) {
        $value = $_SERVER[$header] ?? '';
        if (!is_string($value) || $value === '') {
            continue;
        }
        $ip = trim(explode(',', $value)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return 'unknown';
}

function playnow_clean_string(mixed $value, int $maxLength): string
{
    $value = trim((string) $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }

    return substr($value, 0, $maxLength);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw !== false ? $raw : '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$pageUrl = playnow_clean_string($payload['pageUrl'] ?? '', 300);
$pagePath = playnow_clean_string($payload['pagePath'] ?? '', 180);
$pageTitle = playnow_clean_string($payload['pageTitle'] ?? '', 180);
$referrer = playnow_clean_string($payload['referrer'] ?? '', 300);
$targetUrl = playnow_clean_string($payload['targetUrl'] ?? '', 300);
$buttonText = playnow_clean_string($payload['buttonText'] ?? '', 120);
$buttonClass = playnow_clean_string($payload['buttonClass'] ?? '', 220);
$buttonId = playnow_clean_string($payload['buttonId'] ?? '', 120);
$buttonName = playnow_clean_string($payload['buttonName'] ?? '', 120);
$buttonTag = playnow_clean_string($payload['buttonTag'] ?? '', 40);
$section = playnow_clean_string($payload['section'] ?? '', 140);
$selector = playnow_clean_string($payload['selector'] ?? '', 220);
$location = playnow_clean_string($payload['location'] ?? '', 220);
$path = parse_url($pageUrl, PHP_URL_PATH);
$path = is_string($path) && $path !== '' ? $path : ($_SERVER['HTTP_REFERER'] ?? 'unknown');
$pagePath = $pagePath !== '' ? $pagePath : playnow_clean_string((string) $path, 180);

if ($location === '') {
    $location = $pagePath . ' | ' . ($section !== '' ? $section : 'page') . ' | ' . ($buttonText !== '' ? $buttonText : 'playnow-link');
}

$buttonKey = $buttonText !== '' ? $buttonText : ($buttonId !== '' ? '#' . $buttonId : 'playnow-link');

$event = [
    'time' => gmdate('c'),
    'timestamp' => time(),
    'ip' => playnow_client_ip(),
    'pageUrl' => $pageUrl,
    'pagePath' => $pagePath,
    'pageTitle' => $pageTitle,
    'referrer' => $referrer,
    'targetUrl' => $targetUrl,
    'buttonText' => $buttonText,
    'buttonClass' => $buttonClass,
    'buttonId' => $buttonId,
    'buttonName' => $buttonName,
    'buttonTag' => $buttonTag,
    'buttonKey' => $buttonKey,
    'section' => $section,
    'selector' => $selector,
    'location' => $location,
    'x' => isset($payload['x']) ? (int) $payload['x'] : null,
    'y' => isset($payload['y']) ? (int) $payload['y'] : null,
    'userAgent' => playnow_clean_string($_SERVER['HTTP_USER_AGENT'] ?? '', 300),
];

$storageDir = playnow_storage_dir();
if (!is_dir($storageDir) && !@mkdir($storageDir, 0700, true) && !is_dir($storageDir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Click storage is unavailable.']);
    exit;
}

$path = $storageDir . '/playnow-clicks.json';
$handle = @fopen($path, 'c+');
if ($handle === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Click log is unavailable.']);
    exit;
}

try {
    flock($handle, LOCK_EX);
    $existing = stream_get_contents($handle);
    $data = json_decode($existing !== false ? $existing : '', true);
    if (!is_array($data)) {
        $data = [];
    }

    $data['totalClicks'] = (int) ($data['totalClicks'] ?? 0) + 1;
    $data['updatedAt'] = $event['time'];
    $data['byLocation'] ??= [];
    $data['byIp'] ??= [];
    $data['byPage'] ??= [];
    $data['byButton'] ??= [];
    $data['byTarget'] ??= [];
    $data['recent'] ??= [];

    $data['byLocation'][$location] = (int) ($data['byLocation'][$location] ?? 0) + 1;
    $data['byIp'][$event['ip']] = (int) ($data['byIp'][$event['ip']] ?? 0) + 1;
    $data['byPage'][$pagePath] = (int) ($data['byPage'][$pagePath] ?? 0) + 1;
    $data['byButton'][$buttonKey] = (int) ($data['byButton'][$buttonKey] ?? 0) + 1;
    $data['byTarget'][$targetUrl] = (int) ($data['byTarget'][$targetUrl] ?? 0) + 1;
    $data['recent'][] = $event;
    if (count($data['recent']) > 2000) {
        $data['recent'] = array_slice($data['recent'], -2000);
    }

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
} finally {
    fclose($handle);
}

echo json_encode([
    'ok' => true,
    'totalClicks' => $data['totalClicks'],
    'locationClicks' => $data['byLocation'][$location],
]);
