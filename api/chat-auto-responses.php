<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__ . '/../admin/includes/chat-auto-responses.php';
$action = $_GET['admin_action'] ?? $_POST['admin_action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($action !== null) {
    require_once __DIR__ . '/../admin/includes/auth.php';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    require_auth('admin');
}
try {
    if ($action === null && $method === 'GET') {
        $suggestions = [];
        foreach (chat_auto_rules() as $rule) {
            if ($rule['enabled'] && $rule['suggested']) $suggestions[] = ['id'=>$rule['id'],'label'=>$rule['label'],'message'=>$rule['label']];
        }
        echo json_encode(['ok'=>true,'suggestions'=>array_slice($suggestions,0,5)],JSON_THROW_ON_ERROR);
    } elseif ($action === 'list' && $method === 'GET') {
        echo json_encode(['ok'=>true,'rules'=>chat_auto_rules()],JSON_THROW_ON_ERROR);
    } elseif (in_array($action,['save','delete'],true) && $method === 'POST') {
        require_valid_csrf();
        chat_auto_change($action,$_POST);
        echo json_encode(['ok'=>true,'rules'=>chat_auto_rules()],JSON_THROW_ON_ERROR);
    } else {
        http_response_code(405);
        header('Allow: ' . ($action === null || $action === 'list' ? 'GET' : 'POST'));
        echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);
    }
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Chat automatic responses failed.');
    echo json_encode(['ok'=>false,'error'=>'Automatic responses are unavailable.']);
}
