<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$dir=sys_get_temp_dir().'/google-auth-test-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
putenv('APP_STORAGE_DIR='.$dir); putenv('GOOGLE_PRIVATE_DIR='.$dir.'/private'); putenv('SITE_BASE_URL=https://example.test');
putenv('ADMIN_USERNAME=super_user'); putenv('ADMIN_PASSWORD_HASH='.password_hash('Visibility test password 123!',PASSWORD_DEFAULT));
putenv('ADMIN_JWT_SECRET='.str_repeat('google-auth-test-',5)); ini_set('session.save_path',$dir);
require_once __DIR__ . '/../includes/index-checker-client.php';
require_once __DIR__ . '/../includes/auth.php';
function gc_check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function gc_remove(string $dir): void { foreach(scandir($dir) as $f) if ($f!=='.' && $f!=='..') { $p=$dir.'/'.$f; is_dir($p)?gc_remove($p):unlink($p); } rmdir($dir); }
function gc_handler(array $request): array {
    $proc=proc_open([PHP_BINARY,__DIR__ . '/test-game-visibility.php','--handler'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],json_encode($request)); fclose($pipes[0]); $body=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    gc_check(proc_close($proc)===0 && preg_match('/^STATUS:(\d+)$/m',$err,$m)===1,'Handler failed'); return [(int)$m[1],$body];
}
try {
    $key=openssl_pkey_new(['private_key_bits'=>2048]); openssl_pkey_export($key,$pem);
    $credential=['type'=>'service_account','project_id'=>'test-project','private_key'=>$pem,'client_email'=>'test@test-project.iam.gserviceaccount.com','token_uri'=>'https://oauth2.googleapis.com/token'];
    google_save_configuration('sc-domain:example.test',json_encode($credential));
    gc_check(google_safe_status()['configured'],'Valid credentials');
    $path=google_private_dir().'/google-config.json';
    gc_check(!str_starts_with(realpath($path),realpath(dirname(__DIR__)).'/'),'Outside document root');
    gc_check((fileperms($path)&0777)===0600,'Private file permissions');
    $saved=file_get_contents($path);
    foreach(['invalid','{}',json_encode(array_replace($credential,['token_uri'=>'https://evil.test/token'])),json_encode(array_replace($credential,['private_key'=>'bad']))] as $bad) {
        try {google_save_configuration('sc-domain:example.test',$bad); throw new RuntimeException('Invalid accepted');} catch(InvalidArgumentException $e) {}
        gc_check(file_get_contents($path)===$saved,'Invalid replacement preserves working credentials');
    }
    $calls=0; $ttl=3600; $denied=false; $scopes=[]; $submitted=0;
    $http=static function($url,$body=null,$headers=[],$max=0,$method=null) use (&$calls,&$ttl,&$denied,&$scopes,&$submitted,$key): array {
        if ($url==='https://oauth2.googleapis.com/token') {
            $calls++; parse_str($body,$form); $parts=explode('.',$form['assertion']); $decode=static fn($v)=>base64_decode(strtr($v,'-_','+/'));
            gc_check(openssl_verify($parts[0].'.'.$parts[1],$decode($parts[2]),openssl_pkey_get_details($key)['key'],OPENSSL_ALGO_SHA256)===1,'JWT signature');
            $claims=json_decode($decode($parts[1]),true); $scopes[]=$claims['scope'];
            return ['status'=>200,'body'=>json_encode(['access_token'=>'secret-test-token','expires_in'=>$ttl])];
        }
        gc_check(in_array('Authorization: Bearer secret-test-token',$headers,true),'Shared bearer token');
        if (str_contains($url,'/sitemaps/')) { gc_check($method==='PUT','Sitemap PUT'); $submitted++; return ['status'=>204,'body'=>'']; }
        if (str_contains($url,'urlInspection')) return ['status'=>200,'body'=>'{}'];
        return ['status'=>$denied?403:200,'body'=>'{"permissionLevel":"siteOwner"}'];
    };
    gc_check(google_test_connection($http)==='Connected','Property connection');
    google_access_token([],$http); gc_check($calls===1,'Unexpired token reused');
    $denied=true; gc_check(google_test_connection($http)==='Property Access Denied','Property denial'); $denied=false;
    google_submit_pending_sitemap($http); gc_check($submitted===1 && !google_state()['submission_pending'],'Queued sitemap submitted');
    index_checker_inspect('https://example.test/game/test/',index_checker_config(),$http);
    gc_check(in_array('https://www.googleapis.com/auth/webmasters',$scopes,true) && in_array('https://www.googleapis.com/auth/webmasters.readonly',$scopes,true),'Only required read/write scopes');
    $credential['client_email']='replacement@test-project.iam.gserviceaccount.com'; $ttl=0;
    google_save_configuration('https://example.test/',json_encode($credential));
    $before=$calls; google_access_token([],$http); google_access_token([],$http); gc_check($calls===$before+2,'Expired token refreshed automatically after replacement');
    gc_check(google_test_connection(static fn()=>['status'=>401,'body'=>'sensitive-error'])==='Authentication Failed','Safe auth failure');
    $pdo=blogs_pdo(); $_SESSION['user']=auth_authenticate_credentials('super_user','Visibility test password 123!');
    foreach(['admin','editor'] as $role) admin_user_save(['username'=>$role,'display_name'=>$role,'password'=>'Visibility test password 123!','active'=>1,'role'=>$role]);
    foreach(['admin','editor'] as $role) {
        [$status]=gc_handler(['path'=>'/admin/settings/seo/index.php','role'=>$role,'post'=>['google_action'=>'remove','google_property'=>'https://example.test/']]);
        gc_check($status===403,'Only super user changes credentials');
    }
    [$status,$body]=gc_handler(['path'=>'/admin/settings/seo/index.php','role'=>'admin','method'=>'GET']);
    gc_check($status===200 && !str_contains($body,'PRIVATE KEY') && !str_contains($body,'secret-test-token') && !str_contains($body,'name="service_account"'),'Admin safe status only');
    [$status,$body]=gc_handler(['path'=>'/api/settings/seo.php','method'=>'GET']);
    gc_check($status===200 && !str_contains($body,'PRIVATE KEY') && !str_contains($body,'secret-test-token') && !str_contains($body,'private_key'),'Public settings contain no secrets');
    [$status]=gc_handler(['path'=>'/admin/settings/seo/index.php','role'=>'super_user','bad_csrf'=>true,'post'=>['google_action'=>'remove']]); gc_check($status===403,'CSRF required');
    google_save_configuration('https://example.test/',null,true); gc_check(!google_safe_status()['configured'] && !str_contains(file_get_contents($path),'PRIVATE KEY'),'Removal clears stored secret');
    echo "PASS: validation/atomic replacement, private path/modes, signed tokens/cache/expiry, property testing, shared inspection/sitemap auth, removal, role/CSRF restrictions and public API secrecy. Google transport mocked.\n";
} finally { gc_remove($dir); }
