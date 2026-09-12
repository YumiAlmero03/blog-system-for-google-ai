<?php
declare(strict_types=1);
require_once __DIR__ . '/customer-ticket-storage.php';
require_once __DIR__ . '/chat-session-storage.php';

function admin_ticket_rows(): array
{
    $path = ticket_storage_dir() . '/customer-tickets.json';
    if (!is_file($path)) return [];
    $handle = fopen($path,'r');
    if (!$handle) throw new RuntimeException('Cannot read tickets.');
    try {
        if (!flock($handle,LOCK_SH)) throw new RuntimeException('Cannot lock tickets.');
        $data = json_decode(stream_get_contents($handle),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['recent'] ?? null)) throw new RuntimeException('Invalid ticket storage.');
        $rows = [];
        foreach ($data['recent'] as $row) {
            if (!is_array($row)) continue;
            // The existing archive has no ticket ID. A content hash survives array reordering.
            $row['_key'] = hash('sha256',json_encode($row));
            $rows[] = $row;
        }
        return array_reverse($rows);
    } finally { fclose($handle); }
}

function admin_support_query(string $key, int $limit = 120): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? substr(trim($value),0,$limit) : '';
}

function admin_support_matches(array $row, string $search): bool
{
    return $search === '' || stripos(implode(' ',array_filter($row,'is_scalar')),$search) !== false;
}

function admin_chat_rows(string $search): array
{
    $rows = [];
    foreach (glob(chat_dir() . '/*.json') ?: [] as $path) {
        $id = basename($path);
        $data = chat_read_session($id);
        if ($data === null) continue;
        $messages = is_array($data['messages'] ?? null) ? $data['messages'] : [];
        $last = $messages ? end($messages) : [];
        $row = ['sessionId'=>$id,'name'=>$data['name'] ?? '', 'contact'=>$data['contact'] ?? '',
            'preview'=>$last['text'] ?? '', 'status'=>$data['status'] ?? '',
            'unreadCount'=>$data['unreadCount'] ?? null,
            'startedAt'=>$data['startedAt'] ?? '', 'updatedAt'=>$data['updatedAt'] ?? ''];
        if (admin_support_matches($row,$search)) $rows[] = $row;
    }
    usort($rows,static fn(array $a,array $b): int => strcmp($b['updatedAt'],$a['updatedAt']) ?: strcmp($b['sessionId'],$a['sessionId']));
    return $rows;
}
