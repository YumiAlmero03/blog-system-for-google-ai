<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.'], JSON_UNESCAPED_SLASHES);
    exit;
}

function chat_clean_string(mixed $value, int $maxLength): string
{
    $value = trim((string) $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    $value = str_replace(["\r", "\n"], ' ', $value);

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }

    return substr($value, 0, $maxLength);
}

function chat_filename_part(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value !== '' ? substr($value, 0, 60) : 'guest';
}

function chat_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $header) {
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

function chat_dir(): string
{
    return dirname(__DIR__) . '/chats';
}

function chat_is_valid_session_id(string $sessionId): bool
{
    return (bool) preg_match('/^[a-z0-9-]+_\d{4}-\d{2}-\d{2}_\d{3}\.json$/', $sessionId);
}

function chat_next_session_id(string $name): string
{
    $date = date('Y-m-d');
    $prefix = chat_filename_part($name) . '_' . $date . '_';
    $dir = chat_dir();

    for ($i = 1; $i <= 999; $i++) {
        $candidate = $prefix . str_pad((string) $i, 3, '0', STR_PAD_LEFT) . '.json';
        if (!is_file($dir . '/' . $candidate)) {
            return $candidate;
        }
    }

    return $prefix . uniqid('', false) . '.json';
}

function chat_normalize_messages(mixed $messages): array
{
    if (!is_array($messages)) {
        return [];
    }

    $normalized = [];
    foreach (array_slice($messages, -200) as $message) {
        if (!is_array($message)) {
            continue;
        }

        $text = chat_clean_string($message['text'] ?? '', 5000);
        if ($text === '') {
            continue;
        }

        $sender = chat_clean_string($message['sender'] ?? 'user', 30);
        if (!in_array($sender, ['user', 'bot', 'agent'], true)) {
            $sender = 'user';
        }

        $normalized[] = [
            'sender' => $sender,
            'text' => $text,
            'time' => chat_clean_string($message['time'] ?? '', 60),
            'type' => chat_clean_string($message['type'] ?? 'chat', 40),
        ];
    }

    return $normalized;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw !== false ? $raw : '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$name = chat_clean_string($payload['name'] ?? 'Guest', 120);
$contact = chat_clean_string($payload['contact'] ?? '', 160);
$pageUrl = chat_clean_string($payload['page_url'] ?? ($_SERVER['HTTP_REFERER'] ?? ''), 500);
$messages = chat_normalize_messages($payload['messages'] ?? []);

if ($messages === []) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'No chat messages to save.'], JSON_UNESCAPED_SLASHES);
    exit;
}

$dir = chat_dir();
if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to create chats directory.'], JSON_UNESCAPED_SLASHES);
    exit;
}

$denyFile = $dir . '/.htaccess';
if (!is_file($denyFile)) {
    @file_put_contents($denyFile, "Require all denied\n", LOCK_EX);
}

$sessionId = chat_clean_string($payload['session_id'] ?? '', 120);
if ($sessionId === '' || !chat_is_valid_session_id($sessionId)) {
    $sessionId = chat_next_session_id($name);
}

$path = $dir . '/' . $sessionId;
$now = date('c');
$existingData = [];
if (is_file($path)) {
    $existingRaw = @file_get_contents($path);
    $decoded = json_decode(is_string($existingRaw) ? $existingRaw : '', true);
    if (is_array($decoded)) {
        $existingData = $decoded;
    }
}

$data = [
    'sessionId' => $sessionId,
    'name' => $name !== '' ? $name : ($existingData['name'] ?? 'Guest'),
    'contact' => $contact !== '' ? $contact : ($existingData['contact'] ?? ''),
    'pageUrl' => $pageUrl,
    'startedAt' => $existingData['startedAt'] ?? $now,
    'updatedAt' => $now,
    'ip' => $existingData['ip'] ?? chat_client_ip(),
    'userAgent' => chat_clean_string($_SERVER['HTTP_USER_AGENT'] ?? ($existingData['userAgent'] ?? ''), 300),
    'messages' => $messages,
];

if (@file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to save chat session.'], JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    'ok' => true,
    'sessionId' => $sessionId,
    'path' => 'chats/' . $sessionId,
    'messageCount' => count($messages),
], JSON_UNESCAPED_SLASHES);
