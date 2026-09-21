<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

require_once __DIR__ . '/../admin/includes/chat-session-storage.php';

// Admin extensions use the existing session files and existing authentication.
$adminAction = $_GET['admin_action'] ?? $_POST['admin_action'] ?? null;
if ($adminAction !== null) {
    require_once __DIR__ . '/../admin/includes/auth.php';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    require_auth();
    try {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if (!(($adminAction === 'messages' && $method === 'GET') || ($adminAction === 'reply' && $method === 'POST'))) {
            http_response_code(405);
            echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);
            exit;
        }
        if ($method === 'POST') require_valid_csrf();
        $input = $method === 'POST' ? $_POST : $_GET;
        $sessionId = $input['session_id'] ?? '';
        if (!is_string($sessionId) || !chat_is_valid_session_id($sessionId)) {
            throw new InvalidArgumentException('Invalid session ID.');
        }
        if ($adminAction === 'reply') {
            $text = $input['message'] ?? '';
            if (!is_string($text) || trim($text) === '' || strlen($text) > 5000) {
                throw new InvalidArgumentException('Enter a message of up to 5,000 bytes.');
            }
            chat_admin_reply($sessionId, $text);
            echo json_encode(['ok'=>true]);
        } else {
            $data = chat_read_session($sessionId);
            if ($data === null) {
                http_response_code(404);
                echo json_encode(['ok'=>false,'error'=>'Chat session not found.']);
                exit;
            }
            $version = hash('sha256', json_encode($data['messages'] ?? []));
            $unchanged = is_string($input['version'] ?? null) && hash_equals($version,$input['version']);
            echo json_encode(['ok'=>true,'version'=>$version,'unchanged'=>$unchanged,
                'messages'=>$unchanged ? [] : ($data['messages'] ?? [])], JSON_INVALID_UTF8_SUBSTITUTE);
        }
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    } catch (Throwable $e) {
        error_log('Admin chat session operation failed.');
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Chat session is unavailable.']);
    }
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);
    exit;
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

try {
    $messageCount = chat_update_session($sessionId, static function (array $existingData) use ($sessionId,$name,$contact,$pageUrl,$messages): array {
        $now = date('c');
        // Public clients submit snapshots. Keep server-authored replies when an older
        // browser snapshot is saved, without changing the submitted user/bot history.
        $merged = chat_preserve_admin_replies($messages, $existingData['messages'] ?? []);
        return array_replace($existingData, [
            'sessionId'=>$sessionId,
            'name'=>$name !== '' ? $name : ($existingData['name'] ?? 'Guest'),
            'contact'=>$contact !== '' ? $contact : ($existingData['contact'] ?? ''),
            'pageUrl'=>$pageUrl,
            'startedAt'=>$existingData['startedAt'] ?? $now,
            'updatedAt'=>$now,
            'ip'=>$existingData['ip'] ?? chat_client_ip(),
            'userAgent'=>chat_clean_string($_SERVER['HTTP_USER_AGENT'] ?? ($existingData['userAgent'] ?? ''),300),
            'messages'=>$merged,
        ]);
    });
} catch (Throwable $e) {
    error_log('Chat session save failed.');
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Unable to save chat session.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'sessionId' => $sessionId,
    'path' => 'chats/' . $sessionId,
    'messageCount' => $messageCount,
], JSON_UNESCAPED_SLASHES);
