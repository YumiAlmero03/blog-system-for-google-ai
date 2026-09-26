<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/chat-ai.php';
$once=in_array('--once',$argv,true);
do {
    foreach (glob(chat_dir().'/*.json') ?: [] as $path) {
        $id=basename($path);
        if (!chat_is_valid_session_id($id)) continue;
        try { if (chat_ai_process($id)) echo "AI reply saved.\n"; }
        catch (Throwable $e) { error_log('Chat worker could not process a session.'); }
    }
    if (!$once) sleep(2);
} while (!$once);
