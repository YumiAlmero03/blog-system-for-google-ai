<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dir = sys_get_temp_dir() . '/chat-auto-test-' . bin2hex(random_bytes(6));
mkdir($dir,0700);
putenv('APP_STORAGE_DIR=' . $dir);
require_once __DIR__ . '/../includes/chat-auto-responses.php';
function check_auto(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); }
try {
    check_auto(chat_auto_rules() === [],'Empty initial configuration');
    for ($i=0; $i<7; $i++) chat_auto_change('save',[
        'label'=>'Suggestion ' . $i,'keywords'=>"phrase $i",'response'=>'Answer ' . $i,'enabled'=>'1','suggested'=>$i<5 ? '1' : '0',
    ]);
    check_auto(count(chat_auto_rules()) === 7,'Unlimited rules beyond five');
    try {
        chat_auto_change('save',['label'=>'Sixth','keywords'=>'sixth','response'=>'Answer','enabled'=>'1','suggested'=>'1']);
        throw new RuntimeException('Sixth suggestion accepted');
    } catch (InvalidArgumentException $e) {}
    check_auto(count(chat_auto_rules()) === 7,'Rejected write rolls back');
    chat_auto_change('save',['label'=>'Claim bonus','keywords'=>"bonus\nhow to get bonus",'response'=>'Bonus instructions','enabled'=>'1']);
    $rules = chat_auto_rules(); $bonus = $rules[7];
    check_auto(chat_auto_match('Please tell me HOW TO GET   BONUS today',$rules)['id'] === $bonus['id'],'Contains matching ignores case and spaces');
    check_auto(chat_auto_match('Suggestion 0',$rules)['id'] === $rules[0]['id'],'Suggestion label matches');
    check_auto(chat_auto_match('unrelated',$rules) === null,'No match');
    $specific = $bonus; $specific['id'] = 'specific'; $specific['keywords'] = ['how to get bonus today'];
    check_auto(chat_auto_match('how to get bonus today',[$bonus,$specific])['id'] === 'specific','Longest match');
    $disabled = $bonus; $disabled['enabled'] = false;
    check_auto(chat_auto_match('bonus',[$disabled]) === null,'Disabled rule ignored');
    $messages = chat_normalize_messages([['sender'=>'user','text'=>'how to get bonus']]);
    [$reply,$key] = chat_auto_reply($messages,[],$rules);
    check_auto($reply['sender'] === 'bot' && $reply['text'] === 'Bonus instructions','Bot reply');
    $existing = ['autoReplyKey'=>$key,'autoReply'=>$reply,'messages'=>array_merge($messages,[$reply])];
    [$retry] = chat_auto_reply($messages,$existing,$rules);
    check_auto($retry === $reply,'Retry replays the same reply ID');
    $merged = chat_preserve_admin_replies($messages,$existing['messages']);
    check_auto(count($merged) === 2 && $merged[1]['autoReplyId'] === $reply['autoReplyId'],'Old snapshot preserves bot reply');
    check_auto(count(chat_preserve_admin_replies(chat_normalize_messages($merged),$merged)) === 2,'Echoed reply not duplicated');
    check_auto(chat_auto_reply($merged,$existing,$rules)[0] === null,'Bot turn does not trigger reply');
    $messages[] = ['sender'=>'user','text'=>'bonus','time'=>'later','type'=>'chat'];
    check_auto(chat_auto_reply($messages,$existing,$rules)[0]['autoReplyId'] !== $reply['autoReplyId'],'New turn creates new reply');
    chat_auto_change('save',['id'=>$bonus['id'],'label'=>'Bonus','keywords'=>'bonus','response'=>'Updated','enabled'=>'1']);
    check_auto(chat_auto_match('bonus',chat_auto_rules())['response'] === 'Updated','Edit response');
    chat_auto_change('delete',['id'=>$bonus['id']]);
    check_auto(chat_auto_match('bonus',chat_auto_rules()) === null,'Delete response');
    $formatted = "Step 1\n\nStep 2: https://example.com/register";
    chat_auto_change('save',['label'=>'Register','keywords'=>'register','response'=>$formatted,'enabled'=>'1']);
    check_auto(chat_auto_match('register',chat_auto_rules())['response'] === $formatted,'Automatic reply formatting preserved');
    check_auto(chat_normalize_messages([['text'=>$formatted]])[0]['text'] === $formatted,'Legacy transcript formatting preserved');
    echo "PASS: rule CRUD, five-suggestion limit, unlimited rules, matching, disabled rules, bot replies, retries, history preservation.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($dir);
}
