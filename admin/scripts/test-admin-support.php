<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dir = sys_get_temp_dir() . '/legacy-support-test-' . bin2hex(random_bytes(6));
mkdir($dir,0700); mkdir($dir . '/includes'); mkdir($dir . '/storage'); mkdir($dir . '/chats');
putenv('APP_STORAGE_DIR=' . $dir . '/storage');
foreach (['env.php','customer-ticket-storage.php','chat-session-storage.php','admin-support-data.php'] as $file) {
    copy(__DIR__ . '/../includes/' . $file,$dir . '/includes/' . $file);
}
require $dir . '/includes/admin-support-data.php';
function check(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); }
try {
    $ticket = ['time'=>'2026-09-11T00:00:00Z','fullName'=>'Existing Customer','contact'=>'09123456789','topic'=>'Question','problem'=>'Existing ticket','pageUrl'=>'/contact/'];
    file_put_contents(ticket_storage_dir() . '/customer-tickets.json',json_encode(['recent'=>[$ticket],'totalTickets'=>1]));
    $rows = admin_ticket_rows(); check(count($rows)===1 && $rows[0]['problem']==='Existing ticket','Existing ticket');
    $key = $rows[0]['_key'];
    file_put_contents(ticket_storage_dir() . '/customer-tickets.json',json_encode(['recent'=>[$ticket,array_replace($ticket,['problem'=>'New ticket'])],'totalTickets'=>2]));
    $rows = admin_ticket_rows(); check(count($rows)===2 && $rows[1]['_key']===$key,'New ticket and stable detail link');
    check(admin_support_matches($rows[0],'new ticket'),'Ticket search');
    $id = 'guest_2026-09-11_001.json';
    $data = ['sessionId'=>$id,'name'=>'','startedAt'=>date('c'),'updatedAt'=>date('c'),'messages'=>[['sender'=>'user','text'=>'Existing message','time'=>'2026-09-11T00:00:00Z','type'=>'chat']]];
    file_put_contents(chat_dir() . '/' . $id,json_encode($data));
    check(count(chat_read_session($id)['messages'])===1,'Existing messages');
    chat_admin_reply($id,'Admin reply');
    $saved = chat_read_session($id); check(count($saved['messages'])===2 && $saved['messages'][1]['sender']==='agent','Reply persists');
    $incoming = array_merge($data['messages'],[['sender'=>'user','text'=>'New customer reply','time'=>date('c',time()+1),'type'=>'chat']]);
    $merged = chat_preserve_admin_replies($incoming,$saved['messages']);
    check(count($merged)===3,'Snapshot preserves reply');
    check(count(chat_preserve_admin_replies($merged,$saved['messages']))===3,'No duplicate admin reply');
    chat_update_session($id,static fn(array $row): array => array_replace($row,['messages'=>$merged]));
    check(count(chat_read_session($id)['messages'])===3,'Customer update saves');
    check(chat_read_session('../invalid.json')===null,'Traversal rejected');
    check(count(admin_chat_rows('New customer reply'))===1,'Chat search');
    check(!isset($saved['status']) && !isset($saved['unreadCount']),'No invented state');
    echo "PASS: existing/new tickets, stable detail IDs, search, legacy chat history, agent replies, snapshot preservation, duplicate prevention, path validation, and unchanged status model.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($dir);
}
