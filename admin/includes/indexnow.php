<?php
declare(strict_types=1);
require_once __DIR__ . '/indexing-queue.php';
require_once __DIR__ . '/index-checker-client.php';

function indexnow_settings(): array
{
    $value=json_decode(blog_setting_get('indexnow_config','{}'),true);
    return (is_array($value)?$value:[]) + ['enabled'=>false,'key'=>'','status'=>'Not Configured','http_status'=>null];
}
function indexnow_key_valid(string $key): bool { return preg_match('/^[a-zA-Z0-9-]{8,128}$/D',$key)===1; }
function indexnow_key_location(string $key): string { return $key===''?'':public_url('/'.$key.'.txt'); }
function indexnow_public_settings(): array
{
    $s=indexnow_settings();
    return ['indexnow_enabled'=>(bool)$s['enabled'],'indexnow_configured'=>indexnow_key_valid($s['key']), 'indexnow_key_location'=>indexnow_key_location($s['key']),'indexnow_status'=>$s['status']];
}
function indexnow_store(array $settings): void { blog_setting_set_multiline('indexnow_config',json_encode($settings),4000); }
function indexnow_save(bool $enabled,string $key,bool $generate=false): void
{
    if (!function_exists('auth_can') || !auth_can('super_user')) throw new DomainException('Access denied.');
    if ($generate) $key=bin2hex(random_bytes(16));
    if (!indexnow_key_valid($key)) throw new InvalidArgumentException('IndexNow keys require 8–128 letters, digits, or hyphens.');
    $root=env_value('INDEXNOW_PUBLIC_DIR') ?: dirname(__DIR__);
    if (!is_dir($root)) throw new RuntimeException('Public key directory unavailable.');
    $old=indexnow_settings(); $path=$root.'/'.$key.'.txt';
    if (is_link($path) || (file_exists($path) && (!is_file($path) || file_get_contents($path)!==$key))) throw new InvalidArgumentException('The key filename is already used by another file.');
    $created=!is_file($path);
    if ($created) {
        $file=@fopen($path,'x');
        if (!$file) throw new RuntimeException('Cannot create the public IndexNow key file.');
        try { if (fwrite($file,$key)!==strlen($key) || !chmod($path,0644)) throw new RuntimeException('Cannot write public key file.'); }
        catch (Throwable $e) { fclose($file); unlink($path); throw $e; }
        fclose($file);
    }
    try { indexnow_store(['enabled'=>$enabled,'key'=>$key,'status'=>'Verification Pending','http_status'=>null]); }
    catch (Throwable $e) { if ($created) unlink($path); throw $e; }
    if ($old['key']!==$key || (!$old['enabled'] && $enabled)) {
        $pdo=blogs_pdo(); indexing_schema($pdo);
        $pdo->exec("UPDATE indexing_notifications SET attempts=0,next_at=0; DELETE FROM indexing_jobs WHERE name='indexnow-cooldown'");
    }
    if ($old['key']!==$key && indexnow_key_valid($old['key'])) {
        $previous=$root.'/'.$old['key'].'.txt';
        if (!is_link($previous) && is_file($previous) && file_get_contents($previous)===$old['key']) unlink($previous);
    }
}
function indexnow_url_allowed(string $url): bool
{
    if (!index_checker_url($url) || index_checker_origin($url)!==index_checker_origin(site_base_url()) || !index_checker_safe_path($url)) return false;
    $path=parse_url($url,PHP_URL_PATH);
    if ($path==='/') return true;
    if (!preg_match('~^/(blog|game)/([^/]+)/$~D',$path ?? '',$m)) return false;
    return normalize_slug($m[2])===$m[2];
}
function submitToIndexNow(array $urls,?callable $http=null): array
{
    $s=indexnow_settings();
    if (!$s['enabled'] || !indexnow_key_valid($s['key'])) throw new RuntimeException('IndexNow is disabled or not configured.');
    $original=$s;
    $urls=array_values(array_unique($urls));
    if (!$urls || count($urls)>10000) throw new InvalidArgumentException('IndexNow requires 1–10,000 URLs per batch.');
    foreach ($urls as $url) if (!is_string($url) || !indexnow_url_allowed($url)) throw new InvalidArgumentException('IndexNow URL is not a canonical public content URL.');
    $response=($http ?? 'index_checker_http')('https://api.indexnow.org/indexnow',json_encode(['host'=>parse_url(site_base_url(),PHP_URL_HOST),'key'=>$s['key'],'keyLocation'=>indexnow_key_location($s['key']),'urlList'=>$urls],JSON_UNESCAPED_SLASHES),['Content-Type: application/json; charset=utf-8']);
    $s['http_status']=(int)$response['status'];
    $s['status']=match($s['http_status']) {200=>'Key Verified',202=>'Verification Pending',default=>'Error'};
    // Do not restore a key that was replaced while the request was in flight.
    if (indexnow_settings()===$original) indexnow_store($s);
    error_log('IndexNow batch size '.count($urls).' HTTP '.$s['http_status']);
    return $response;
}
function indexnow_test(?callable $http=null): int
{
    $s=indexnow_settings();
    if (!indexnow_key_valid($s['key'])) throw new InvalidArgumentException('Configure a valid IndexNow key first.');
    if (!$s['enabled']) throw new InvalidArgumentException('Enable IndexNow and save settings before testing.');
    $result=($http ?? 'index_checker_http')(indexnow_key_location($s['key']),null,[],1024);
    if ($result['status']!==200 || $result['body']!==$s['key']) {
        $s['status']='Error'; $s['http_status']=$result['status']; indexnow_store($s);
        error_log('IndexNow key verification failed HTTP '.(int)$result['status']);
        throw new RuntimeException('The public IndexNow key file could not be verified.');
    }
    return (int)submitToIndexNow([public_url('/')],$http)['status'];
}
function indexnow_process_queue(PDO $pdo,?callable $http=null): int
{
    indexing_schema($pdo);
    if (!indexnow_settings()['enabled']) return 0;
    if ($pdo->query("SELECT 1 FROM indexing_jobs WHERE name='indexnow-cooldown' AND next_at>".time())->fetchColumn()) return 0;
    $lock=fopen(blog_storage_dir().'/indexnow-worker.lock','c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if ($lock) fclose($lock); return 0; }
    try {
        $rows=$pdo->query('SELECT * FROM indexing_notifications WHERE next_at<='.time().' ORDER BY next_at,url LIMIT 10000')->fetchAll(PDO::FETCH_ASSOC);
        $rows=array_values(array_filter($rows,static function(array $row) use ($pdo): bool {
            if (indexnow_url_allowed($row['url'])) return true;
            $pdo->prepare('DELETE FROM indexing_notifications WHERE url=? AND version=?')->execute([$row['url'],$row['version']]);
            error_log('IndexNow skipped a noncanonical or previous-host URL.');
            return false;
        }));
        if (!$rows) return 0;
        $urls=array_column($rows,'url');
        try { $response=submitToIndexNow($urls,$http); } catch (Throwable $e) { $response=['status'=>0,'headers'=>[]]; error_log('IndexNow transport/verification error.'); }
        $ok=in_array($response['status'],[200,202],true); $now=time();
        $retry=$response['headers']['retry-after'][0] ?? null;
        $retrySeconds=is_string($retry)?(ctype_digit($retry)?(int)$retry:max(0,(strtotime($retry)?:$now)-$now)):0;
        foreach ($rows as $row) {
            if ($ok) $pdo->prepare('DELETE FROM indexing_notifications WHERE url=? AND version=?')->execute([$row['url'],$row['version']]);
            else {
                $delay=max($retrySeconds, min(86400,600*(2**min(8,(int)$row['attempts']))));
                if (in_array($response['status'],[400,403,422],true)) $delay=max($delay,86400);
                $pdo->prepare('UPDATE indexing_notifications SET attempts=attempts+1,next_at=? WHERE url=? AND version=?')->execute([$now+$delay,$row['url'],$row['version']]);
            }
        }
        if (!$ok) {
            $pdo->prepare("INSERT INTO indexing_jobs(name,version,next_at) VALUES('indexnow-cooldown','cooldown',?) ON CONFLICT(name) DO UPDATE SET next_at=excluded.next_at")->execute([$now+$delay]);
        }
        if (!$ok) error_log('IndexNow deferred retries'.($response['status']===429?' (rate limited)':'').'.');
        return $ok?count($rows):0;
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
