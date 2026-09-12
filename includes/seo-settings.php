<?php
declare(strict_types=1);
require_once __DIR__ . '/blog-storage.php';

const SEO_SETTING_KEYS = [
    'website_name'=>'website_title', 'website_url'=>'site_base_url',
    'google_analytics_id'=>'google_analytics_id',
    'google_search_console_verification'=>'google_search_console_verification',
    'default_seo_title'=>'default_seo_title', 'default_meta_description'=>'default_meta_description',
    'default_og_image'=>'default_og_image',
];

function seo_stored_settings(): array
{
    if (!isset($GLOBALS['seo_settings_cache'])) {
        $keys = array_values(SEO_SETTING_KEYS);
        $stmt = blogs_pdo()->prepare('SELECT setting_key,setting_value FROM app_settings WHERE setting_key IN (' . implode(',',array_fill(0,count($keys),'?')) . ')');
        $stmt->execute($keys);
        $GLOBALS['seo_settings_cache'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return $GLOBALS['seo_settings_cache'];
}

function seo_settings(): array
{
    $stored = seo_stored_settings(); $result = [];
    foreach (SEO_SETTING_KEYS as $public=>$key) $result[$public] = $stored[$key] ?? '';
    $result['website_name'] = $result['website_name'] ?: BLOG_DEFAULT_SITE_TITLE;
    $result['website_url'] = site_base_url() . '/';
    if ($result['default_og_image'] !== '') $result['default_og_image'] = public_url($result['default_og_image']);
    return $result;
}

function seo_valid_url(string $url): bool
{
    return filter_var($url,FILTER_VALIDATE_URL) !== false
        && in_array(strtolower(parse_url($url,PHP_URL_SCHEME) ?? ''),['http','https'],true)
        && parse_url($url,PHP_URL_USER) === null && parse_url($url,PHP_URL_PASS) === null;
}

function seo_settings_save(array $input): array
{
    if (!function_exists('auth_can') || !auth_can('admin')) throw new DomainException('Access denied.');
    $limits = ['website_name'=>120,'website_url'=>2048,'google_analytics_id'=>32,'google_search_console_verification'=>512,'default_seo_title'=>200,'default_meta_description'=>500,'default_og_image'=>2048];
    $clean = [];
    foreach ($input as $key=>$value) {
        if (!isset($limits[$key])) throw new InvalidArgumentException('Unknown SEO setting.');
        if (!is_string($value) || strlen($value)>$limits[$key]) throw new InvalidArgumentException('Invalid ' . $key . '.');
        $value = trim($value);
        if (preg_match('/[\x00-\x1f\x7f]/',$value) || strip_tags($value) !== $value) throw new InvalidArgumentException('Use plain text for ' . $key . '.');
        if ($key === 'website_name' && $value === '') throw new InvalidArgumentException('Website name is required.');
        if ($key === 'website_url') {
            if (!seo_valid_url($value) || !in_array(parse_url($value,PHP_URL_PATH),[null,'','/'],true) || parse_url($value,PHP_URL_QUERY) !== null || parse_url($value,PHP_URL_FRAGMENT) !== null) throw new InvalidArgumentException('Use an HTTP(S) website origin without a path, query, or fragment.');
            $value = rtrim($value,'/');
            if (env_value('SITE_BASE_URL') && $value !== site_base_url()) throw new InvalidArgumentException('Website URL is controlled by SITE_BASE_URL in this environment.');
        }
        if ($key === 'google_analytics_id' && $value !== '' && !preg_match('/^G-[A-Z0-9]{10}$/D',$value)) throw new InvalidArgumentException('Use a Google Analytics measurement ID such as G-XXXXXXXXXX.');
        if ($key === 'google_search_console_verification' && $value !== '' && !preg_match('/^[A-Za-z0-9_-]+$/D',$value)) throw new InvalidArgumentException('Enter only the Search Console verification token.');
        if ($key === 'default_og_image' && $value !== '') {
            $local = preg_match('~^/uploads/blogs/[A-Za-z0-9._-]+\.(?:webp|png|jpe?g|svg)$~iD',$value);
            if (!$local && !seo_valid_url($value)) throw new InvalidArgumentException('Upload an image or use an HTTP(S) image URL.');
        }
        $clean[$key] = $value;
    }
    $pdo = blogs_pdo(); $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO app_settings(setting_key,setting_value,updated_at) VALUES(?,?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_at=excluded.updated_at');
        foreach ($clean as $key=>$value) $stmt->execute([SEO_SETTING_KEYS[$key],$value,time()]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    unset($GLOBALS['seo_settings_cache']);
    blog_clear_api_cache();
    return seo_settings();
}
