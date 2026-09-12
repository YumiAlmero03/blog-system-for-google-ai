<?php
declare(strict_types=1);

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
    return (bool) preg_match('/^[a-z0-9-]+_\d{4}-\d{2}-\d{2}_(?:\d{3}|[a-f0-9]{13})\.json$/D', $sessionId);
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

/** Read the same snapshot written by chat-session.php without observing a partial write. */
function chat_read_session(string $sessionId): ?array
{
    if (!chat_is_valid_session_id($sessionId)) return null;
    $path = chat_dir() . '/' . $sessionId;
    if (!is_file($path) || is_link($path)) return null;
    $handle = fopen($path,'r');
    if ($handle === false) throw new RuntimeException('Cannot read chat.');
    try {
        if (!flock($handle,LOCK_SH)) throw new RuntimeException('Cannot lock chat.');
        $data = json_decode(stream_get_contents($handle),true);
        return is_array($data) ? $data : null;
    } finally { fclose($handle); }
}

function chat_update_session(string $sessionId, callable $update, bool $existingOnly = false): int
{
    if (!chat_is_valid_session_id($sessionId)) throw new InvalidArgumentException('Invalid session ID.');
    $path = chat_dir() . '/' . $sessionId;
    if (is_link($path)) throw new RuntimeException('Invalid chat file.');
    $handle = fopen($path,$existingOnly ? 'r+' : 'c+');
    if ($handle === false) throw new RuntimeException('Cannot open chat.');
    try {
        if (!flock($handle,LOCK_EX)) throw new RuntimeException('Cannot lock chat.');
        $raw = stream_get_contents($handle);
        $data = $raw === '' ? [] : json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('Invalid chat data.');
        $data = $update($data);
        $json = json_encode($data,JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        rewind($handle);
        if (fwrite($handle,$json) !== strlen($json) || !ftruncate($handle,strlen($json)) || !fflush($handle)) {
            throw new RuntimeException('Cannot save chat.');
        }
        return count($data['messages'] ?? []);
    } finally { fclose($handle); }
}

function chat_preserve_admin_replies(array $incoming, array $existing): array
{
    foreach ($existing as $message) {
        if (!is_array($message) || empty($message['adminReplyId'])) continue;
        $found = false;
        foreach ($incoming as &$candidate) {
            if (($candidate['sender'] ?? '') === 'agent' && ($candidate['text'] ?? '') === ($message['text'] ?? '')
                && ($candidate['time'] ?? '') === ($message['time'] ?? '')) {
                $candidate['adminReplyId'] = $message['adminReplyId'];
                $found = true;
                break;
            }
        }
        unset($candidate);
        if (!$found) {
            // Keep the reply near its original position in the snapshot.
            $position = count($incoming);
            foreach ($incoming as $i => $candidate) {
                $time = strtotime($candidate['time'] ?? '');
                if ($time !== false && $time > strtotime($message['time'])) { $position = $i; break; }
            }
            array_splice($incoming,$position,0,[$message]);
        }
    }
    return array_slice($incoming,-200);
}

function chat_admin_reply(string $sessionId, string $text): void
{
    chat_update_session($sessionId,static function(array $data) use ($text): array {
        $now = date('c');
        $data['messages'][] = ['sender'=>'agent','text'=>chat_clean_string($text,5000),'time'=>$now,'type'=>'chat','adminReplyId'=>bin2hex(random_bytes(16))];
        $data['messages'] = array_slice($data['messages'],-200);
        $data['updatedAt'] = $now;
        return $data;
    },true);
}
