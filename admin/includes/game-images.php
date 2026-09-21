<?php
declare(strict_types=1);
require_once __DIR__ . '/image-validation.php';
require_once __DIR__ . '/env.php';
const GAME_IMAGE_MAX_BYTES = 8388608;

function game_image_directory(): string
{
    return env_value('GAME_IMAGE_UPLOAD_DIR') ?: __DIR__ . '/../uploads/games';
}

function game_image_valid(string $path): ?string
{
    if (!is_file($path) || is_link($path) || filesize($path)<=0 || filesize($path)>GAME_IMAGE_MAX_BYTES) return null;
    $result=validate_blog_image_file($path);
    // Require an actual readable image signature/dimensions, including for AVIF.
    $size=@getimagesize($path);
    if (!$result['ok'] || !$size || $size[0]<1 || $size[1]<1 || $size[0]*$size[1]>40000000) return null;
    return allowed_blog_image_mimes()[$result['mime']][0] ?? null;
}

function game_image_local_valid(string $url): bool
{
    if (!preg_match('~^/uploads/games/([a-zA-Z0-9._-]+\.(?:jpe?g|png|webp|avif))$~D',$url,$match)) return false;
    $path=game_image_directory().'/'.$match[1];
    $extension=game_image_valid($path);
    return $extension!==null && ($extension===strtolower(pathinfo($path,PATHINFO_EXTENSION)) || ($extension==='jpg' && str_ends_with(strtolower($path),'.jpeg')));
}

function game_image_source_valid(string $url): bool
{
    return strlen($url)<=4096 && filter_var($url,FILTER_VALIDATE_URL)!==false
        && in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true)
        && parse_url($url,PHP_URL_USER)===null && parse_url($url,PHP_URL_PASS)===null
        && parse_url($url,PHP_URL_FRAGMENT)===null && !preg_match('/[\x00-\x20\x7f\\\\]/',$url);
}

function game_image_download(string $url,string $path): void
{
    if (!game_image_source_valid($url)) throw new RuntimeException('invalid source URL');
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL is unavailable');
    $host=(string)parse_url($url,PHP_URL_HOST);
    $port=(int)(parse_url($url,PHP_URL_PORT) ?: (strtolower((string)parse_url($url,PHP_URL_SCHEME))==='https' ? 443 : 80));
    if (!in_array($port,[80,443],true)) throw new RuntimeException('unsupported source port');
    $ips=filter_var($host,FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) throw new RuntimeException('source DNS lookup failed');
    foreach ($ips as $ip) if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('non-public source address');
    $file=fopen($path,'wb'); if (!$file) throw new RuntimeException('temporary image cannot be opened');
    $curl=curl_init($url); $bytes=0;
    try {
        curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':'.$port.':'.$ips[0]],CURLOPT_USERAGENT=>'SiteGameImporter/1.0',
            CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk) use ($file,&$bytes): int { $bytes+=strlen($chunk); return $bytes>GAME_IMAGE_MAX_BYTES ? 0 : (int)fwrite($file,$chunk); },
        ]);
        $ok=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        if ($ok===false || $status!==200 || $bytes===0) throw new RuntimeException('image request failed (HTTP '.$status.') or size limit exceeded');
    } finally { unset($curl); fclose($file); }
}

function game_image_localize(int $apiId,string $source,?callable $download=null): string
{
    $source=trim($source);
    if (game_image_local_valid($source)) return $source;
    if (!game_image_source_valid($source)) throw new RuntimeException('missing or invalid image URL');
    $directory=game_image_directory();
    if (!is_dir($directory) && !@mkdir($directory,0755,true) && !is_dir($directory)) throw new RuntimeException('image directory cannot be created');
    $stem='game-'.abs($apiId).'-'.substr(hash('sha256',$source),0,24);
    foreach (['jpg','jpeg','png','webp','avif'] as $extension) {
        $local='/uploads/games/'.$stem.'.'.$extension;
        if (game_image_local_valid($local)) return $local;
    }
    $temp=tempnam($directory,'.image-');
    if (!$temp) throw new RuntimeException('temporary image cannot be created');
    try {
        ($download ?? 'game_image_download')($source,$temp);
        $extension=game_image_valid($temp);
        if ($extension===null) throw new RuntimeException('download is not a supported valid image');
        $target=$directory.'/'.$stem.'.'.$extension;
        if (is_link($target)) throw new RuntimeException('unsafe image destination');
        if (!chmod($temp,0644) || !rename($temp,$target)) throw new RuntimeException('validated image could not be stored');
        return '/uploads/games/'.$stem.'.'.$extension;
    } finally { if (is_file($temp)) unlink($temp); }
}

function game_image_failure_log(int $id,string $name,string $source,string $reason): void
{
    // Omit path, query, userinfo and fragments: signed CDN URLs may contain secrets.
    $reason = preg_match('/^(?:missing or invalid image URL|invalid source URL|PHP cURL is unavailable|source DNS lookup failed|non-public source address|unsupported source port|download is not a supported valid image|image directory cannot be created|temporary image cannot be created|temporary image cannot be opened|unsafe image destination|validated image could not be stored|image request failed \(HTTP [0-9]+\) or size limit exceeded)$/D', $reason) ? $reason : 'unexpected image processing failure';
    $host=parse_url($source,PHP_URL_HOST);
    $safe=$host ? 'https://'.$host.'/[redacted]' : '[missing or invalid URL]';
    error_log('Game image import failed: id='.$id.' name='.json_encode(substr($name,0,200)).' source='.json_encode($safe).' source_hash='.substr(hash('sha256',$source),0,16).' reason='.$reason.'; retry on next import.');
}
