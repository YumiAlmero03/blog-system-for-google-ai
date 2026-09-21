<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$dir=sys_get_temp_dir().'/indexnow-test-'.bin2hex(random_bytes(5));mkdir($dir,0700);mkdir($dir.'/public');
putenv('APP_STORAGE_DIR='.$dir);putenv('INDEXNOW_PUBLIC_DIR='.$dir.'/public');putenv('GAME_SITEMAP_OUTPUT_DIR='.$dir.'/public');putenv('GOOGLE_PRIVATE_DIR='.$dir.'/private');putenv('SITE_BASE_URL=https://example.test');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/indexnow.php';
require_once __DIR__ . '/../includes/index-checker.php';
function in_check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function in_remove(string $dir): void { foreach(scandir($dir) as $f) if ($f!=='.'&&$f!=='..') { $p=$dir.'/'.$f;is_dir($p)?in_remove($p):unlink($p); }rmdir($dir); }
try {
    $_SESSION['user']=['role'=>'super_user'];$pdo=index_checker_pdo();
    indexnow_save(true,'',true);$generated=indexnow_settings()['key'];in_check(indexnow_key_valid($generated),'Generated key');
    in_check(file_get_contents($dir.'/public/'.$generated.'.txt')===$generated,'Exact plain-text file');
    indexnow_save(true,'Valid-IndexNow123');in_check(!is_file($dir.'/public/'.$generated.'.txt'),'Old generated file removed');
    foreach(['short','space key','bad/key!123',str_repeat('a',129)] as $bad) {
        try {indexnow_save(true,$bad);throw new RuntimeException('Invalid accepted');}catch(InvalidArgumentException $e){}
    }
    file_put_contents($dir.'/public/CollisionKey.txt','unrelated');
    try {indexnow_save(true,'CollisionKey');throw new RuntimeException('Collision overwritten');}catch(InvalidArgumentException $e){}
    foreach(['editor','admin'] as $role) {$_SESSION['user']=['role'=>$role];try {indexnow_save(true,'ForbiddenKey');throw new RuntimeException('Unauthorized');}catch(DomainException $e){}}
    $_SESSION['user']=['role'=>'super_user'];
    $code=202;$batches=[];
    $http=static function($url,$body=null,$headers=[],$max=null) use (&$code,&$batches): array {
        if ($body===null) return ['status'=>200,'body'=>'Valid-IndexNow123'];
        in_check($url==='https://api.indexnow.org/indexnow','Standard endpoint');$data=json_decode($body,true);
        in_check($data['host']==='example.test'&&$data['keyLocation']==='https://example.test/Valid-IndexNow123.txt','Host/key location');$batches[]=$data['urlList'];
        return ['status'=>$code,'body'=>'','headers'=>['retry-after'=>['1200']]];
    };
    in_check(indexnow_test($http)===202&&indexnow_settings()['status']==='Verification Pending','Pending accepted');
    $b=blog_normalize_existing(['slug'=>'public-blog','title'=>'Public','category'=>'Guides','excerpt'=>'Excerpt','content'=>'First']);blogs_upsert($b);
    $url=public_url('/blog/public-blog/');
    in_check($pdo->query('SELECT COUNT(*) FROM indexing_notifications')->fetchColumn()==1,'New blog queued');
    for($i=0;$i<5;$i++){$b['content']='Edit '.$i;blogs_upsert($b);}
    in_check($pdo->query('SELECT COUNT(*) FROM indexing_notifications')->fetchColumn()==1,'Repeated edits deduped');
    $draft=$b;$draft['slug']=$draft['id']='draft';$draft['status']='draft';blogs_upsert($draft);
    in_check($pdo->query('SELECT COUNT(*) FROM indexing_notifications')->fetchColumn()==1,'Draft not queued');
    $pdo->exec("INSERT INTO games(api_id,name,slug,provider_slug,published,done_processing,is_viewable,restrictions) VALUES(1,'Public','public-game','ok',1,1,1,'[]'),(2,'PH','ph','ok',1,1,1,'[\"PH\"]'),(3,'Hidden','hidden','ok',1,1,0,'[]'),(4,'Blocked','blocked','blocked',1,1,1,'[]')");
    $pdo->exec("INSERT INTO game_provider_settings VALUES('slug:blocked',0,0)");
    indexing_reconcile($pdo,'game');
    in_check($pdo->query('SELECT COUNT(*) FROM indexing_notifications')->fetchColumn()==2,'Only eligible game queued');
    indexing_google_events($pdo);in_check($pdo->query('SELECT COUNT(*) FROM index_checks WHERE queued=1')->fetchColumn()==2,'Google independently queued');
    $code=429;indexnow_process_queue($pdo,$http);
    in_check($pdo->query('SELECT MIN(next_at) FROM indexing_notifications')->fetchColumn()>=time()+1199,'Retry After honored');
    $count=count($batches);indexnow_process_queue($pdo,$http);in_check(count($batches)===$count,'No tight retries');
    $pdo->exec("UPDATE indexing_notifications SET next_at=0; DELETE FROM indexing_jobs WHERE name='indexnow-cooldown'");
    $code=200;in_check(indexnow_process_queue($pdo,$http)===2&&count(end($batches))===2,'Batch accepted');
    in_check($pdo->query('SELECT COUNT(*) FROM indexing_notifications')->fetchColumn()==0,'Accepted queue drained');
    in_check($pdo->query('SELECT COUNT(*) FROM index_checks WHERE queued=1')->fetchColumn()==2,'Google queue independent');
    $pdo->exec("UPDATE games SET is_viewable=0 WHERE slug='public-game'");indexing_reconcile($pdo,'game');
    in_check($pdo->query('SELECT removed FROM indexing_notifications')->fetchColumn()==1,'Previously public removal queued');
    blogs_delete('public-blog');in_check($pdo->query('SELECT COUNT(*) FROM indexing_notifications WHERE removed=1')->fetchColumn()==2,'Public blog deletion queued');
    indexing_google_events($pdo);in_check($pdo->query('SELECT COUNT(*) FROM index_checks WHERE queued=1')->fetchColumn()==0,'No Google inspection of removed URLs');
    $code=202;in_check(indexnow_process_queue($pdo,$http)===2,'Removals accepted by IndexNow');
    $pdo->exec("UPDATE games SET is_viewable=1 WHERE slug='public-game'");indexing_reconcile($pdo,'game');
    in_check($pdo->query('SELECT removed FROM indexing_notifications')->fetchColumn()==0,'Reactivation queued');
    $old=$b;$old['content']='New after failure';blogs_upsert($old);
    indexnow_process_queue($pdo,static function(){throw new RuntimeException('Network offline');});
    $old['content']='Save still succeeds';blogs_upsert($old);in_check(blogs_find('public-blog')['content']==='Save still succeeds','Remote failure does not block publishing');
    foreach(['https://evil.test/blog/x/','https://example.test/admin/','https://example.test/blog/x?draft=1'] as $bad) in_check(!indexnow_url_allowed($bad),'Private/external denied');
    $safe=indexnow_public_settings();in_check(!isset($safe['key'])&&!isset($safe['private_key']),'Safe status fields');
    if (in_array('--http',$argv,true)) {
        $socket=stream_socket_server('tcp://127.0.0.1:0'); $address=stream_socket_get_name($socket,false); fclose($socket);
        $server=proc_open([PHP_BINARY,'-S',$address,'-t',$dir.'/public'],[0=>['pipe','r'],1=>['file',$dir.'/http.log','a'],2=>['file',$dir.'/http.log','a']],$pipes);
        try {
            usleep(300000); $curl=curl_init('http://'.$address.'/Valid-IndexNow123.txt'); curl_setopt($curl,CURLOPT_RETURNTRANSFER,true);
            $body=curl_exec($curl); in_check($body==='Valid-IndexNow123' && curl_getinfo($curl,CURLINFO_RESPONSE_CODE)===200,'Public key HTTP reachability');
            in_check(str_starts_with(curl_getinfo($curl,CURLINFO_CONTENT_TYPE),'text/plain'),'Plain-text response');
        } finally { proc_terminate($server); fclose($pipes[0]); proc_close($server); }
    }
    echo "PASS: generation/manual validation/file replacement/collision safety, permissions, key test/202, blog create/edit/delete, game eligibility/removal/reactivation, dedup/batching, Google independence and persisted retry/backoff. Network mocked.\n";
} finally {session_write_close();in_remove($dir);}
