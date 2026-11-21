<?php
declare(strict_types=1);
require_once __DIR__ . '/seo-settings.php';

function google_private_dir(): string
{
    $custom = env_value('GOOGLE_PRIVATE_DIR');
    $root = @realpath(site_root_path()) ?: site_root_path();
    $storageDir = function_exists('blog_storage_dir') ? blog_storage_dir() : (__DIR__ . '/../storage');
    $resolvedStorage = @realpath($storageDir) ?: $storageDir;

    if ($custom !== null && $custom !== '') {
        $parent = @realpath(dirname($custom));
        $resolved = @realpath($custom) ?: ($parent ? $parent . '/' . basename($custom) : false);
        if (!$resolved || $resolved === $root || is_link($custom)) {
            throw new RuntimeException('Google private storage cannot be the public site directory or a symlink.');
        }
        // If within the public site root, it must reside in protected admin/storage.
        if (str_starts_with($resolved, $root . '/') && !str_starts_with($resolved, $resolvedStorage . '/')) {
            throw new RuntimeException('Google private storage within site root must be inside protected admin/storage.');
        }
        return $resolved;
    }

    // Default outside document root (standard Linux/VPS/macOS setups without strict open_basedir)
    $outsideParent = dirname(site_root_path());
    $outsideParentReal = @realpath($outsideParent);
    if ($outsideParentReal && $outsideParentReal !== $root && @is_dir($outsideParentReal) && @is_writable($outsideParentReal)) {
        $dir = $outsideParentReal . '/.google-private-' . substr(hash('sha256', site_root_path()), 0, 12);
        return $dir;
    }

    // aaPanel / cPanel with open_basedir restricting access to site root:
    // Store in protected admin/storage/.google-private
    $dir = $resolvedStorage . '/.google-private';
    return $dir;
}

function google_legacy_config_path(): ?string
{
    $candidates = [];
    try {
        $candidates[] = google_private_dir() . '/google-config.json';
    } catch (Throwable) {
    }
    $storageDir = function_exists('blog_storage_dir') ? blog_storage_dir() : (__DIR__ . '/../storage');
    $resolvedStorage = @realpath($storageDir) ?: $storageDir;
    $fallback = $resolvedStorage . '/.google-private/google-config.json';
    if (!in_array($fallback, $candidates, true)) {
        $candidates[] = $fallback;
    }
    foreach ($candidates as $path) {
        if (@is_file($path) && @is_readable($path)) {
            return $path;
        }
    }
    return null;
}

function google_read_sqlite_state(PDO $pdo): ?array
{
    $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $stmt->execute(['google_config']);
    $raw = $stmt->fetchColumn();
    if ($raw === false || $raw === null || $raw === '') {
        return null;
    }
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Private Google configuration is unavailable.');
    }
    return $data;
}

function google_state(): array
{
    $pdo = blogs_pdo();
    $data = google_read_sqlite_state($pdo);
    if ($data !== null) {
        return $data;
    }
    $legacyPath = google_legacy_config_path();
    if ($legacyPath !== null) {
        $legacy = json_decode((string) @file_get_contents($legacyPath), true);
        if (is_array($legacy)) {
            google_write_state($legacy);
            @unlink($legacyPath);
            return $legacy;
        }
    }
    return [];
}

function google_write_state(array $state, ?array $expected = null): void
{
    $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $pdo = blogs_pdo();
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->exec('BEGIN IMMEDIATE');
    }
    try {
        if ($expected !== null) {
            $current = google_read_sqlite_state($pdo) ?? [];
            if ($current !== $expected) {
                throw new RuntimeException('Google configuration changed; retry the operation.');
            }
        }
        $stmt = $pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value, updated_at)
             VALUES (?, ?, ?)
             ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = excluded.updated_at'
        );
        $stmt->execute(['google_config', $json, time()]);
        if ($own) {
            $pdo->exec('COMMIT');
        }
    } catch (Throwable $error) {
        if ($own) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        throw $error;
    }
    $legacyPath = google_legacy_config_path();
    if ($legacyPath !== null) {
        @unlink($legacyPath);
    }
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
    $path = @realpath($config['credentials'] ?? env_value('GSC_SERVICE_ACCOUNT_FILE') ?: '');
    $root = @realpath(site_root_path()) ?: site_root_path();
    $storageDir = function_exists('blog_storage_dir') ? blog_storage_dir() : (__DIR__ . '/../storage');
    $resolvedStorage = @realpath($storageDir) ?: $storageDir;
    $isAllowed = $path && is_file($path) && (!str_starts_with($path, $root . '/') || str_starts_with($path, $resolvedStorage . '/'));
    if (!$isAllowed || filesize($path) > 65536) throw new RuntimeException('Configure a private Google service-account file outside the public site directory.');
    return google_validate_credentials((string) @file_get_contents($path));
}

function google_property(): string
{
    return google_state()['property'] ?? env_value('GSC_PROPERTY') ?: seo_settings()['website_url'];
}

