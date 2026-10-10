<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (($argv[1] ?? '') === '--request') {
    $_POST=json_decode(stream_get_contents(STDIN),true);
    $_SERVER['REQUEST_METHOD']='POST';
    $_SERVER['REMOTE_ADDR']='127.0.0.1';
    require __DIR__.'/../../'.(($argv[2] ?? '')==='legacy' ? 'api' : 'game-api').'/game-play.php';
    exit;
}
$dir=sys_get_temp_dir().'/game-players-'.bin2hex(random_bytes(6));
mkdir($dir,0700); putenv('APP_STORAGE_DIR='.$dir); putenv('GAME_SITEMAP_OUTPUT_DIR='.$dir);
ini_set('session.save_path',$dir);
require __DIR__.'/../includes/auth.php';
require __DIR__.'/../includes/public-detail.php';
function check_players(bool $ok): void { if (!$ok) throw new RuntimeException('Counter regression failed'); }
function record_player(string $slug,string $visitor,bool $legacy=false): array {
    $p=proc_open([PHP_BINARY,__FILE__,'--request',$legacy?'legacy':'canonical'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],json_encode(['slug'=>$slug,'visitor_id'=>$visitor])); fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]); fclose($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[2]);
    check_players(proc_close($p)===0 && $err===''); return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
try {
    $pdo=blogs_pdo();
    $pdo->exec("INSERT INTO games(api_id,name,slug,provider,provider_slug,published,done_processing,restrictions) VALUES(987654,'Test','counter-test','Test','test',1,1,'[]')");
    $one=record_player('counter-test',str_repeat('a',32));
    $repeat=record_player('counter-test',str_repeat('a',32));
    $two=record_player('counter-test',str_repeat('b',32));
    check_players($one['counted'] && $one['uniquePlayers']===1 && !$repeat['counted'] && $repeat['uniquePlayers']===1 && $two['uniquePlayers']===2);
    check_players(!record_player('missing',str_repeat('a',32))['ok']);
    check_players(!record_player('counter-test','bad')['ok']);
    check_players(record_player('counter-test',str_repeat('a',32),true)['ok']);
    $pdo->exec('UPDATE games SET is_viewable=0');
    check_players(!record_player('counter-test',str_repeat('c',32))['ok']);
    check_players((int)$pdo->query('SELECT COUNT(*) FROM game_players')->fetchColumn()===2);
    $_POST=['title'=>'Draft default','seo_title'=>'Draft default','content'=>'Draft content','excerpt'=>'An excerpt','category'=>blog_category_names()[0]];
    check_players(blog_payload_from_post()['blog']['status']==='draft');
    mkdir($dir.'/api-cache'); file_put_contents($dir.'/api-cache/test.json','stale');
    $blog=['id'=>'live-test','slug'=>'live-test','title'=>'Before','content'=>'Before','excerpt'=>'Test','category'=>blog_category_names()[0],'author'=>'Test','status'=>'published','featuredImage'=>BLOG_DEFAULT_IMAGE];
    blogs_upsert($blog); $blog['content']='Updated live content'; blogs_upsert($blog);
    check_players(!file_exists($dir.'/api-cache/test.json') && public_blog_find('live-test')['content']==='Updated live content');
    echo "PASS: unique players, duplicate retries, validation/visibility, draft default, published edits and cache invalidation.\n";
} finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
    rmdir($dir);
}
