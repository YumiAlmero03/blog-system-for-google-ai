<?php
declare(strict_types=1);
require_once __DIR__ . '/seo-settings.php';

function google_private_dir(): string
{
    $dir = env_value('GOOGLE_PRIVATE_DIR') ?: __DIR__ . '/../../../.google-private-' . substr(hash('sha256', site_root_path()), 0, 12);
    // Resolve the parent too: never allow a symlink or ../ to place secrets inside the document root.
    $parent = realpath(dirname($dir));
    $resolved = realpath($dir) ?: ($parent ? $parent . '/' . basename($dir) : false);
    $root = realpath(site_root_path());
    if (!$resolved || $resolved === $root || str_starts_with($resolved, $root . '/') || is_link($dir)) {
        throw new RuntimeException('Google private storage must be outside the public site directory.');
    }
    return $resolved;
}

function google_state(): array
{
    $path = google_private_dir() . '/google-config.json';
    if (!is_file($path)) return [];
    $data = json_decode((string) @file_get_contents($path), true);
    if (!is_array($data)) throw new RuntimeException('Private Google configuration is unavailable.');
    return $data;
}

function google_write_state(array $state, ?array $expected = null): void
{
    $dir = google_private_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700)) throw new RuntimeException('Private Google storage is not writable.');
    if (!@chmod($dir,0700)) throw new RuntimeException('Cannot secure private Google storage.');
    $lock = fopen($dir . '/config.lock','c');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Cannot lock private Google configuration.');
    chmod($dir . '/config.lock',0600);
    if ($expected !== null && google_state() !== $expected) { flock($lock,LOCK_UN); fclose($lock); throw new RuntimeException('Google configuration changed; retry the operation.'); }
    $tmp = tempnam($dir, '.google-');
    if ($tmp === false) { flock($lock,LOCK_UN); fclose($lock); throw new RuntimeException('Cannot save private Google configuration.'); }
    try {
        $json = json_encode($state,JSON_THROW_ON_ERROR);
        if (!chmod($tmp,0600) || file_put_contents($tmp,$json) !== strlen($json)
            || !rename($tmp,$dir.'/google-config.json')) throw new RuntimeException('Cannot save private Google configuration.');
    } finally { if (is_file($tmp)) unlink($tmp); flock($lock,LOCK_UN); fclose($lock); }
}

