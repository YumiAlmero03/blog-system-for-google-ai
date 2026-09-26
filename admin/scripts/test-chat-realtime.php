<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$dir=sys_get_temp_dir().'/chat-realtime-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
putenv('APP_STORAGE_DIR='.$dir.'/storage'); putenv('CHAT_STORAGE_DIR='.$dir.'/chats'); putenv('CHAT_AI_ENABLED=1');
require __DIR__.'/../includes/chat-ai.php';
function rt_check(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); }
try {
    $_SERVER['REMOTE_ADDR']='127.0.0.1';
    $start=chat_realtime_action(['action'=>'start','name'=>'Test']);
    $auth=['session_id'=>$start['sessionId'],'session_token'=>$start['sessionToken'],'client_id'=>'browser-test'];
    $id=$start['sessionId'];
    rt_check(!isset($start['visitorTokenHash']) && strlen($start['sessionToken'])===64,'Opaque token issued without hash leakage');
    $denied=false;
    try { chat_realtime_action(array_replace($auth,['action'=>'poll','session_token'=>str_repeat('0',64)])); } catch(DomainException $e) { $denied=true; }
    rt_check($denied,'Unauthorized reads rejected');
    $request=$auth+['action'=>'send','message'=>'Help please','client_message_id'=>'message-test-1'];
    $send=chat_realtime_action($request);
    rt_check($send['messages'][0]['sender']==='user' && $send['status']['playerOnline'],'Server-owned sender and online state');
    rt_check(count(chat_realtime_action($request)['messages'])===1,'Duplicate send deduplicated');
    $now=time();
    chat_agent_presence('test-agent',true,$now);
    $data=chat_read_session($id);
    rt_check(!chat_ai_due($data,true,$now+599),'Online agent gets full ten minutes');
    $data['connections']['browser-test']=$now+600;
    rt_check(chat_ai_due($data,true,$now+600),'Ten-minute boundary');
    $data['connections']=[];
    rt_check(!chat_ai_due($data,false,$now+600),'Offline player receives no AI');
    chat_agent_presence('test-agent',false);
    rt_check(chat_ai_process($id,static fn()=> 'I am the AI assistant.'),'Offline agent triggers AI immediately');
    rt_check(!chat_ai_process($id,static fn()=> 'Duplicate'),'No duplicate AI reply');
    rt_check(chat_read_session($id)['messages'][1]['type']==='ai','AI visibly labeled');
    chat_realtime_action(array_replace($request,['client_message_id'=>'message-test-2','message'=>'Another question']));
    rt_check(!chat_ai_process($id,static function() use ($id) { chat_admin_reply($id,'Human answer'); return 'Late AI answer'; }),'Human reply wins the race');
    rt_check(end(chat_read_session($id)['messages'])['sender']==='agent','No late AI saved');
    chat_realtime_action(array_replace($request,['client_message_id'=>'message-test-3','message'=>'More help']));
    $calls=0;
    chat_ai_process($id,static function() use (&$calls) { $calls++; throw new RuntimeException('429'); });
    chat_ai_process($id,static function() use (&$calls) { $calls++; return 'Unexpected'; });
    rt_check($calls===1 && chat_read_session($id)['aiRetryAt']>time(),'Failed AI backs off');
    chat_update_session($id,static function(array $row): array { $row['aiRetryAt']=0; return $row; },true);
    rt_check(!chat_ai_process($id,static function() use ($auth) { chat_realtime_action($auth+['action'=>'disconnect']); return 'Offline answer'; }),'Disconnect during generation discards answer');
    chat_realtime_action($auth+['action'=>'heartbeat']);
    chat_update_session($id,static function(array $row): array { $row['aiLease']=['id'=>'other','until'=>time()+100]; return $row; },true);
    rt_check(!chat_ai_process($id,static fn()=> 'Duplicate lease'),'Concurrent worker lease respected');
    chat_update_session($id,static function(array $row): array { $row['aiLease']=null; return $row; },true);
    rt_check(!chat_ai_process($id,static function() use ($request) { chat_realtime_action(array_replace($request,['client_message_id'=>'message-test-4','message'=>'New turn'])); return 'Stale answer'; }),'New user turn invalidates stale AI');
    chat_auto_change('save',['label'=>'Deposit','keywords'=>'how to deposit','response'=>'Configured instructions','enabled'=>'1']);
    $auto=chat_realtime_action(array_replace($request,['client_message_id'=>'message-test-5','message'=>'how to deposit']));
    rt_check(end($auto['messages'])['type']==='auto_response' && $auto['status']['waitingSince']===null,'Keyword reply takes precedence');
    rt_check(chat_agent_presence('expired',true,$now-80) && !chat_agent_presence(null,false,$now),'Agent presence expires');
    echo "PASS: visitor tokens, idempotent sends, presence expiry, ten-minute boundary, immediate fallback, offline exclusion, human takeover, leases, new-turn race, backoff, and keyword replies.\n";
} finally {
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($dir);
}
