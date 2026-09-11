<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/support-api.php';
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method,['GET','POST'],true)) {
        header('Allow: GET, POST');
        support_json(['ok'=>false,'error'=>'Method not allowed.'],405);
    }
    $input = $method === 'POST' ? support_input() : $_GET;
    $action = support_text($input,'action',20);
    if (($method === 'GET' && $action !== 'messages') || ($method === 'POST' && !in_array($action,['start','send','read'],true))) {
        support_json(['ok'=>false,'error'=>'Unsupported action for this method.'],405);
    }
    support_rate('chat-' . $action, $action === 'start' ? 5 : ($action === 'send' ? 30 : 120));
    if ($action === 'start') {
        support_json(['ok'=>true] + support_chat_start(support_text($input,'name',120,false)),201);
    }
    $id = support_chat_id($input['chat_id'] ?? null);
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $token = preg_match('/^Bearer ([a-f0-9]{64})$/D',$authorization,$matches) ? $matches[1] : '';
    if (!support_chat_authorized($id,$token)) support_json(['ok'=>false,'error'=>'Chat unavailable.'],403);
    if ($action === 'send') {
        $messageId = support_chat_send($id,'customer',support_text($input,'message',4000));
        support_json(['ok'=>true,'message_id'=>$messageId],201);
    }
    if ($action === 'read') {
        support_chat_read($id,'admin',support_cursor($input['through_id'] ?? 0));
        support_json(['ok'=>true]);
    }
    $after = support_cursor($input['after_id'] ?? 0);
    $messages = support_chat_messages($id,$after);
    support_json(['ok'=>true,'status'=>support_chat($id)['status'],'messages'=>$messages,
        'last_id'=>$messages ? (int) end($messages)['id'] : $after,'has_more'=>count($messages)===100]);
} catch (Throwable $error) { support_api_error($error); }