function google_save_configuration(string $property, ?string $json, bool $remove = false, ?bool $indexingApiEnabled = null): void
{
    if (!index_checker_in_property(seo_settings()['website_url'], $property)) throw new InvalidArgumentException('The Search Console property must cover the configured site.');
    $state = google_state(); $original = $state;
    $remove = $remove || ($json === null && !empty($state['removed']));
    $credentials = $json !== null ? google_validate_credentials($json) : ($state['credentials'] ?? null);
    $state = ['property'=>$property,'status'=>'Not Configured','submission_pending'=>true];
    if ($remove) {
        $state['removed'] = true;
        $state['submission_pending'] = false;
    } else {
        if ($indexingApiEnabled !== null) {
            $state['indexing_api_enabled'] = $indexingApiEnabled;
        } elseif (array_key_exists('indexing_api_enabled', $original)) {
            $state['indexing_api_enabled'] = (bool) $original['indexing_api_enabled'];
        }
        if ($credentials !== null) {
            $state['credentials'] = $credentials;
        }
    }
    google_write_state($state,$original);
}

function google_indexing_scope(): string
{
    return 'https://www.googleapis.com/auth/indexing';
}

function google_indexing_enabled(): bool
{
    $state = google_state();
    if (array_key_exists('indexing_api_enabled', $state)) {
        return (bool) $state['indexing_api_enabled'];
    }
    $env = env_value('GOOGLE_INDEXING_ENABLED');
    if ($env !== null && $env !== '') {
        return filter_var($env, FILTER_VALIDATE_BOOLEAN);
    }
    return false;
}

function google_safe_status(): array
{
    $state=google_state();
    try { $key=google_credentials(); $configured=true; } catch (Throwable $e) { $key=[]; $configured=false; }
    return ['configured'=>$configured,'property'=>google_property(),'status'=>$state['status'] ?? 'Not Configured',
        'email'=>$key['client_email'] ?? '', 'last_authenticated'=>$state['last_authenticated'] ?? null,
        'indexing_api_enabled'=>google_indexing_enabled()];
}

function google_access_token(array $config = [], ?callable $http = null, bool $write = false, ?string $scope = null): string
{
    static $cache=[];
    $key=google_credentials($config);
    $scope=$scope ?? ('https://www.googleapis.com/auth/webmasters'.($write ? '' : '.readonly'));
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

function google_publish_url(string $url, string $type = 'URL_UPDATED', ?callable $http = null): array
{
    if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
        throw new InvalidArgumentException('Invalid publication URL.');
    }
    if (!in_array($type, ['URL_UPDATED', 'URL_DELETED'], true)) {
        throw new InvalidArgumentException('Invalid notification type: must be URL_UPDATED or URL_DELETED.');
    }
    $token = google_access_token([], $http, false, google_indexing_scope());
    $payload = json_encode(['url' => $url, 'type' => $type], JSON_UNESCAPED_SLASHES);
    $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json; charset=utf-8'];
    $response = ($http ?? 'index_checker_http')(
        'https://indexing.googleapis.com/v3/urlNotifications:publish',
        $payload,
        $headers,
        65536,
        'POST'
    );
    $status = (int)$response['status'];
    $data = json_decode((string)($response['body'] ?? ''), true);
    $success = ($status >= 200 && $status < 300);
    error_log('Google Indexing publish ' . $type . ' HTTP ' . $status . ' for ' . $url);
    return [
        'status' => $status,
        'success' => $success,
        'body' => is_array($data) ? $data : [],
        'raw' => $response['body'] ?? '',
    ];
}

function google_get_url_notification_status(string $url, ?callable $http = null): array
{
    if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
        throw new InvalidArgumentException('Invalid publication URL.');
    }
    $token = google_access_token([], $http, false, google_indexing_scope());
    $endpoint = 'https://indexing.googleapis.com/v3/urlNotifications/metadata?url=' . rawurlencode($url);
    $headers = ['Authorization: Bearer ' . $token];
    $response = ($http ?? 'index_checker_http')($endpoint, null, $headers);
    $status = (int)$response['status'];
    $data = json_decode((string)($response['body'] ?? ''), true);
    return [
        'status' => $status,
        'success' => ($status === 200),
        'body' => is_array($data) ? $data : [],
        'raw' => $response['body'] ?? '',
    ];
}

function google_test_indexing_api(?callable $http = null): array
{
    try {
        $siteUrl = rtrim(seo_settings()['website_url'], '/') . '/';
        $res = google_get_url_notification_status($siteUrl, $http);
        $status = $res['status'];
        if ($status === 200) {
            return ['status' => 200, 'ok' => true, 'message' => 'Connected. Google Indexing API is active.'];
        }
        if ($status === 404) {
            return ['status' => 200, 'ok' => true, 'message' => 'Connected. Google Indexing API is active and ready for URL submissions.'];
        }
        if ($status === 403) {
            $msg = $res['body']['error']['message'] ?? 'Permission denied. Ensure Indexing API is enabled in Google Cloud Console and service account has owner permissions.';
            return ['status' => 403, 'ok' => false, 'message' => 'Google Indexing API access denied (HTTP 403): ' . $msg];
        }
        return ['status' => $status, 'ok' => false, 'message' => 'Google Indexing API test returned HTTP ' . $status . '.'];
    } catch (Throwable $e) {
        return ['status' => 0, 'ok' => false, 'message' => 'Google Indexing API authentication or transport failed: ' . $e->getMessage()];
    }
}
