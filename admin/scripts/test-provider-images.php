<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dir=sys_get_temp_dir().'/provider-images-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
putenv('APP_STORAGE_DIR='.$dir.'/storage'); putenv('PROVIDER_IMAGE_UPLOAD_DIR='.$dir.'/images');
define('PROVIDERS_IMPORT_LIBRARY_ONLY',true);
require __DIR__ . '/../plugin/games/scripts/import-slotslaunch-providers.php';
function pi_check(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); }
try {
    $pdo=blogs_pdo(); $calls=0;
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQ0AAAAASUVORK5CYII=');
    $download=static function(string $url,string $path) use (&$calls,$png): void { $calls++; file_put_contents($path,$png); };
    $input=['id'=>123,'name'=>'Example Provider','thumb'=>'https://assets.example.test/provider.png','thumbnail'=>'https://assets.example.test/wrong.png'];
    pi_check(import_provider($pdo,$input,$download),'Import provider');
    $row=$pdo->query('SELECT * FROM game_providers WHERE api_id=123')->fetch(); $local=$row['thumbnail'];
    pi_check(str_starts_with($local,'/uploads/providers/provider-123-') && provider_image_local_valid($local),'Validated local image');
    pi_check(str_contains($local,substr(hash('sha256',$input['thumb']),0,24)),'thumb wins over thumbnail on creation');
    import_provider($pdo,$input,$download); pi_check($calls===1,'Cached image reused');
    $changed=$input; $changed['thumb']='https://assets.example.test/new.png';
    import_provider($pdo,$changed,static function($url,$path): void { file_put_contents($path,'<html>Error</html>'); });
    pi_check($pdo->query('SELECT thumbnail FROM game_providers WHERE api_id=123')->fetchColumn()===$local,'Failure preserves previous image');
    import_provider($pdo,$changed,$download); $fresh=$pdo->query('SELECT thumbnail FROM game_providers WHERE api_id=123')->fetchColumn();
    pi_check($fresh!==$local && provider_image_local_valid($fresh),'Changed source retried');
    $input['thumb']=''; unset($input['thumbnail']); import_provider($pdo,$input,$download);
    pi_check($pdo->query('SELECT thumbnail FROM game_providers WHERE api_id=123')->fetchColumn()===$fresh,'Missing source preserves image');
    $pdo->prepare('INSERT INTO games(api_id,name,slug,provider_id,provider,provider_slug,published,done_processing,is_viewable,created_at,updated_at) VALUES(?,?,?,?,?,?,1,1,1,?,?)')->execute([987,'Test Game','test-game',$row['id'],'Example Provider','custom-provider-slug',time(),time()]);
    $runner='$_SERVER["REQUEST_METHOD"]="GET"; require '.var_export(dirname(__DIR__,2).'/game-api/provider-list.php',true).';';
    $process=proc_open([PHP_BINARY,'-r',$runner],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $json=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    pi_check(proc_close($process)===0 && $errors==='','API executes');
    $data=json_decode($json,true);
    pi_check(count($data['providers'])===1 && $data['providers'][0]['thumbnail']===$fresh && $data['providers'][0]['slug']==='custom-provider-slug','API image and custom slug without duplicates');
    pi_check($data['providers'][0]['gameCount']===1,'Eligible game count preserved');
    $readApi=static function() use ($runner): array {
        blog_clear_api_cache();
        $process=proc_open([PHP_BINARY,'-r',$runner],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $json=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        pi_check(proc_close($process)===0 && $errors==='','Zero-demo API executes');
        return json_decode($json,true);
    };
    $pdo->exec('UPDATE games SET done_processing=0 WHERE api_id=987');
    $data=$readApi();
    pi_check(count($data['providers'])===1 && $data['providers'][0]['gameCount']===0 && $data['providers'][0]['thumbnail']===$fresh,'Checked provider without processed demos retains image');
    $pdo->exec('UPDATE games SET done_processing=1, restrictions=\'["PH"]\' WHERE api_id=987');
    pi_check($readApi()['providers'][0]['gameCount']===0,'Restricted demos do not inflate count or hide approved provider');
    $pdo->exec('INSERT INTO game_provider_settings(provider_key,approved,updated_at) VALUES("slug:custom-provider-slug",0,1)');
    pi_check($readApi()['providers']===[],'Unchecked zero-demo provider excluded');
    $pdo->exec('UPDATE game_provider_settings SET approved=1');
    pi_check(count($readApi()['providers'])===1,'Rechecking restores zero-demo provider');
    $pdo->exec('UPDATE game_module_settings SET enabled=0');
    pi_check($readApi()['providers']===[],'Disabled module still hides providers');
    foreach(['file:///etc/passwd','http://127.0.0.1/a','https://user:pass@example.test/a','../../file.png'] as $bad) {
        $rejected=false; try { provider_image_localize(1,$bad); } catch(RuntimeException $e) { $rejected=true; }
        pi_check($rejected,'Unsafe source rejected');
    }
    pi_check(!glob($dir.'/images/.image-*'),'Temporary files cleaned');
    echo "PASS: provider import, validation, local thumbnails, cache reuse, retry, existing image preservation, API custom slugs/counts, unsafe URLs, cleanup.\n";
} finally {
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($dir);
}
