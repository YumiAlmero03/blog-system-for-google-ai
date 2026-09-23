<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$dir=sys_get_temp_dir().'/game-images-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
putenv('APP_STORAGE_DIR='.$dir.'/data'); putenv('GAME_IMAGE_UPLOAD_DIR='.$dir.'/images'); putenv('SITE_BASE_URL=https://example.test');
require_once __DIR__ . '/import-slotslaunch-games.php';
require_once __DIR__ . '/../includes/game-public.php';
function gi_check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function gi_remove(string $dir): void { foreach(scandir($dir) as $name) { if ($name==='.' || $name==='..') continue; $p=$dir.'/'.$name; is_dir($p)?gi_remove($p):unlink($p); } rmdir($dir); }
try {
    $pdo=blogs_pdo(); $calls=0;
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3ioAAAAASUVORK5CYII=');
    $download=static function(string $url,string $path) use (&$calls,$png): void { $calls++; file_put_contents($path,$png); };
    $source='https://assets.slotslaunch.com/game.png?token=private-token';
    $input=['id'=>1,'name'=>'Image game','slug'=>'image-game','thumb'=>$source,'published'=>1,'provider'=>['id'=>1,'name'=>'Provider']];
    gi_check(import_game($pdo,$input,$download),'Import new game');
    $row=$pdo->query('SELECT * FROM games WHERE api_id=1')->fetch(); $local=$row['thumb'];
    gi_check(str_starts_with($local,'/uploads/games/game-1-') && game_image_local_valid($local),'Validated local DB image');
    gi_check((int)$row['done_processing']===0,'Image success does not bypass processing');
    import_game($pdo,$input,$download); gi_check($calls===1,'Cached image reused');
    $pdo->exec('UPDATE games SET done_processing=1,is_viewable=0 WHERE api_id=1');
    import_game($pdo,$input,$download); $row=$pdo->query('SELECT * FROM games WHERE api_id=1')->fetch();
    gi_check((int)$row['done_processing']===1 && (int)$row['is_viewable']===0,'Successful repeat preserves processing and visibility');
    $pdo->exec('UPDATE games SET is_viewable=1 WHERE api_id=1');
    gi_check(slot_list_item($row)['thumbnail']===$local,'Shared list local upload thumbnail');
    gi_check(public_game_payload(public_game_find('image-game'))['thumbnail']==='https://example.test'.$local,'Detail absolute thumbnail');
    foreach (['/api/slot-list.php','/api/game.php'] as $endpoint) {
        $runner='$_SERVER["REQUEST_METHOD"]="GET"; $_GET=["slug"=>"image-game"]; require '.var_export(dirname(__DIR__,2).$endpoint,true).';';
        $process=proc_open([PHP_BINARY,'-r',$runner],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $json=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        gi_check(proc_close($process)===0 && $errors==='','API execution'); $data=json_decode($json,true);
        $image=$data['game']['thumbnail'] ?? $data['slots'][0]['thumbnail'] ?? '';
        gi_check($image===($endpoint === '/api/game.php' ? 'https://example.test'.$local : $local) && !str_contains($image,'assets.slotslaunch'),'API uses local upload thumbnail');
    }
    $hash=hash_file('sha256',$dir.'/images/'.basename($local));
    ini_set('error_log',$dir.'/failures.log');
    $changed=$input; $changed['thumb']='https://assets.slotslaunch.com/new.png?token=private-token';
    import_game($pdo,$changed,static function($url,$path): void { file_put_contents($path,'<html>Error</html>'); });
    $row=$pdo->query('SELECT * FROM games WHERE api_id=1')->fetch();
    gi_check($row['thumb']===$local && (int)$row['done_processing']===0,'Failure preserves good image and marks retryable');
    gi_check(hash_file('sha256',$dir.'/images/'.basename($local))===$hash,'Good file not overwritten');
    $log=file_get_contents($dir.'/failures.log'); gi_check(str_contains($log,'id=1') && str_contains($log,'assets.slotslaunch.com') && !str_contains($log,'private-token'),'Safe contextual failure log');
    import_game($pdo,$changed,$download); $fresh=$pdo->query('SELECT thumb FROM games WHERE api_id=1')->fetchColumn();
    gi_check($fresh!==$local && game_image_local_valid($fresh),'Changed source downloads new deterministic file');
    $pdo->prepare('UPDATE games SET thumb=? WHERE api_id=1')->execute([$changed['thumb']]);
    $before=$calls; import_game($pdo,$changed,$download); gi_check($calls===$before,'Existing remote DB record uses already-localized file');
    foreach (['file:///etc/passwd','http://127.0.0.1/x','http://169.254.169.254/x','https://user:pass@example.test/x','../../file.png'] as $bad) {
        try { game_image_localize(2,$bad); throw new LogicException('Unsafe source accepted'); } catch(RuntimeException $e) { gi_check(!$e instanceof LogicException,'Unsafe source rejection'); }
    }
    $broken=$input; $broken['id']=2; $broken['slug']='broken'; import_game($pdo,$broken,static function($u,$p): void { file_put_contents($p,''); });
    gi_check($pdo->query('SELECT thumb FROM games WHERE api_id=2')->fetchColumn()==='','No remote fallback on failed first import');
    $ok=$input; $ok['id']=3; $ok['slug']='after-broken'; import_game($pdo,$ok,$download);
    gi_check((int)$pdo->query('SELECT COUNT(*) FROM games')->fetchColumn()===3,'Batch can continue after image failure');
    gi_check(!glob($dir.'/images/.image-*'),'Temporary downloads removed');
    $batch=[];
    for ($i=0;$i<105;$i++) $batch[]=['id'=>100+$i,'name'=>'Batch '.$i,'slug'=>'batch-'.$i,'thumb'=>$fresh];
    file_put_contents($dir.'/batch.json',json_encode(['data'=>$batch]));
    ob_start(); $result=import_fetched_games($pdo,'file://'.$dir.'/batch.json',1000); ob_end_clean();
    gi_check($result['imported']===100,'100-game default cap across oversized input');
    ob_start(); $result=import_fetched_games($pdo,'file://'.$dir.'/batch.json',null,10000,true); ob_end_clean();
    gi_check($result['imported']===105,'Explicit all mode bypasses the game cap');
    echo "PASS: image import/validation, local DB paths, list/detail APIs, cache reuse, changed source, remote-record backfill, nonfatal failure/retry, existing-file preservation and URL/log safety.\n";
} finally { gi_remove($dir); }
