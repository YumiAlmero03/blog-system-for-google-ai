<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

require_once __DIR__ . '/../admin/includes/chat-session-storage.php';
require_once __DIR__ . '/../admin/includes/chat-auto-responses.php';
require_once __DIR__ . '/../admin/includes/chat-realtime.php';

// Admin extensions use the existing session files and existing authentication.
$adminAction = $_GET['admin_action'] ?? $_POST['admin_action'] ?? null;
if ($adminAction !== null) {
    require_once __DIR__ . '/../admin/includes/auth.php';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    require_auth();
    try {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if (!((in_array($adminAction,['messages','player_presence'],true) && $method === 'GET') || (in_array($adminAction,['reply','presence'],true) && $method === 'POST'))) {
            http_response_code(405);
            echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);
            exit;
        }
        if ($method === 'POST') require_valid_csrf();
        $input = $method === 'POST' ? $_POST : $_GET;
        if ($adminAction === 'player_presence') {
            $ids=$input['session_ids'] ?? [];
            if (!is_array($ids) || count($ids)>25) throw new InvalidArgumentException('Provide up to 25 session IDs.');
            $players=[];
            foreach ($ids as $id) {
                if (!is_string($id) || !chat_is_valid_session_id($id)) throw new InvalidArgumentException('Invalid session ID.');
                $row=chat_read_session($id);
                $players[$id]=$row===null ? null : chat_player_presence($row);
            }
            echo json_encode(['ok'=>true,'players'=>(object)$players]); exit;
        }
        if ($adminAction === 'presence') {
            $client=$input['client_id'] ?? '';
            if (!is_string($client) || !preg_match('/^[a-zA-Z0-9_-]{8,80}$/D',$client)) throw new InvalidArgumentException('Invalid client ID.');
            $key=hash('sha256',($_SESSION['auth_binding'] ?? session_id()).'|'.$client);
            $online=chat_agent_presence($key,($input['online'] ?? '')==='1');
            echo json_encode(['ok'=>true,'agentOnline'=>$online]); exit;
        }
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
                'playerOnline'=>chat_player_presence($data),
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

if (isset($payload['action'])) {
    try { echo json_encode(chat_realtime_action($payload),JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    catch (DomainException $e) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    catch (InvalidArgumentException $e) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    catch (OverflowException $e) { http_response_code(429); header('Retry-After: 60'); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    catch (Throwable $e) { http_response_code(500); error_log('Realtime chat operation failed.'); echo json_encode(['ok'=>false,'error'=>'Chat is unavailable.']); }
    exit;
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
    $rules = chat_auto_rules();
    $reply = null;
    $messageCount = chat_update_session($sessionId, static function (array $existingData) use ($sessionId,$name,$contact,$pageUrl,$messages,$rules,&$reply): array {
        if (!empty($existingData['visitorTokenHash'])) throw new DomainException('Use token-authenticated chat actions for this session.');
        $now = date('c');
        // Public clients submit snapshots. Preserve server-authored admin and bot
        // replies when an older browser snapshot is saved.
        $merged = chat_preserve_admin_replies($messages, $existingData['messages'] ?? []);
        [$reply, $replyKey] = chat_auto_reply($messages, $existingData, $rules);
        if ($reply !== null && !in_array($reply['autoReplyId'],array_column($merged,'autoReplyId'),true)) {
            $merged[] = $reply;
            $merged = array_slice($merged,-200);
        }
        return array_replace($existingData, [
            'autoReplyRuleIds'=>array_values(array_unique(array_merge(chat_auto_used_rule_ids($existingData), $reply !== null ? [$reply['ruleId']] : []))),
            'autoReplyKey'=>$replyKey ?? ($existingData['autoReplyKey'] ?? null),
            'autoReply'=>$replyKey !== null ? $reply : ($existingData['autoReply'] ?? null),
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
} catch (DomainException $e) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    exit;
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
    'reply' => $reply,
], JSON_UNESCAPED_SLASHES);
