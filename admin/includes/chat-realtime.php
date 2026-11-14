<?php
declare(strict_types=1);
require_once __DIR__ . '/chat-auto-responses.php';
require_once __DIR__ . '/api-rate-limit.php';

const CHAT_ONLINE_TTL = 75;
const CHAT_AGENT_WAIT = 180;

function chat_ai_name(): string
{
    return chat_clean_string(blog_setting_get('chat_ai_name', 'Alex'), 80) ?: 'Alex';
}

function chat_prepare_directory(): void
{
    $dir = chat_dir();
    if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Chat storage unavailable.');
    if (!is_file($dir.'/.htaccess')) file_put_contents($dir.'/.htaccess',"Require all denied\n",LOCK_EX);
}

function chat_agent_presence(?string $key = null, bool $online = false, ?int $now = null): bool
{
    $now ??= time();
    chat_prepare_directory();
    $handle = fopen(chat_dir().'/.agents.json','c+');
    if (!$handle) throw new RuntimeException('Presence unavailable.');
    try {
        if (!flock($handle,LOCK_EX)) throw new RuntimeException('Presence unavailable.');
        $agents = json_decode(stream_get_contents($handle),true) ?: [];
        $agents = array_filter($agents,static fn($seen): bool => is_int($seen) && $seen > $now-CHAT_ONLINE_TTL);
        if ($key !== null) {
            if ($online) $agents[$key] = $now; else unset($agents[$key]);
        }
        $raw = json_encode($agents,JSON_THROW_ON_ERROR);
        rewind($handle);
        if (fwrite($handle,$raw) !== strlen($raw) || !ftruncate($handle,strlen($raw)) || !fflush($handle)) throw new RuntimeException('Presence unavailable.');
        return count($agents)>0;
    } finally { fclose($handle); }
}

function chat_player_online(array $data, ?int $now = null): bool
{
    $now ??= time();
    foreach ($data['connections'] ?? [] as $seen) if (is_int($seen) && $seen > $now-CHAT_ONLINE_TTL) return true;
    return false;
}

function chat_player_presence(array $data): ?bool
{
    return empty($data['visitorTokenHash']) ? null : chat_player_online($data);
}

function chat_realtime_authorize(array $data, mixed $token): void
{
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token)
        || empty($data['visitorTokenHash']) || ($data['expiresAt'] ?? 0) < time()
        || !hash_equals($data['visitorTokenHash'],hash('sha256',$token))) {
        throw new DomainException('Chat access denied.');
    }
}

function chat_realtime_view(array $data, bool $agentsOnline): array
{
    $messages = $data['messages'] ?? [];
    $status = ['agentOnline'=>$agentsOnline,'playerOnline'=>chat_player_online($data),
        'waitingSince'=>$data['pendingSince'] ?? null,'aiPending'=>!empty($data['aiLease']),
        'aiEnabled'=>chat_ai_enabled(),'supportName'=>chat_ai_name()];
    return ['ok'=>true,'sessionId'=>$data['sessionId'],'revision'=>$data['revision'] ?? 0,'messages'=>$messages,'status'=>$status,
        'version'=>hash('sha256',json_encode([$messages,$status],JSON_THROW_ON_ERROR))];
}

function chat_ai_enabled(): bool
{
    return !in_array(strtolower(env_value('CHAT_AI_ENABLED') ?? '1'),['0','false','off'],true);
}

