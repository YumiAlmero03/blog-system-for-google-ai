<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
require_auth();
require_once __DIR__ . '/../includes/support-api.php';
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method,['GET','POST'],true)) support_json(['ok'=>false,'error'=>'Method not allowed.'],405);
    if ($method === 'POST') require_valid_csrf();
    $input = $method === 'POST' ? $_POST : $_GET;
    $id = support_chat_id($input['chat_id'] ?? null);
    if (!support_chat($id)) support_json(['ok'=>false,'error'=>'Chat not found.'],404);
    if ($method === 'POST') {
        $action = support_text($input,'action',16);
        if ($action === 'reply') support_chat_send($id,'admin',support_text($input,'message',4000));
        elseif ($action === 'status') support_chat_status($id,support_text($input,'status',16));
        elseif ($action === 'read') support_chat_read($id,'customer',support_cursor($input['through_id'] ?? 0));
        else throw new InvalidArgumentException('Invalid action.');
        support_json(['ok'=>true]);
    }
    $after = support_cursor($input['after_id'] ?? 0);
    $messages = support_chat_messages($id,$after);
    support_json(['ok'=>true,'messages'=>$messages,'status'=>support_chat($id)['status'],
        'last_id'=>$messages ? (int)end($messages)['id'] : $after,'has_more'=>count($messages)===100]);
} catch (Throwable $error) { support_api_error($error); }
