<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$dir=sys_get_temp_dir().'/default-image-test-'.bin2hex(random_bytes(5));mkdir($dir,0700);
putenv('APP_STORAGE_DIR='.$dir);
require_once __DIR__.'/../includes/default-image.php';
require_once __DIR__.'/../plugin/odds-api/includes/sports-api.php';
$count=0;$saved=[];
function default_check(bool $ok,string $message):void{global $count;if(!$ok)throw new RuntimeException($message);$count++;}
try {
    default_check(site_default_image()==='', 'Unset default preserves empty sports fallback');
    default_check(blog_display_image('')===BLOG_DEFAULT_IMAGE,'Unset default preserves builtin blog image');
    file_put_contents($dir.'/bad.png','<svg onload="alert(1)"></svg>');
    $rejected=false;try{site_default_image_save_file($dir.'/bad.png');}catch(InvalidArgumentException){$rejected=true;}
    default_check($rejected,'Non-raster upload rejected');
    $rejected=false;try{site_default_image_upload(['error'=>UPLOAD_ERR_OK,'tmp_name'=>$dir.'/bad.png']);}catch(InvalidArgumentException){$rejected=true;}
    default_check($rejected,'HTTP handler rejects non-uploaded files');
    $im=imagecreatetruecolor(24,24);imagepng($im,$dir.'/image.png');
    $first=site_default_image_save_file($dir.'/image.png');$saved[]=$first;
    default_check(site_default_image()===$first,'Setting stores the validated upload');
    default_check(blog_display_image(BLOG_DEFAULT_IMAGE)===$first && blog_display_image('')===$first,'Blank and legacy default blog images resolve dynamically');
    default_check(blog_display_image('/uploads/blogs/missing-example.png')===$first,'Missing local blog image uses fallback');
    default_check(blog_display_image('https://example.com/real.png')==='https://example.com/real.png','Existing custom source preserved');
    $image=['id'=>'home','type'=>'image','path'=>'$.home_team','label'=>'Home','image_source'=>'home_team','section'=>'loop'];
    $p=sports_api_project([(object)['home_team'=>'Missing Team']],[$image],1,0,'basketball_nba');
    default_check($p['records'][0]['blocks'][0]['url']===$first && $p['records'][0]['blocks'][0]['fallback_url']===$first,'Missing sports logo exposes default and error fallback');
    $image['image_source']='custom';$image['path']='$.photo';
    $p=sports_api_project([(object)['photo'=>'https://example.com/team.png']],[$image],1);
    default_check($p['records'][0]['blocks'][0]['url']==='https://example.com/team.png' && $p['records'][0]['blocks'][0]['fallback_url']===$first,'Custom image retained with load-error fallback');
    $second=site_default_image_save_file($dir.'/image.png');$saved[]=$second;
    default_check(blog_display_image($first)===$second,'Changing global default updates previous auto-default paths');
    default_check((int)sports_registry_pdo()->query('SELECT COUNT(*) FROM participants')->fetchColumn()===0,'Fallback does not create or mark participants enriched');
    blog_setting_set('default_image','');
    default_check(site_default_image()==='' && blog_display_image($first)===BLOG_DEFAULT_IMAGE,'Removal restores original fallback behavior');
    echo "PASS: {$count} default image checks\n";
} finally {
    foreach($saved as $path){@unlink(dirname(__DIR__,2).$path);@unlink(dirname(__DIR__).$path);}
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($dir);
}
