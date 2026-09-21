<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dir = sys_get_temp_dir() . '/editor-security-' . bin2hex(random_bytes(6)); mkdir($dir,0700);
ini_set('session.save_path',$dir);
putenv('APP_STORAGE_DIR=' . $dir);
putenv('ADMIN_USERNAME=test-admin'); putenv('ADMIN_PASSWORD_HASH=' . password_hash('Admin testing password 123!',PASSWORD_DEFAULT));
putenv('ADMIN_JWT_SECRET=' . str_repeat('test-secret-',6));
putenv('BLOG_API_JWT_SECRET=' . str_repeat('public-test-',6));
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rate-limit.php';
require_once __DIR__ . '/../includes/api-rate-limit.php';
require_once __DIR__ . '/../includes/slot-content.php';
function security_check(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); }
try {
    $admin = auth_authenticate_credentials('test-admin','Admin testing password 123!');
    security_check($admin !== null && $admin['role']==='super_user','Existing admin credentials');
    $_SESSION['user'] = $admin;
    $input = ['username'=>'test-editor','display_name'=>'Editor','password'=>'Editor testing password 123!','active'=>'1'];
    $id = admin_user_save($input);
    $user = auth_authenticate_credentials('test-editor',$input['password']);
    security_check($user !== null && password_verify($input['password'],admin_user_find($id)['password_hash']),'Editor password hashing');
    security_check(auth_authenticate_credentials('test-editor','incorrect')===null,'Invalid password');
    auth_mark_authenticated($user);
    $claims = ['aud'=>'admin','scope'=>'admin:access','sub'=>$user['id'],'user_id'=>$user['id'],'role'=>'editor','ver'=>$user['auth_version'],'sid'=>$_SESSION['auth_binding'],'iat'=>time(),'nbf'=>time(),'exp'=>time()+900];
    $_COOKIE[AUTH_ACCESS_COOKIE] = jwt_sign($claims,auth_jwt_secret());
    security_check(auth_is_authenticated(),'Session-bound JWT');
    foreach (['blogs.view','blogs.edit','blogs.publish','slots.edit','contacts.view','chat.reply'] as $capability) security_check(auth_can($capability),'Editor capability');
    security_check(!auth_can('admin'),'No admin role escalation');
    $_SERVER['SCRIPT_NAME']='/admin/settings.php'; security_check(auth_route_capability()==='admin','Default-deny routes');
    $expired = $claims; $expired['exp']=time();
    security_check(jwt_verify(jwt_sign($expired,auth_jwt_secret()),'admin','admin:access',auth_jwt_secret())===null,'Expired JWT');
    $missing = $claims; unset($missing['exp']);
    security_check(jwt_verify(jwt_sign($missing,auth_jwt_secret()),'admin','admin:access',auth_jwt_secret())===null,'Missing expiration');
    $wrong = $claims; $wrong['scope']='blog:read';
    security_check(jwt_verify(jwt_sign($wrong,auth_jwt_secret()),'admin','admin:access',auth_jwt_secret())===null,'Scope isolation');
    $_SESSION['user'] = $admin;
    admin_user_save(['id'=>$id]+array_replace($input,['active'=>'0','password'=>'']));
    $_SESSION['user'] = $user;
    security_check(!auth_is_authenticated(),'Disabled token rejected');
    $_SESSION['user'] = $admin;
    admin_user_save(['id'=>$id]+array_replace($input,['password'=>'New testing password 123!']));
    $_SESSION['user'] = $user;
    security_check(!auth_is_authenticated(),'Old token stays revoked after enable/reset');
    security_check(auth_authenticate_credentials('test-editor',$input['password'])===null,'Old password rejected');
    security_check(auth_authenticate_credentials('test-editor','New testing password 123!')!==null,'Reset password works');
    for ($i=0;$i<5;$i++) login_record_failure('test-editor','127.0.0.1');
    security_check(login_rate_limited('test-editor','127.0.0.1'),'Five-failure limit');
    login_clear_failures('test-editor','127.0.0.1');
    security_check(!login_rate_limited('test-editor','127.0.0.1'),'Successful reset');
    file_put_contents(login_attempts_path(),json_encode([login_rate_key('old','127.0.0.1')=>array_fill(0,5,time()-901)]));
    security_check(!login_rate_limited('old','127.0.0.1'),'Temporary lock expires');
    security_check(!api_rate_limit_exceeded('test-write',1) && api_rate_limit_exceeded('test-write',1),'API rate cap');
    $pdo = blogs_pdo();
    $pdo->exec("INSERT INTO games(api_id,name,slug,published,done_processing,restrictions,updated_at) VALUES(999,'Game','game',1,1,'[\"PH\"]',1)");
    $gameId = (int)$pdo->lastInsertId();
    $original = $pdo->query('SELECT api_id,slug,done_processing,restrictions,published FROM games')->fetch();
    $save = ['id'=>$gameId,'name'=>'Edited','short_description'=>'Short','long_description'=>'<h2>Heading</h2><p><b>Bold</b><a href="javascript:alert(1)">Bad link</a></p><script>alert(1)</script>','rtp'=>'96.5','volatility'=>'High'];
    slot_content_save($save);
    $game = $pdo->query('SELECT * FROM games')->fetch();
    security_check(str_contains($game['long_description'],'<h2>Heading</h2>') && !str_contains($game['long_description'],'<script>') && !str_contains($game['long_description'],'javascript:'),'Safe HTML formatting');
    security_check($original===$pdo->query('SELECT api_id,slug,done_processing,restrictions,published FROM games')->fetch(),'Protected game fields');
    $rejected=false; try { slot_content_save($save+['done_processing'=>0]); } catch (InvalidArgumentException $e) { $rejected=true; }
    security_check($rejected,'Mass assignment rejected');
    $publicClaims=['aud'=>'blog-post-list','scope'=>'blog:read','iat'=>time(),'exp'=>time()+300];
    security_check(jwt_verify(jwt_sign($publicClaims),'blog-post-list')!==null,'Existing public JWT flow');
    auth_destroy_session();
    echo "PASS: admin/editor credentials, hashed passwords, JWT signature/expiry/scope/session rules, role capabilities, disabled/reset revocation, login limits/expiry, API limits, slot HTML and protected fields, public JWT compatibility.\n";
} finally {
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($dir);
}
