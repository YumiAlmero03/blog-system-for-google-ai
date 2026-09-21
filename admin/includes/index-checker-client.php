<?php
declare(strict_types=1);
require_once __DIR__ . '/seo-settings.php';
require_once __DIR__ . '/google-credentials.php';

function index_checker_config(): array
{
    $integer = static function (string $key, int $default, int $min, int $max): int {
        $value=env_value($key);
        if ($value===null || $value==='') return $default;
        if (!ctype_digit($value) || (int)$value<$min || (int)$value>$max) throw new RuntimeException('Invalid Index Checker interval or limit configuration.');
        return (int)$value;
    };
    return [
        'site'=>rtrim(seo_settings()['website_url'],'/'),
        'property'=>google_property(),
        'credentials'=>env_value('GSC_SERVICE_ACCOUNT_FILE') ?: '',
        'batch'=>$integer('INDEX_CHECK_BATCH',200,1,2000),
        'daily'=>$integer('INDEX_CHECK_DAILY_LIMIT',1800,1,2000),
        'minute'=>$integer('INDEX_CHECK_MINUTE_LIMIT',60,1,600),
        'indexed'=>$integer('INDEX_CHECK_INDEXED_DAYS',30,1,365)*86400,
        'not_indexed'=>$integer('INDEX_CHECK_UNRESOLVED_DAYS',3,1,90)*86400,
        'retry'=>$integer('INDEX_CHECK_RETRY_HOURS',24,1,168)*3600,
        'max_backoff'=>7*86400,
    ];
}

function index_checker_url(string $url): bool
{
    $p=parse_url($url);
    return strlen($url)<=2048 && filter_var($url,FILTER_VALIDATE_URL)!==false && is_array($p)
        && in_array($p['scheme'] ?? '',['http','https'],true)
        && !isset($p['user']) && !isset($p['pass']) && !isset($p['fragment'])
        && !preg_match('/[\x00-\x20\x7f\\\\]/',$url);
}

function index_checker_origin(string $url): string
{
    $p=parse_url($url);
    return strtolower(($p['scheme'] ?? '').'://'.($p['host'] ?? '')).':'.($p['port'] ?? (($p['scheme'] ?? '')==='https' ? 443 : 80));
}

function index_checker_in_property(string $url, string $property): bool
{
    if (!index_checker_url($url)) return false;
    if (str_starts_with($property,'sc-domain:')) {
        $domain=strtolower(substr($property,10));
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9-]+$/D',$domain)) return false;
        $host=strtolower((string)parse_url($url,PHP_URL_HOST));
        return $host===$domain || str_ends_with($host,'.'.$domain);
    }
    if (!index_checker_url($property) || !str_ends_with($property,'/') || parse_url($property,PHP_URL_QUERY)!==null) return false;
    return index_checker_origin($url)===index_checker_origin($property)
        && str_starts_with(parse_url($url,PHP_URL_PATH) ?: '/',parse_url($property,PHP_URL_PATH) ?: '/');
}

function index_checker_safe_path(string $url): bool
{
    $path=rawurldecode(parse_url($url,PHP_URL_PATH) ?: '/');
    if (str_contains($path,'\\') || preg_match('~(?:^|/)\.{1,2}(?:/|$)|%[0-9a-f]{2}~i',$path)) return false;
    if (parse_url($url,PHP_URL_QUERY)!==null) return false;
    return !preg_match('~^/(?:admin|api|storage|uploads|chats|scripts|includes|promo|promo-code|bonus|login[^/]*|logout[^/]*|\.)(?:/|$)|(?:^|/)\.~i',$path);
}

// No redirect following: a sitemap cannot redirect this transport to a private host.
// Public DNS addresses are pinned for the request to prevent rebinding.
function index_checker_http(string $url, ?string $body = null, array $headers = [], int $maxBytes = 16777216, ?string $method = null): array
{
    if (!index_checker_url($url) || !function_exists('curl_init')) throw new RuntimeException('Index Checker requires a valid URL and PHP cURL.');
    $host=(string)parse_url($url,PHP_URL_HOST); $port=(int)(parse_url($url,PHP_URL_PORT) ?: (str_starts_with($url,'https:') ? 443 : 80));
    if (!in_array($port,[80,443],true)) throw new RuntimeException('Unsupported remote port.');
    $addresses=filter_var($host,FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$addresses) throw new RuntimeException('Remote DNS lookup failed.');
    foreach ($addresses as $ip) if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('Remote address is not public.');
    $curl=curl_init($url); $response=''; $receivedHeaders=[];
    curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>40,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTP | CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':'.$port.':'.$addresses[0]],CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_USERAGENT=>'SiteIndexChecker/1.0',
        CURLOPT_WRITEFUNCTION=>static function ($ch,string $chunk) use (&$response,$maxBytes): int { if (strlen($response)+strlen($chunk)>$maxBytes) return 0; $response.=$chunk; return strlen($chunk); },
        CURLOPT_HEADERFUNCTION=>static function ($ch,string $line) use (&$receivedHeaders): int { if (str_contains($line,':')) { [$k,$v]=explode(':',$line,2); $receivedHeaders[strtolower(trim($k))][]=trim($v); } return strlen($line); },
    ]);
    if ($body!==null) curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body]);
    if ($method !== null) curl_setopt($curl,CURLOPT_CUSTOMREQUEST,$method);
    $ok=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    if ($ok===false) throw new RuntimeException('Remote request failed or exceeded the response limit.');
    return ['status'=>$status,'body'=>$response,'headers'=>$receivedHeaders];
}

function index_checker_access_token(array $config, ?callable $http = null): string
{
    return google_access_token($config,$http);
}

function index_checker_inspect(string $url,array $config,?callable $http = null): array
{
    if (!index_checker_in_property($url,$config['property'])) throw new RuntimeException('URL is outside the configured Search Console property.');
    $token=index_checker_access_token($config,$http);
    return ($http ?? 'index_checker_http')('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',json_encode(['inspectionUrl'=>$url,'siteUrl'=>$config['property'],'languageCode'=>'en-US']),['Content-Type: application/json','Authorization: Bearer '.$token]);
}
