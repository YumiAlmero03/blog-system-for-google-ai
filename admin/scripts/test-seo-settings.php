<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dir = sys_get_temp_dir() . '/seo-settings-' . bin2hex(random_bytes(6)); mkdir($dir,0700);
putenv('APP_STORAGE_DIR=' . $dir); putenv('SITE_BASE_URL'); ini_set('session.save_path',$dir);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/public-head.php';
function seo_check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function seo_reject(array $input,string $class): void {
    try { seo_settings_save($input); } catch (Throwable $e) { seo_check($e instanceof $class,'Wrong validation exception'); return; }
    throw new RuntimeException('Invalid update accepted');
}
try {
    $_SESSION['user']=['role'=>'super_user'];
    // Environment files may configure an origin; use its effective value in that case.
    $url=site_base_url();
    $settings=seo_settings_save(['website_name'=>'Test & Site','website_url'=>$url,'google_analytics_id'=>'G-ABC1234567','google_search_console_verification'=>'verify_token-123','default_seo_title'=>'Global title','default_meta_description'=>'Global description','default_og_image'=>'/uploads/blogs/test.webp']);
    seo_check(blog_website_title()==='Test & Site','Existing website_title reused');
    seo_check($settings['website_url']===$url.'/','Existing URL resolver used');
    seo_check($settings['default_og_image']===$url.'/uploads/blogs/test.webp','Absolute OG image');
    blog_setting_set('unrelated_private_setting','must-not-leak');
    seo_check(array_keys(seo_settings())===array_keys(SEO_SETTING_KEYS),'Public allowlist');
    unset($GLOBALS['seo_tracking_rendered']); $html=public_head_html();
    seo_check(str_contains($html,'<title>Global title</title>') && str_contains($html,'content="Global description"'),'Global defaults');
    seo_check(str_contains($html,'content="Test &amp; Site"') && str_contains($html,'content="verify_token-123"'),'Escaped site name and verification');
    seo_check(substr_count($html.public_head_html(),'googletagmanager.com/gtag/js')===1,'Analytics once per request');
    $page=public_head_html(['title'=>'Specific blog title','description'=>'Specific description','image'=>'/uploads/blogs/specific.webp']);
    seo_check(str_contains($page,'Specific blog title') && str_contains($page,'Specific description') && !str_contains($page,'Global title'),'Page overrides');
    foreach ([['google_analytics_id'=>'<script>'],['google_analytics_id'=>'UA-123'],['google_analytics_id'=>'G-ABC'],['website_url'=>'javascript:alert(1)'],['website_url'=>'https://example.test/path'],['default_og_image'=>'javascript:alert(1)'],['google_search_console_verification'=>'<meta name="x">'],['default_meta_description'=>['bad']],['jwt_secret'=>'leak']] as $bad) seo_reject($bad,InvalidArgumentException::class);
    seo_check(seo_settings()['google_analytics_id']==='G-ABC1234567','Invalid updates leave settings intact');
    seo_settings_save(['google_analytics_id'=>'','google_search_console_verification'=>'']); unset($GLOBALS['seo_tracking_rendered']);
    $empty=public_head_html(); seo_check(!str_contains($empty,'gtag') && !str_contains($empty,'google-site-verification'),'Empty tracking values omitted');
    seo_check(str_contains(public_head_html(['fallback_image'=>'/uploads/blogs/fallback.webp']),'test.webp'),'Global OG precedes fallback');
    seo_settings_save(['default_og_image'=>'']);
    seo_check(str_contains(public_head_html(['fallback_image'=>'/uploads/blogs/fallback.webp']),'fallback.webp'),'Existing image fallback');
    $_SESSION['user']=['role'=>'editor']; seo_reject(['website_name'=>'Forbidden'],DomainException::class);
    $_SESSION['user']=['role'=>'admin']; seo_settings_save(['website_name'=>'Admin update']);
    seo_check(seo_settings()['website_name']==='Admin update','Admin access');
    $count=(int)blogs_pdo()->query("SELECT COUNT(*) FROM app_settings WHERE setting_key='website_name'")->fetchColumn();
    seo_check($count===0,'No duplicate website name field');
    echo "PASS: reused settings, public allowlist, validation, admin/editor permissions, metadata precedence, OG URLs, tracking once/empty, and cache invalidation.\n";
} finally {
    session_write_close(); foreach (glob($dir.'/*') ?: [] as $file) if (is_file($file)) unlink($file); rmdir($dir);
}