function google_validate_credentials(string $json): array
{
    $key = json_decode($json,true);
    if (strlen($json)>65536 || !is_array($key) || ($key['type'] ?? '') !== 'service_account'
        || !is_string($key['project_id'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9:.-]{3,127}$/D',$key['project_id'])
        || !is_string($key['private_key'] ?? null) || !is_string($key['client_email'] ?? null)
        || !filter_var($key['client_email'],FILTER_VALIDATE_EMAIL)
        || ($key['token_uri'] ?? '') !== 'https://oauth2.googleapis.com/token') throw new InvalidArgumentException('Upload a valid Google service-account JSON file.');
    $private = @openssl_pkey_get_private($key['private_key']);
    $details = $private ? openssl_pkey_get_details($private) : false;
    if (!$details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) throw new InvalidArgumentException('The service-account signing key is invalid.');
    return array_intersect_key($key,array_flip(['type','project_id','private_key','client_email','token_uri','private_key_id']));
}

function google_credentials(array $config = []): array
{
    $state = google_state();
    if (isset($state['credentials'])) return google_validate_credentials(json_encode($state['credentials']));
    if (!empty($state['removed'])) throw new RuntimeException('Google credentials are not configured.');
    $path = realpath($config['credentials'] ?? env_value('GSC_SERVICE_ACCOUNT_FILE') ?: '');
    $root = realpath(site_root_path());
    if (!$path || !is_file($path) || str_starts_with($path,$root.'/') || filesize($path)>65536) throw new RuntimeException('Configure a private Google service-account file outside the public site directory.');
    return google_validate_credentials((string) @file_get_contents($path));
}

function google_property(): string
{
    return google_state()['property'] ?? env_value('GSC_PROPERTY') ?: seo_settings()['website_url'];
}

function google_save_configuration(string $property, ?string $json, bool $remove = false): void
{
    if (!index_checker_in_property(seo_settings()['website_url'], $property)) throw new InvalidArgumentException('The Search Console property must cover the configured site.');
    $state = google_state(); $original = $state;
    $remove = $remove || ($json === null && !empty($state['removed']));
    $credentials = $json !== null ? google_validate_credentials($json) : ($state['credentials'] ?? null);
    $state = ['property'=>$property,'status'=>'Not Configured','submission_pending'=>true];
    if (!$remove && $credentials !== null) $state['credentials']=$credentials;
    if ($remove) { $state['removed']=true; $state['submission_pending']=false; }
    google_write_state($state,$original);
}

function google_safe_status(): array
{
    $state=google_state();
    try { $key=google_credentials(); $configured=true; } catch (Throwable $e) { $key=[]; $configured=false; }
    return ['configured'=>$configured,'property'=>google_property(),'status'=>$state['status'] ?? 'Not Configured',
        'email'=>$key['client_email'] ?? '', 'last_authenticated'=>$state['last_authenticated'] ?? null];
}

function google_access_token(array $config = [], ?callable $http = null, bool $write = false): string
{
    static $cache=[];
    $key=google_credentials($config);
    $scope='https://www.googleapis.com/auth/webmasters'.($write ? '' : '.readonly');
    $id=hash('sha256',json_encode($key).$scope);
    if (isset($cache[$id]) && $cache[$id]['expires']>time()+60) return $cache[$id]['token'];
    $b64=static fn(string $v): string => rtrim(strtr(base64_encode($v),'+/','-_'),'=');
    $now=time();
    $jwt=$b64(json_encode(['alg'=>'RS256','typ'=>'JWT'])).'.'.$b64(json_encode(['iss'=>$key['client_email'],'scope'=>$scope,'aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3600]));
    if (!@openssl_sign($jwt,$signature,$key['private_key'],OPENSSL_ALGO_SHA256)) throw new RuntimeException('Google authentication failed.');
    try {
        $response=($http ?? 'index_checker_http')('https://oauth2.googleapis.com/token',http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt.'.'.$b64($signature)]),['Content-Type: application/x-www-form-urlencoded']);
        $data=json_decode($response['body'],true);
        if ($response['status']!==200 || !is_string($data['access_token'] ?? null) || $data['access_token']==='') throw new RuntimeException('Google authentication failed.');
    } catch (Throwable $e) { error_log('Google authentication failed.'); throw new RuntimeException('Google authentication failed.'); }
    $cache[$id]=['token'=>$data['access_token'],'expires'=>$now+max(0,min(3600,(int)($data['expires_in'] ?? 3600)))];
    error_log('Google authentication succeeded.');
    return $cache[$id]['token'];
}

function google_test_connection(?callable $http = null): string
{
    $state=google_state(); $original=$state;
    try {
        $token=google_access_token([], $http);
        $state['last_authenticated']=time();
        $response=($http ?? 'index_checker_http')('https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode(google_property()),null,['Authorization: Bearer '.$token]);
        $data=json_decode($response['body'],true);
        $state['status']=$response['status']===200 && in_array($data['permissionLevel'] ?? '',['siteOwner','siteFullUser','siteRestrictedUser'],true)
            ? 'Connected' : 'Property Access Denied';
    } catch (Throwable $e) { $state['status']='Authentication Failed'; }
    google_write_state($state,$original);
    return $state['status'];
}

function google_submit_pending_sitemap(?callable $http = null): void
{
    $state=google_state(); $original=$state;
    if (empty($state['submission_pending'])) return;
    $token=google_access_token([], $http,true);
    $url='https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode(google_property()).'/sitemaps/'.rawurlencode(public_url('/sitemap-index.xml'));
    $response=$http ? $http($url,'',['Authorization: Bearer '.$token],16777216,'PUT')
        : index_checker_http($url,'',['Authorization: Bearer '.$token],16777216,'PUT');
    error_log('Google sitemap submission HTTP '.(int)$response['status']);
    if ($response['status']<200 || $response['status']>=300) throw new RuntimeException('Google sitemap submission failed; it remains queued.');
    $state['submission_pending']=false;
    google_write_state($state,$original);
}
