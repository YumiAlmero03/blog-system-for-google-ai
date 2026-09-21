<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$dir=sys_get_temp_dir().'/index-checker-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
putenv('GOOGLE_PRIVATE_DIR='.$dir.'/private'); putenv('APP_STORAGE_DIR='.$dir); putenv('SITE_BASE_URL=https://example.test');
putenv('ADMIN_USERNAME=super_user'); putenv('ADMIN_PASSWORD_HASH='.password_hash('Visibility test password 123!',PASSWORD_DEFAULT));
putenv('ADMIN_JWT_SECRET='.str_repeat('index-check-test-',5)); ini_set('session.save_path',$dir);
require_once __DIR__ . '/../includes/index-checker.php';
require_once __DIR__ . '/../includes/auth.php';
function ix_check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function ix_remove(string $path): void { foreach (scandir($path) as $f) { if ($f==='.' || $f==='..') continue; $file=$path.'/'.$f; is_dir($file) ? ix_remove($file) : unlink($file); } rmdir($path); }
function ix_handler(array $request): array {
    // Reuse the existing isolated auth/handler harness (no live HTTP required).
    $p=proc_open([PHP_BINARY,__DIR__ . '/test-game-visibility.php','--handler'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],json_encode($request)); fclose($pipes[0]); $body=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    ix_check(proc_close($p)===0 && preg_match('/^STATUS:(\d+)$/',$error,$m)===1,'Handler error: '.$error);
    return [(int)$m[1],$body];
}
function ix_xml(string $root,array $urls): string {
    $child=$root==='sitemapindex' ? 'sitemap' : 'url';
    return '<'.$root.' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('',array_map(static fn($u)=>'<'.$child.'><loc>'.htmlspecialchars($u,ENT_XML1).'</loc></'.$child.'>',$urls)).'</'.$root.'>';
}
try {
    $pdo=index_checker_pdo(); $config=index_checker_config();
    $pdo->exec('INSERT INTO games(api_id,name,slug,provider,provider_slug,published,done_processing,restrictions,is_viewable) VALUES
        (1,"Good","good","Good","good",1,1,"[]",1),(2,"Hidden","hidden","Good","good",1,1,"[]",0),
        (3,"PH","ph","Good","good",1,1,"PH",1),(4,"Unprocessed","unprocessed","Good","good",1,0,"[]",1),
        (5,"Blocked","blocked","Blocked","blocked",1,1,"[]",1)');
    game_provider_approval_save($pdo,'slug:blocked',0);
    foreach (['public'=>'published','draft'=>'draft','future'=>'scheduled'] as $slug=>$status) {
        $post=blog_normalize_existing(['slug'=>$slug,'title'=>$slug,'category'=>'Guides','content'=>'Body','excerpt'=>'Excerpt','status'=>$status]);
        if ($status==='scheduled') $post['scheduledAt']=time()+86400;
        blogs_upsert($post);
    }
    $site='https://example.test';
    $maps=[
        $site.'/sitemap-index.xml'=>ix_xml('sitemapindex',[$site.'/sitemap-pages.xml',$site.'/sitemap-blog.php',$site.'/sitemap-games-1.xml']),
        $site.'/sitemap-pages.xml'=>ix_xml('urlset',[$site.'/',$site.'/about/',$site.'/noindex/',$site.'/admin/',$site.'/api/game.php',$site.'/promo-code/secret/','https://evil.test/']),
        $site.'/sitemap-blog.php'=>ix_xml('urlset',[$site.'/blog/public/',$site.'/blog/draft/',$site.'/blog/future/']),
        $site.'/sitemap-games-1.xml'=>ix_xml('urlset',array_map(static fn($s)=>$site.'/game/'.$s.'/',['good','hidden','ph','unprocessed','blocked'])),
    ];
    $fetch=static function(string $url) use (&$maps): array {
        if (str_contains($url,'sitemap')) { if (!isset($maps[$url])) throw new RuntimeException('Missing fixture'); return ['status'=>200,'body'=>$maps[$url],'headers'=>[]]; }
        return ['status'=>200,'body'=>str_contains($url,'noindex') ? '<meta name="robots" content="noindex,follow">' : '<html><title>Page</title></html>','headers'=>[]];
    };
    $sets=index_checker_eligible_sets($pdo); $urls=index_checker_discover($config,$sets,$fetch);
    ix_check(count($urls)===5 && isset($urls[$site.'/game/good/']), 'Child/chunk discovery and exclusion rules');
    index_checker_sync($pdo,$urls,time()); index_checker_sync($pdo,$urls,time());
    ix_check((int)$pdo->query('SELECT COUNT(*) FROM index_checks')->fetchColumn()===5,'Unique sync');
    ix_check((int)$pdo->query('SELECT COUNT(*) FROM index_checks WHERE check_status="Pending"')->fetchColumn()===5,'New URLs pending');
    $id=(int)$pdo->query('SELECT id FROM index_checks WHERE url="https://example.test/about/"')->fetchColumn();
    $pdo->exec('UPDATE index_checks SET check_status="Indexed",last_checked_at=123,next_check_at=9999999999 WHERE id='.$id);
    $removed=$urls; unset($removed[$site.'/about/']); index_checker_sync($pdo,$removed,time());
    $row=$pdo->query('SELECT * FROM index_checks WHERE id='.$id)->fetch();
    ix_check((int)$row['in_sitemap']===0 && $row['check_status']==='Indexed' && (int)$row['last_checked_at']===123,'Removed membership preserves result');
    index_checker_sync($pdo,$urls,time());
    ix_check((int)$pdo->query('SELECT in_sitemap FROM index_checks WHERE id='.$id)->fetchColumn()===1,'Reactivation');
    $original=$maps[$site.'/sitemap-games-1.xml']; $maps[$site.'/sitemap-games-1.xml']='<broken>';
    try { index_checker_run($pdo,$config,$fetch,static fn()=>throw new RuntimeException('Must not inspect'),static fn()=>null); throw new LogicException('Malformed sitemap accepted'); }
    catch (RuntimeException $e) { ix_check(!$e instanceof LogicException,'Discovery rejects malformed XML'); }
    ix_check((int)$pdo->query('SELECT SUM(in_sitemap) FROM index_checks')->fetchColumn()===5,'Discovery failure preserves membership');
    $maps[$site.'/sitemap-games-1.xml']=$original;
    $calls=[];
    $inspect=static function(string $url) use (&$calls): array {
        $calls[]=$url;
        return ['status'=>200,'headers'=>[],'body'=>json_encode(['inspectionResult'=>['indexStatusResult'=>['verdict'=>str_contains($url,'/game/') ? 'NEUTRAL' : 'PASS','coverageState'=>'Fixture coverage','indexingState'=>'INDEXING_ALLOWED','robotsTxtState'=>'ALLOWED','lastCrawlTime'=>'2026-09-01T00:00:00Z','googleCanonical'=>$url,'userCanonical'=>$url]]])];
    };
    $result=index_checker_run($pdo,$config,$fetch,$inspect,static fn()=>null);
    ix_check($result['processed']===3 && count($calls)===3,'Only due eligible URLs; noindex consumes no Google quota');
    $game=$pdo->query('SELECT * FROM index_checks WHERE content_type="game"')->fetch();
    ix_check($game['check_status']==='Not Indexed' && (int)$game['last_checked_at']>0 && (int)$game['next_check_at']>time(),'Not indexed mapping and schedule');
    ix_check((int)$pdo->query('SELECT COUNT(*) FROM index_checks WHERE check_status="Indexed"')->fetchColumn()===3,'Indexed mapping');
    $before=count($calls); index_checker_run($pdo,$config,$fetch,$inspect,static fn()=>null);
    ix_check(count($calls)===$before,'Repeated cron does not recheck scheduled results');
    index_checker_queue($pdo,[$game['id']]); ix_check(count(index_checker_due($pdo,100,time()))>=1,'Manual recheck due');
    $quota=static fn(string $url): array => ['status'=>429,'headers'=>['retry-after'=>['7200']],'body'=>'{"error":{"status":"RESOURCE_EXHAUSTED"}}'];
    index_checker_queue($pdo,[$id]);
    $result=index_checker_run($pdo,$config,$fetch,$quota,static fn()=>null);
    ix_check($result['state']==='Quota deferred' && $result['processed']===1,'429 stops worker');
    ix_check((int)$pdo->query('SELECT COUNT(*) FROM index_checks WHERE queued=1')->fetchColumn()===1,'Remaining queue preserved');
    ix_check(!index_checker_reserve($pdo,$config,time()),'Persisted cooldown blocks repeated runs');
    $pdo->exec('DELETE FROM index_check_limits'); $pdo->exec('DELETE FROM index_check_attempts');
    $limited=$config; $limited['daily']=1;
    ix_check(index_checker_reserve($pdo,$limited,time()) && !index_checker_reserve($pdo,$limited,time()),'Persistent daily allowance');
    $pdo->exec('DELETE FROM index_check_attempts'); $limited=$config; $limited['minute']=1;
    ix_check(index_checker_reserve($pdo,$limited,time()) && !index_checker_reserve($pdo,$limited,time()),'Persistent minute allowance');
    index_checker_result($pdo,$game,['status'=>200,'headers'=>[],'body'=>'{}'],$config,time());
    ix_check($pdo->query('SELECT check_status FROM index_checks WHERE id='.(int)$game['id'])->fetchColumn()==='Unknown','Missing result is Unknown');
    index_checker_result($pdo,$game,['status'=>503,'headers'=>[],'body'=>'{"secret":"never-store-this"}'],$config,time());
    ix_check(!str_contains(json_encode($pdo->query('SELECT * FROM index_checks')->fetchAll()),'never-store-this'),'Raw errors not persisted');
    ix_check(index_checker_in_property($site.'/blog/public/','sc-domain:example.test'),'Domain property');
    foreach (['https://example.test.evil.test/','https://evil-example.test/','https://user:pass@example.test/'] as $url) ix_check(!index_checker_in_property($url,'sc-domain:example.test'),'Property boundary');
    ix_check(index_checker_in_property($site.'/blog/public/',$site.'/blog/') && !index_checker_in_property($site.'/blogs/',$site.'/blog/'),'URL prefix property');
    foreach ([$site.'/admin/',$site.'/%61dmin/',$site.'/a/../admin/',$site.'/promo/x/',$site.'/?preview=1'] as $url) ix_check(!index_checker_safe_path($url),'Private path denial');
    ix_check(!index_checker_page_allowed(['status'=>200,'headers'=>['x-robots-tag'=>['googlebot: noindex']],'body'=>'<html></html>']),'HTTP noindex');
    // Real RS256 signing with an isolated key, mocked Google token/inspection transport.
    $key=openssl_pkey_new(['private_key_bits'=>2048]); openssl_pkey_export($key,$pem);
    file_put_contents($dir.'/google-private.json',json_encode(['project_id'=>'test-project','token_uri'=>'https://oauth2.googleapis.com/token','type'=>'service_account','client_email'=>'test@example.test','private_key'=>$pem])); chmod($dir.'/google-private.json',0600);
    $config['credentials']=$dir.'/google-private.json'; $authRequests=0;
    $http=static function(string $url,?string $body=null,array $headers=[]) use (&$authRequests,$key,$site): array {
        if ($url==='https://oauth2.googleapis.com/token') {
            $authRequests++; parse_str($body,$form); $parts=explode('.',$form['assertion']);
            $decode=static fn($s)=>base64_decode(strtr($s,'-_','+/'));
            ix_check(openssl_verify($parts[0].'.'.$parts[1],$decode($parts[2]),openssl_pkey_get_details($key)['key'],OPENSSL_ALGO_SHA256)===1,'Signed service-account JWT');
            return ['status'=>200,'headers'=>[],'body'=>'{"access_token":"private-test-token","expires_in":3600}'];
        }
        $data=json_decode($body,true); ix_check($data['inspectionUrl']===$site.'/' && $data['siteUrl']===$site.'/','Inspection request property');
        ix_check(in_array('Authorization: Bearer private-test-token',$headers,true),'Bearer auth');
        return ['status'=>200,'headers'=>[],'body'=>'{"inspectionResult":{"indexStatusResult":{"verdict":"PASS"}}}'];
    };
    ix_check(index_checker_inspect($site.'/',$config,$http)['status']===200,'Valid inspection transport');
    index_checker_inspect($site.'/',$config,$http); ix_check($authRequests===1,'Token reused in memory');
    $_SESSION['user']=auth_authenticate_credentials('super_user','Visibility test password 123!');
    foreach (['admin','editor'] as $role) admin_user_save(['username'=>$role,'display_name'=>$role,'password'=>'Visibility test password 123!','active'=>1,'role'=>$role]);
    putenv('GSC_SERVICE_ACCOUNT_FILE='.$dir.'/google-private.json'); putenv('GSC_PROPERTY=sc-domain:example.test');
    foreach (['GET','POST'] as $method) {
        [$status]=ix_handler(['path'=>'/admin/index-checker/index.php','role'=>'editor','method'=>$method,'post'=>['recheck'=>(string)$id]]); ix_check($status===403,'Editor denied');
    }
    [$status]=ix_handler(['path'=>'/admin/index-checker/index.php','role'=>'admin','bad_csrf'=>true,'post'=>['recheck'=>(string)$id]]); ix_check($status===403,'CSRF');
    foreach (['admin','super_user'] as $role) {
        [$status,$body]=ix_handler(['path'=>'/admin/index-checker/index.php','role'=>$role,'method'=>'GET','get'=>['search'=>'example','status'=>'Indexed','sort'=>'oldest']]);
        ix_check($status===200 && str_contains($body,'Index Checker'),'Admin page');
        ix_check(!str_contains($body,'private-test-token') && !str_contains($body,'google-private.json'),'No HTML credentials');
    }
    [$status]=ix_handler(['path'=>'/admin/index-checker/index.php','role'=>'admin','post'=>['recheck'=>(string)$id]]); ix_check($status===303,'Manual form queues');
    [$status,$body]=ix_handler(['path'=>'/api/settings/seo.php','method'=>'GET']);
    ix_check($status===200 && !str_contains($body,'google-private') && !str_contains($body,'sc-domain:') && !str_contains($body,'private-test-token'),'Public SEO settings exclude private configuration');
    $unknown=$pdo->query('SELECT * FROM index_checks WHERE id='.(int)$game['id'])->fetch();
    $firstDelay=(int)$unknown['next_check_at']-(int)$unknown['last_checked_at'];
    index_checker_result($pdo,$unknown,['status'=>503,'headers'=>[],'body'=>'{}'],$config,time());
    $retry=$pdo->query('SELECT * FROM index_checks WHERE id='.(int)$game['id'])->fetch();
    ix_check((int)$retry['next_check_at']-(int)$retry['last_checked_at']>$firstDelay,'Repeated failures increase backoff');
    $lock=fopen($dir.'/index-checker.lock','c'); flock($lock,LOCK_EX);
    ix_check(index_checker_run($pdo,$config,$fetch,$inspect,static fn()=>null)['state']==='Already running','Overlap lock');
    flock($lock,LOCK_UN); fclose($lock);
    foreach (['http://127.0.0.1/','http://10.0.0.1/','http://169.254.169.254/'] as $url) {
        try { index_checker_http($url); throw new LogicException('Private request accepted'); }
        catch (RuntimeException $e) { ix_check(!$e instanceof LogicException,'Private network blocked before request'); }
    }
    $originalIndex=$maps[$site.'/sitemap-index.xml'];
    $maps[$site.'/sitemap-index.xml']=ix_xml('sitemapindex',['https://other.test/sitemap.xml']);
    try { index_checker_discover($config,$sets,$fetch); throw new LogicException('Cross-origin sitemap accepted'); }
    catch (RuntimeException $e) { ix_check(!$e instanceof LogicException,'Cross-origin sitemap rejected'); }
    $maps[$site.'/sitemap-index.xml']=$originalIndex;
    for ($i=0;$i<101;$i++) blogs_upsert(blog_normalize_existing(['slug'=>'sitemap-extra-'.$i,'title'=>'Sitemap extra '.$i,'category'=>'Guides','content'=>'Body','excerpt'=>'Excerpt','status'=>'published']));
    [$status,$body]=ix_handler(['path'=>'/sitemap-blog.php','method'=>'GET']);
    ix_check($status===200 && substr_count($body,'<loc>')===103,'Blog sitemap includes all 102 public posts plus hub, without 100-row truncation');
    ix_check(!str_contains($body,'/blog/draft/') && !str_contains($body,'/blog/future/'),'Blog sitemap visibility preserved');
    echo "PASS: sitemap index/children/chunks, eligibility, atomic membership/history/reactivation, due queue, status mapping/backoff, persistent quota/cooldown, service-account signing and inspection transport, admin queue/filter access, CSRF, editor denial and credential privacy. Google responses were mocked; no live quota consumed.\n";
} finally { ix_remove($dir); }
