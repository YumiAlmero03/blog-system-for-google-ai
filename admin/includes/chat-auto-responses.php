<?php
declare(strict_types=1);
require_once __DIR__ . '/blog-storage.php';
require_once __DIR__ . '/chat-session-storage.php';

function chat_auto_rules(): array
{
    $stmt = blogs_pdo()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $stmt->execute(['chat_auto_responses']);
    $value = $stmt->fetchColumn();
    return $value === false ? [] : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
}

function chat_auto_text(string $text): string
{
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function chat_auto_change(string $action, array $input): void
{
    $id = $input['id'] ?? '';
    if (!is_string($id)) throw new InvalidArgumentException('Invalid rule ID.');
    $rule = null;
    if ($action === 'save') {
        foreach (['label'=>120, 'response'=>5000, 'keywords'=>10000] as $field=>$limit) {
            if (!is_string($input[$field] ?? null) || strlen($input[$field]) > $limit || trim($input[$field]) === '') {
                throw new InvalidArgumentException('Enter ' . $field . ' (up to ' . $limit . ' bytes).');
            }
        }
        $keywords = array_values(array_unique(array_filter(array_map('chat_auto_text', preg_split('/\R/u', $input['keywords'])))));
        if (!$keywords) throw new InvalidArgumentException('Enter at least one matching phrase.');
        $rule = ['id'=>$id ?: bin2hex(random_bytes(12)), 'label'=>trim($input['label']),
            'response'=>chat_clean_message($input['response']), 'keywords'=>$keywords,
            'suggested'=>in_array($input['suggested'] ?? '0', ['1',1,true], true),
            'enabled'=>in_array($input['enabled'] ?? '0', ['1',1,true], true)];
    } elseif ($action !== 'delete') {
        throw new InvalidArgumentException('Invalid action.');
    }
    $pdo = blogs_pdo();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $rules = chat_auto_rules();
        $index = array_search($id, array_column($rules,'id'), true);
        if ($id !== '' && $index === false) throw new InvalidArgumentException('Rule not found.');
        if ($action === 'delete') {
            if ($id === '') throw new InvalidArgumentException('Rule ID is required.');
            array_splice($rules, $index, 1);
        } elseif ($index === false) {
            $rules[] = $rule;
        } else {
            $rules[$index] = $rule;
        }
        if (count(array_filter($rules, static fn(array $r): bool => $r['suggested'] && $r['enabled'])) > 5) {
            throw new InvalidArgumentException('Only 5 enabled suggested chats are allowed. Turn off Suggested chat on another rule first.');
        }
        $stmt = $pdo->prepare('INSERT INTO app_settings(setting_key,setting_value,updated_at) VALUES(?,?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_at=excluded.updated_at');
        $stmt->execute(['chat_auto_responses',json_encode($rules,JSON_THROW_ON_ERROR),time()]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function chat_auto_used_rule_ids(array $session): array
{
    $ids = $session['autoReplyRuleIds'] ?? [];
    foreach (array_merge($session['messages'] ?? [], [$session['autoReply'] ?? []]) as $message) {
        if (($message['type'] ?? '') === 'auto_response' && isset($message['ruleId'])) $ids[] = $message['ruleId'];
    }
    return array_values(array_unique($ids));
}

function chat_auto_match(string $text, array $rules, array $session = []): ?array
{
    $text = chat_auto_text($text);
    $used = chat_auto_used_rule_ids($session);
    foreach ($rules as $rule) {
        if ($rule['enabled'] && $rule['suggested'] && $text === chat_auto_text($rule['label'])) return $rule;
    }
    $match = null; $length = 0;
    foreach ($rules as $rule) {
        if (!$rule['enabled'] || in_array($rule['id'], $used, true)) continue;
        foreach (array_merge([$rule['label']], $rule['keywords']) as $phrase) {
            $phrase = chat_auto_text($phrase);
            if ($phrase !== '' && strlen($phrase) > $length && str_contains($text,$phrase)) {
                $match = $rule; $length = strlen($phrase);
            }
        }
    }
    return $match;
}

/** Match only the latest user turn and replay its saved result on snapshot retries. */
function chat_auto_reply(array $messages, array $existing, array $rules): array
{
    $last = $messages ? $messages[array_key_last($messages)] : null;
    if (!$last || $last['sender'] !== 'user') return [null, null];
    $users = array_values(array_filter($messages,static fn(array $m): bool => $m['sender'] === 'user'));
    $key = hash('sha256',json_encode($users,JSON_THROW_ON_ERROR));
    if (($existing['autoReplyKey'] ?? null) === $key) return [$existing['autoReply'] ?? null, $key];
    $rule = chat_auto_match($last['text'],$rules,$existing);
    if (!$rule) return [null, $key];
    return [['sender'=>'bot','text'=>$rule['response'],'time'=>date('c'),'type'=>'auto_response',
        'autoReplyId'=>bin2hex(random_bytes(16)), 'ruleId'=>$rule['id']], $key];
}
