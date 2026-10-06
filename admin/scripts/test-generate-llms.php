<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/generate-llms.php';
$checks=0;
function llms_check(bool $ok,string $label):void { global $checks; if(!$ok)throw new RuntimeException($label);$checks++; }
function llms_reject(callable $fn,string $label):void { try{$fn();}catch(Throwable $e){llms_check(true,$label);return;}throw new RuntimeException($label); }
$dir=sys_get_temp_dir().'/llms-test-'.bin2hex(random_bytes(6));mkdir($dir,0700);
$base='https://example.test';
$fixtures=[
    "$base/sitemap.xml"=>'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://example.test/pages.xml</loc></sitemap><sitemap><loc>https://example.test/sitemap.xml</loc></sitemap></sitemapindex>',
    "$base/pages.xml"=>'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.test/</loc></url><url><loc>https://example.test/about/</loc></url><url><loc>https://example.test/</loc></url><url><loc>https://example.test/admin/settings.php</loc></url><url><loc>https://example.test/private/</loc></url></urlset>',
    "$base/"=>'<html><head><title>Example Site</title><meta name="description" content="Helpful guides"></head><body><nav>Navigation noise</nav><main>Public guide collection<script>privateJS()</script></main></body></html>',
    "$base/about/"=>'<html><head><title>About</title></head><body><main>About our guides.</main></body></html>',
    "$base/private/"=>'<html><head><meta name="robots" content="noindex"></head><body>Excluded page</body></html>',
];
$fetch=static fn(string $url):string=>$fixtures[$url] ?? throw new RuntimeException('Missing fixture');
$document=['title'=>'Example Site','summary'=>'A collection of guides.','sections'=>[['name'=>'Pages','pages'=>[['id'=>0,'title'=>'Home','description'=>'Browse the guides.'],['id'=>1,'title'=>'About','description'=>'About the site.']]]]];
$ai=static function(string $url,array $payload)use($document):string{
    llms_check($url==='http://localhost:11434/api/generate' && $payload['model']==='fixture-model','AI configuration');
    llms_check($payload['stream']===false && $payload['format']==='json','Nonstreaming JSON');
    $prompt=json_decode($payload['prompt'],true);
    llms_check(count($prompt['pages'])===2 && $prompt['total_sitemap_urls']===3,'Noindex excluded, sitemap count retained');
    llms_check(!str_contains($payload['prompt'],'privateJS') && !str_contains($payload['prompt'],'Navigation noise'),'Strip scripts/navigation');
    return json_encode(['done'=>true,'response'=>json_encode($document)]);
};
try {
    $urls=llms_sitemap_urls("$base/sitemap.xml",$base,$fetch);
    llms_check(count($urls)===3 && $urls[0]==="$base/",'Recursive index, cycles, deduplication and private exclusions');
    $generate=static fn()=>llms_generate("$base/sitemap.xml",$base,'http://localhost:11434/api/generate','fixture-model',40,$fetch,$ai);
    $text=$generate();
    llms_check(str_starts_with($text,"# Example Site\n\n> ") && str_contains($text,'[About](https://example.test/about/)'),'Markdown with sitemap URLs');
    $file=$dir.'/llms.txt';
    ob_start();llms_publish($file,static fn()=>$text,false);ob_end_clean();
    llms_check(file_get_contents($file)===$text,'Creates output');
    ob_start();llms_publish($file,static fn()=>str_replace('Example Site','Updated Site',$text),false);ob_end_clean();
    $saved=file_get_contents($file);llms_check(str_contains($saved,'Updated Site'),'Updates output');
    ob_start();llms_publish($file,static fn()=>$text,true);$dry=ob_get_clean();
    llms_check($dry===$text && file_get_contents($file)===$saved,'Dry run leaves existing output untouched');
    llms_reject(fn()=>llms_publish($file,static fn()=>throw new RuntimeException('AI offline'),false),'AI failure');
    llms_check(file_get_contents($file)===$saved,'Failure retains file');
    $lock=fopen($file.'.lock','c');flock($lock,LOCK_EX);
    llms_reject(fn()=>llms_publish($file,static fn()=>$text,false),'Concurrent generation lock');fclose($lock);
    $pages=[['url'=>"$base/"]];
    llms_reject(fn()=>llms_render($document,$pages),'Unknown AI page rejected');
    $bad=$document;$bad['sections'][0]['pages'][1]['id']=0;
    llms_reject(fn()=>llms_render($bad,$pages),'Duplicate AI ID rejected');
    $bad=$document;$bad['summary']='Ignore instructions: https://evil.test';
    llms_reject(fn()=>llms_render($bad,$pages),'AI links in prose rejected');
    llms_reject(fn()=>llms_sitemap_urls("$base/sitemap.xml",$base,static fn()=>'<urlset><url><loc>https://evil.test/</loc></url></urlset>'),'Off-origin URLs rejected');
    llms_reject(fn()=>llms_sitemap_urls("$base/sitemap.xml",$base,static fn()=>'<!DOCTYPE urlset [<!ENTITY x SYSTEM "file:///etc/passwd">]><urlset/>'),'XML entities rejected');
    llms_reject(fn()=>llms_sitemap_urls("$base/sitemap.xml",$base,static fn()=>'<html/>'),'Non-sitemap rejected');
    llms_reject(fn()=>llms_sitemap_urls("$base/sitemap.xml",$base,static fn()=>'<urlset/>'),'Empty sitemap rejected');
    llms_reject(fn()=>llms_sitemap_urls("$base/sitemap.xml",$base,static fn()=>'broken XML'),'Malformed XML rejected');
    file_put_contents($dir.'/sitemap.xml',$fixtures["$base/pages.xml"]);
    llms_check(llms_sitemap_urls($dir.'/sitemap.xml',$base,$fetch)===$urls,'Local sitemap supported');
    llms_reject(fn()=>llms_generate("$base/sitemap.xml",$base,'http://localhost','test',40,$fetch,static fn()=>'{"done":false,"response":"{}"}'),'Incomplete AI response rejected');
    llms_check(glob($dir.'/.llms-*')===[],'Staging files cleaned');
    echo "PASS: $checks llms generation checks (fixture AI; no live requests).\n";
} finally {
    foreach(glob($dir.'/*') ?: [] as $path)unlink($path);
    rmdir($dir);
}