function chat_realtime_action(array $input): array
{
    $action = $input['action'] ?? '';
    if (!in_array($action,['start','send','poll','heartbeat','disconnect'],true)) throw new InvalidArgumentException('Unknown chat action.');
    if ($action === 'start') {
        if (api_rate_limit_exceeded(hash('sha256','chat-start|'.($_SERVER['REMOTE_ADDR'] ?? 'unknown')),5)) throw new OverflowException('Too many new chats. Try again in a minute.');
        chat_prepare_directory();
        $name = is_string($input['name'] ?? null) ? chat_clean_string($input['name'],120) : 'Guest';
        $id = chat_filename_part($name).'_'.date('Y-m-d').'_'.substr(bin2hex(random_bytes(7)),0,13).'.json';
        $token = bin2hex(random_bytes(32));
        $data = ['sessionId'=>$id,'name'=>$name,'startedAt'=>date('c'),'updatedAt'=>date('c'),
            'messages'=>[],'visitorTokenHash'=>hash('sha256',$token),'expiresAt'=>time()+2592000,
            'connections'=>[],'revision'=>0,'pendingSince'=>null];
        chat_update_session($id,static function(array $existing) use ($data): array {
            if ($existing) throw new RuntimeException('Please retry starting the chat.');
            return $data;
        });
        return chat_realtime_view($data,chat_agent_presence()) + ['sessionToken'=>$token];
    }
    $id = $input['session_id'] ?? '';
    if (!is_string($id) || !chat_is_valid_session_id($id)) throw new DomainException('Chat access denied.');
    $data = chat_read_session($id) ?? [];
    chat_realtime_authorize($data,$input['session_token'] ?? null);
    $limit = $action === 'send' ? 20 : 180;
    if (api_rate_limit_exceeded(hash('sha256','chat-'.$action.'|'.$id),$limit)) throw new OverflowException('Too many requests. Try again in a minute.');
    if ($action === 'poll') {
        $view = chat_realtime_view($data,chat_agent_presence());
        if (($input['version'] ?? '') === $view['version']) { $view['unchanged']=true; $view['messages']=[]; }
        return $view;
    }
    $client = $input['client_id'] ?? '';
    if (!is_string($client) || !preg_match('/^[a-zA-Z0-9_-]{8,80}$/D',$client)) throw new InvalidArgumentException('A client_id of 8–80 letters, digits, hyphens or underscores is required.');
    $rules = $action === 'send' ? chat_auto_rules() : [];
    $addedMessages = [];
    chat_update_session($id,static function(array $row) use ($input,$action,$client,$rules,&$data,&$addedMessages): array {
        chat_realtime_authorize($row,$input['session_token'] ?? null);
        $now = time();
        $row['connections'] = array_filter($row['connections'] ?? [],static fn($seen): bool => is_int($seen) && $seen>$now-CHAT_ONLINE_TTL);
        if ($action === 'disconnect' || ($action === 'heartbeat' && ($input['online'] ?? true) === false)) unset($row['connections'][$client]);
        else {
            $row['connections'][$client] = $now;
            $row['connections'] = array_slice($row['connections'],-10,null,true);
        }
        if ($action === 'send') {
            $text = $input['message'] ?? null; $requestId = $input['client_message_id'] ?? null;
            if (!is_string($text) || trim($text)==='' || strlen($text)>5000) throw new InvalidArgumentException('Enter a message of up to 5,000 bytes.');
            if (!is_string($requestId) || !preg_match('/^[a-zA-Z0-9_-]{8,80}$/D',$requestId)) throw new InvalidArgumentException('A stable client_message_id is required.');
            foreach ($row['messages'] as $message) if (($message['clientMessageId'] ?? '') === $requestId) {
                if ($message['text'] !== chat_clean_message($text)) throw new InvalidArgumentException('Message ID was already used.');
                $data=$row; return $row;
            }
            $message = ['id'=>bin2hex(random_bytes(16)),'sender'=>'user','text'=>chat_clean_message($text),
                'time'=>date('c',$now),'type'=>'chat','clientMessageId'=>$requestId];
            $row['messages'][] = $message;
            $addedMessages[] = $message;
            $row['pendingSince'] ??= $now;
            $row['revision'] = ($row['revision'] ?? 0)+1;
            $row['aiLease'] = null;
            $row['autoReplyRuleIds'] = chat_auto_used_rule_ids($row);
            $rule = chat_auto_match($text,$rules,$row);
            if ($rule) {
                $reply = ['id'=>bin2hex(random_bytes(16)),'sender'=>'bot','text'=>$rule['response'],
                    'time'=>date('c',$now),'type'=>'auto_response','ruleId'=>$rule['id']];
                $row['messages'][] = $reply;
                $addedMessages[] = $reply;
                $row['pendingSince'] = null;
                $row['autoReplyRuleIds'] = array_values(array_unique(array_merge($row['autoReplyRuleIds'], [$rule['id']])));
            }
            $row['messages'] = array_slice($row['messages'],-200);
            $row['updatedAt'] = date('c',$now);
        }
        $data=$row; return $row;
    },true);
    $view = chat_realtime_view($data,chat_agent_presence());
    if ($action === 'send' && $addedMessages !== []) {
        $view['messages'] = $addedMessages;
        $view['incremental'] = true;
    }
    return $view;
}
