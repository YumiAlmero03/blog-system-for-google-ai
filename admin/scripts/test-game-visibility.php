<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (($argv[1] ?? '') === '--handler') {
    $request = json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    ini_set('session.save_path', getenv('APP_STORAGE_DIR'));
    require_once __DIR__ . '/../includes/auth.php';
    $_SERVER['SCRIPT_NAME'] = $request['path'];
    $_SERVER['REQUEST_METHOD'] = $request['method'] ?? 'POST';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    if (isset($request['role'])) {
        $user = auth_authenticate_credentials($request['role'], 'Visibility test password 123!');
        auth_mark_authenticated($user);
        $_COOKIE[AUTH_ACCESS_COOKIE] = jwt_sign(['aud'=>'admin','scope'=>'admin:access','sub'=>$user['id'],'user_id'=>$user['id'],'role'=>$user['role'],'ver'=>$user['auth_version'],'sid'=>$_SESSION['auth_binding'],'iat'=>time(),'nbf'=>time(),'exp'=>time()+900],auth_jwt_secret());
    }
    $_POST = $request['post'] ?? []; $_GET = $request['get'] ?? [];
    if (isset($request['role'])) $_POST['csrf_token'] = !empty($request['bad_csrf']) ? 'bad' : csrf_token();
    register_shutdown_function(static function (): void { fwrite(STDERR, 'STATUS:' . (http_response_code() ?: 200)); });
    require dirname(__DIR__, 2) . $request['path']; exit;
}
$dir = sys_get_temp_dir() . '/game-visibility-' . bin2hex(random_bytes(6)); mkdir($dir,0700);
putenv('APP_STORAGE_DIR='.$dir); putenv('GAME_SITEMAP_OUTPUT_DIR='.$dir);
putenv('ADMIN_USERNAME=super_user'); putenv('ADMIN_PASSWORD_HASH='.password_hash('Visibility test password 123!',PASSWORD_DEFAULT));
putenv('ADMIN_JWT_SECRET='.str_repeat('visibility-test-',5)); ini_set('session.save_path',$dir);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/public-detail.php';
require_once __DIR__ . '/../includes/slot-content.php';
function visibility_check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function visibility_handler(array $request): array {
    $process=proc_open([PHP_BINARY,__FILE__,'--handler'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],json_encode($request)); fclose($pipes[0]);
    $body=stream_get_contents($pipes[1]); fclose($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[2]);
    visibility_check(proc_close($process)===0,'Handler failed: '.$errors);
    visibility_check(preg_match('/^STATUS:(\d+)$/',$errors,$match)===1,'Handler warnings: '.$errors);
    return [(int)$match[1],json_decode($body,true),$body];
}
function visibility_remove(string $dir): void { foreach (scandir($dir) as $file) { if ($file==='.' || $file==='..') continue; $path=$dir.'/'.$file; is_dir($path) ? visibility_remove($path) : unlink($path); } rmdir($dir); }
try {
    $pdo=blogs_pdo();
    $pdo->exec("INSERT INTO game_providers(api_id,name) VALUES(1,'Approved'),(2,'Blocked')");
    $insert=$pdo->prepare('INSERT INTO games(api_id,name,slug,provider_id,provider,provider_slug,type,type_slug,published,done_processing,restrictions,megaways,featured,updated_at) VALUES(?,?,?,?,?,?,"Slots","slots",?,?,?,?,1,1)');
    $cases=[['visible',1,'Approved','approved',1,1,'[]',1],['blocked',2,'Blocked','blocked',1,1,'[]',1],['hidden',1,'Approved','approved',1,1,'[]',1],['ph',1,'Approved','approved',1,1,'["PH"]',1],['unfinished',1,'Approved','approved',1,0,'[]',1],['draft',1,'Approved','approved',0,1,'[]',1],['ordinary',1,'Approved','approved',1,1,'[]',0],['text-only',null,'Text Provider','text-provider',1,1,'[]',0]];
    foreach ($cases as $index=>$case) $insert->execute(array_merge([$index+1,$case[0]],$case));
    // Simulate upgrading the earlier schema with real existing rows.
    $pdo->exec('ALTER TABLE games DROP COLUMN is_viewable');
    games_schema($pdo);
    visibility_check((int)$pdo->query('SELECT MIN(is_viewable) FROM games')->fetchColumn()===1,'Existing games default visible');
    visibility_check(count(array_filter(game_provider_settings_rows($pdo),static fn($p)=>(int)$p['approved']===1))===3,'Existing providers default approved');
    $_SESSION['user']=auth_authenticate_credentials('super_user','Visibility test password 123!');
    foreach (['admin','editor'] as $role) admin_user_save(['username'=>$role,'display_name'=>$role,'password'=>'Visibility test password 123!','active'=>1,'role'=>$role]);
    game_provider_approval_save($pdo,'slug:blocked','0');
    $pdo->exec('UPDATE games SET is_viewable=0 WHERE slug="hidden"');
    games_schema($pdo);
    visibility_check((int)$pdo->query('SELECT approved FROM game_provider_settings')->fetchColumn()===0,'Repeated migration preserves approval');
    visibility_check(public_game_find('visible')!==null,'Approved/viewable detail');
    foreach (['blocked','hidden','ph','unfinished','draft'] as $slug) {
        visibility_check(public_game_find($slug)===null,'Private detail '.$slug);
        foreach (array_filter(['/api/game.php','/game/index.php'], static fn($path) => is_file(dirname(__DIR__,2).$path)) as $path) {
            [$status]=visibility_handler(['path'=>$path,'method'=>'GET','get'=>['slug'=>$slug]]);
            visibility_check($status===404,'Public 404 '.$path.' '.$slug);
        }
    }
    foreach (['GET','POST'] as $method) {
        foreach ([[],['provider'=>'approved'],['search'=>'visible'],['type'=>'slots','megaways'=>'1','featured'=>'1']] as $filters) {
            [$status,$data]=visibility_handler(['path'=>'/api/slot-list.php','method'=>$method,'get'=>$filters,'post'=>$filters]);
            $slugs=array_column($data['slots'],'slug');
            visibility_check($status===200 && in_array('visible',$slugs,true),'Filter keeps eligible game');
            visibility_check(!array_intersect($slugs,['blocked','hidden','ph','unfinished','draft']),'Filters cannot bypass eligibility');
            if (isset($filters['megaways']) || isset($filters['search'])) visibility_check($slugs===['visible'],'Megaways/search exact results');
        }
    }
    [$status,$data]=visibility_handler(['path'=>'/api/slot-list.php','method'=>'GET','get'=>['count'=>'1','page'=>'2']]);
    visibility_check($status===200 && $data['pagination']['total']===3 && count($data['slots'])===1,'Eligible pagination count');
    [$status,$data]=visibility_handler(['path'=>'/api/provider-list.php','method'=>'GET']);
    visibility_check($status===200 && !in_array('blocked',array_column($data['providers'],'slug'),true),'Public providers exclude blocked');
    $approved=array_values(array_filter($data['providers'],static fn($p)=>$p['slug']==='approved'))[0];
    visibility_check($approved['gameCount']===2,'Provider counts only eligible games');
    game_visibility_refresh();
    $xml=file_get_contents($dir.'/sitemap-games-1.xml');
    foreach (['blocked','hidden','ph','unfinished','draft'] as $slug) visibility_check(!str_contains($xml,'/game/'.$slug.'/'),'Sitemap excludes '.$slug);
    visibility_check(str_contains($xml,'/game/visible/'),'Sitemap includes eligible game');
    [$status,,$html]=visibility_handler(['path'=>'/admin/slots.php','role'=>'editor','method'=>'GET']);
    visibility_check($status===200 && str_contains($html,'blocked') && str_contains($html,'hidden'),'Admin sees hidden games');
    foreach (['admin','super_user'] as $role) {
        [$status,,$html]=visibility_handler(['path'=>'/admin/settings/providers/index.php','role'=>$role,'method'=>'GET']);
        visibility_check($status===200 && str_contains($html,'Blocked'),'Admin sees all providers');
    }
    [$status]=visibility_handler(['path'=>'/admin/settings/providers/index.php','role'=>'editor','post'=>['provider_key'=>'slug:blocked','approved'=>'1']]);
    visibility_check($status===403,'Editor cannot approve providers');
    [$status]=visibility_handler(['path'=>'/admin/settings/providers/index.php','role'=>'admin','bad_csrf'=>true,'post'=>['provider_key'=>'slug:blocked','approved'=>'1']]);
    visibility_check($status===403,'Provider CSRF');
    [$status]=visibility_handler(['path'=>'/admin/settings/providers/index.php','role'=>'admin','post'=>['provider_key'=>'slug:approved','approved'=>'0']]);
    visibility_check($status===200 && public_game_find('visible')===null,'Admin approval update');
    visibility_check(!str_contains(file_get_contents($dir.'/sitemap-games-1.xml'),'/game/visible/'),'Approval save refreshes sitemap');
    [$status,$data]=visibility_handler(['path'=>'/api/slot-list.php','method'=>'GET']);
    visibility_check($status===200 && array_column($data['slots'],'slug')===['text-only'],'Approval save clears cached lists');
    game_provider_approval_save($pdo,'slug:approved',1); game_visibility_refresh();
    $id=(int)$pdo->query('SELECT id FROM games WHERE slug="visible"')->fetchColumn();
    $input=['id'=>(string)$id,'name'=>'visible','short_description'=>'Description','long_description'=>'<p>Body</p>','rtp'=>'95','volatility'=>'High','is_viewable'=>'0'];
    [$status,$data]=visibility_handler(['path'=>'/admin/slot-save.php','role'=>'editor','post'=>$input]);
    visibility_check($status===200 && $data['ok'] && public_game_find('visible')===null,'Editor visibility update');
    visibility_check(!str_contains(file_get_contents($dir.'/sitemap-games-1.xml'),'/game/visible/'),'Editor update refreshes sitemap');
    [$status]=visibility_handler(['path'=>'/admin/slot-save.php','role'=>'editor','post'=>$input+['done_processing'=>'1']]);
    visibility_check($status===422,'Protected fields stay protected');
    [$status]=visibility_handler(['path'=>'/admin/slot-save.php','role'=>'editor','post'=>array_replace($input,['is_viewable'=>'yes'])]);
    visibility_check($status===422,'Invalid visibility rejected');
    [$status]=visibility_handler(['path'=>'/admin/slots.php','role'=>'editor','post'=>['slot_id'=>(string)$id,'action'=>'visibility','is_viewable'=>'1']]);
    visibility_check($status===303 && public_game_find('visible')!==null,'Slots list toggle');
    $pdo->exec('UPDATE games SET is_viewable=0 WHERE api_id=1');
    file_put_contents($dir.'/import.json',json_encode(['data'=>[['id'=>1,'name'=>'visible','slug'=>'visible','provider'=>['id'=>1,'name'=>'Approved'],'published'=>1]]]));
    putenv('SLOTSLAUNCH_API_TOKEN=isolated-test-token');
    $process=proc_open([PHP_BINARY,__DIR__ . '/import-slotslaunch-games.php','--url=file://'.$dir.'/import.json','--max-pages=1'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    visibility_check(proc_close($process)===0 && str_contains($out,'Imported 1 game(s).'),'Local fixture import: '.$errors);
    visibility_check((int)$pdo->query('SELECT is_viewable FROM games WHERE api_id=1')->fetchColumn()===0,'Reimport preserves hidden state');
    visibility_check((int)$pdo->query('SELECT approved FROM game_provider_settings WHERE provider_key="slug:blocked"')->fetchColumn()===0,'Import preserves approval');
    // The settings primary key supplies an indexed lookup inside the normal SQL query.
    $plan=$pdo->query('EXPLAIN QUERY PLAN SELECT * FROM games WHERE '.game_public_eligibility_sql())->fetchAll();
    visibility_check(str_contains(json_encode($plan),'sqlite_autoindex_game_provider_settings'),'Approval lookup uses indexed key');
    [$status]=visibility_handler(['path'=>'/admin/games/settings.php','role'=>'editor','post'=>['enabled'=>'0']]);
    visibility_check($status===403 && games_enabled(),'Editors cannot disable module');
    [$status]=visibility_handler(['path'=>'/admin/games/settings.php','role'=>'admin','bad_csrf'=>true,'post'=>['enabled'=>'0']]);
    visibility_check($status===403 && games_enabled(),'Module switch requires CSRF');
    [$status]=visibility_handler(['path'=>'/admin/games/settings.php','role'=>'admin','post'=>['enabled'=>'0']]);
    visibility_check($status===200 && !games_enabled(),'Administrator can disable module');
    [$status,$data]=visibility_handler(['path'=>'/api/slot-list.php','method'=>'GET']);
    visibility_check($status===200 && $data['slots']===[],'Disabled module hides cached game list');
    [$status,$data]=visibility_handler(['path'=>'/api/provider-list.php','method'=>'GET','get'=>['sample'=>'1']]);
    visibility_check($status===200 && $data['providers']===[],'Sample providers cannot bypass disabled module');
    visibility_check(public_game_find('text-only')===null,'Disabled game detail hidden');
    visibility_check(glob($dir.'/sitemap-games-*.xml')===[],'Disabled module removes game sitemap chunks');
    [$status,$data,$html]=visibility_handler(['path'=>'/admin/games/index.php','role'=>'editor','method'=>'GET']);
    visibility_check($status===200 && str_contains($html,'/admin/games/'),'Admin game management remains available');
    [$status]=visibility_handler(['path'=>'/admin/games/settings.php','role'=>'admin','post'=>['enabled'=>'1']]);
    visibility_check($status===200 && games_enabled() && public_game_find('text-only')!==null,'Reenable restores public eligibility');
    echo "PASS: migration/defaults/import preservation, approval and visibility controls, public API/page 404s, PH/processing/publication rules, filters/pagination, providers/counts, sitemap refresh/cache invalidation, admin/editor permissions, CSRF and indexed approval lookup.\n";
} finally { visibility_remove($dir); }
