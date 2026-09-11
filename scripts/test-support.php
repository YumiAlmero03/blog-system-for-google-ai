<?php
declare(strict_types=1);
// Isolated storage tests: php scripts/test-support.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dir = sys_get_temp_dir() . '/support-test-' . bin2hex(random_bytes(6));
mkdir($dir,0700);
putenv('APP_STORAGE_DIR=' . $dir);
require_once __DIR__ . '/../includes/support-storage.php';
require_once __DIR__ . '/../includes/api-rate-limit.php';
function verify(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); }
function rejects(callable $callback, string $label): void {
    try { $callback(); } catch (InvalidArgumentException | DomainException $e) { return; }
    throw new RuntimeException($label);
}
try {
    $id = support_contact_save(['email'=>'person@example.test','subject'=>'Hello','message'=>'<script>example</script>','source_page'=>'/contact/']);
    $pdo = support_pdo();
    verify((int)$pdo->query('SELECT COUNT(*) FROM contact_submissions')->fetchColumn() === 1,'Contact stored');
    foreach (['read','resolved','new'] as $status) {
        support_contact_status($id,$status);
        verify($pdo->query('SELECT status FROM contact_submissions')->fetchColumn()===$status,'Contact status');
    }
    rejects(fn()=>support_contact_save(['email'=>'invalid','subject'=>'Hello','message'=>'Test']),'Invalid email');
    rejects(fn()=>support_contact_save(['email'=>'person@example.test','subject'=>'Hello','message'=>str_repeat('x',10001)]),'Oversize contact');
    putenv('SMTP_HOST=127.0.0.1'); putenv('SMTP_PORT=1'); putenv('SMTP_ENCRYPTION=none'); putenv('SMTP_TIMEOUT=1');
    putenv('CONTACT_RECIPIENT_EMAIL=admin@example.test'); putenv('SMTP_FROM_EMAIL=site@example.test');
    verify(!support_contact_notify($id),'SMTP failure');
    verify((int)$pdo->query('SELECT COUNT(*) FROM contact_submissions')->fetchColumn() === 1,'Contact survives SMTP failure');
    $guest = support_chat_start(''); $named = support_chat_start('Customer');
    verify(support_chat($guest['chat_id'])['customer_name']==='','Guest starts');
    verify(support_chat($named['chat_id'])['customer_name']==='Customer','Named starts');
    verify(support_chat_authorized($guest['chat_id'],$guest['token']),'Owner access');
    verify(!support_chat_authorized($guest['chat_id'],$named['token']),'Different customer rejected');
    verify(!support_chat_authorized($guest['chat_id'],''),'Missing token rejected');
    $first = support_chat_send($guest['chat_id'],'customer','Hello');
    $reply = support_chat_send($guest['chat_id'],'admin','Welcome');
    $messages = support_chat_messages($guest['chat_id'],$first);
    verify(count($messages)===1 && $messages[0]['id']===$reply && $messages[0]['sender']==='admin','Incremental reply polling');
    verify((int)$pdo->query("SELECT COUNT(*) FROM live_chat_messages WHERE sender='customer' AND read_at IS NULL")->fetchColumn()===1,'Unread count');
    support_chat_read($guest['chat_id'],'customer',$first);
    verify((int)$pdo->query("SELECT COUNT(*) FROM live_chat_messages WHERE sender='customer' AND read_at IS NULL")->fetchColumn()===0,'Mark read');
    support_chat_status($guest['chat_id'],'closed');
    rejects(fn()=>support_chat_send($guest['chat_id'],'customer','Closed'),'Closed chat rejects send');
    support_chat_status($guest['chat_id'],'open');
    support_chat_send($guest['chat_id'],'customer','Reopened');
    rejects(fn()=>support_chat_send($guest['chat_id'],'customer','  '),'Empty message');
    rejects(fn()=>support_chat_send($guest['chat_id'],'customer',str_repeat('x',4001)),'Oversize message');
    rejects(fn()=>support_chat_id('../invalid'),'Invalid ID');
    for ($i=0;$i<105;$i++) support_chat_send($named['chat_id'],'customer','Message ' . $i);
    $batch = support_chat_messages($named['chat_id'],0);
    verify(count($batch)===100 && count(support_chat_messages($named['chat_id'],(int)end($batch)['id']))===5,'Bounded polling');
    verify(!api_rate_limit_exceeded('test',1) && api_rate_limit_exceeded('test',1),'Rate limiting');
    echo "PASS: contact persistence/status/validation; SMTP failure retention; guest/named chats; ownership; replies; incremental polling; unread state; close/reopen; message limits; rate limiting.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($dir);
}
