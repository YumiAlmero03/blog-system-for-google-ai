<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dir=sys_get_temp_dir().'/blog-buttons-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
putenv('APP_STORAGE_DIR='.$dir); putenv('SITE_BASE_URL=https://example.test');
putenv('ADMIN_USERNAME=super_user'); putenv('ADMIN_PASSWORD_HASH='.password_hash('Visibility test password 123!',PASSWORD_DEFAULT));
putenv('ADMIN_JWT_SECRET='.str_repeat('button-test-',6)); ini_set('session.save_path',$dir);
require_once __DIR__.'/../includes/auth.php'; require_once __DIR__.'/../includes/blog-renderer.php';
function button_check(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); }
function button_handler(array $request): array {
    $p=proc_open([PHP_BINARY,__DIR__.'/test-game-visibility.php','--handler'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],json_encode($request)); fclose($pipes[0]); $body=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    button_check(proc_close($p)===0 && preg_match('/^STATUS:(\d+)$/',$error,$m)===1,'Handler: '.$error);
    return [(int)$m[1],$body];
}
function button_cleanup(string $dir): void { foreach(scandir($dir) as $f) { if ($f==='.' || $f==='..') continue; $p=$dir.'/'.$f; is_dir($p)?button_cleanup($p):unlink($p); } rmdir($dir); }
try {
    $legacy=blog_render_button_block("url: /playnow\nnofollow: true");
    button_check(str_contains($legacy,'Open Link') && str_contains($legacy,'target="_blank"') && str_contains($legacy,'nofollow'),'Legacy defaults');
    button_check(str_contains($legacy,'href="https://example.test/playnow"'),'Playnow routing preserved');
    button_check(str_contains(blog_render_button_block('url: /playnow?source=blog'),'https://example.test/playnow?source=blog'),'Playnow tracking query preserved');
    $_SESSION['user']=auth_authenticate_credentials('super_user','Visibility test password 123!');
    admin_user_save(['username'=>'editor','display_name'=>'Editor','password'=>'Visibility test password 123!','active'=>1,'role'=>'editor']);
    $post=['slug'=>'button-post','title'=>'Button post','seo_title'=>'Button post','category'=>'Guides','excerpt'=>'Excerpt','status'=>'published','content'=>"Before\n\n:::button\nlabel: Play Now\nurl: /playnow\n:::\n\nAfter"];
    [$status,$body]=button_handler(['path'=>'/admin/blog-save.php','role'=>'editor','post'=>$post]); button_check($status===200,'Insert button: '.$body);
    $post['id']='button-post';
    $post['content']="Before\n\n:::button\nlabel: Try This Game\nurl: /game/example/\nstyle: secondary\nalign: center\nnew_tab: false\nnofollow: true\n:::\n\nAfter";
    [$status]=button_handler(['path'=>'/admin/blog-save.php','role'=>'editor','post'=>$post]); button_check($status===200,'Edit existing button');
    [$status,$body]=button_handler(['path'=>'/admin/blog-edit.php','role'=>'editor','post'=>['id'=>'button-post']]);
    button_check($status===200 && json_decode($body,true)['blog']['content']===$post['content'],'Save/reopen content unchanged');
    [$status,$body]=button_handler(['path'=>'/api/blog.php','method'=>'GET','get'=>['slug'=>'button-post']]); $html=json_decode($body,true)['blog']['content_html'];
    button_check($status===200 && str_contains($html,'text-align:center') && str_contains($html,'blog-button-link blog-slot-demo-real') && str_contains($html,'Try This Game'),'API rendering');
    preg_match('~<p class="blog-button-block".*?</p>~s',$html,$button);
    button_check(!str_contains($button[0],'target=') && str_contains($button[0],'rel="noopener noreferrer nofollow"'),'Same-tab behavior');
    [$status,$body]=button_handler(['path'=>'/blog/view.php','method'=>'GET','get'=>['slug'=>'button-post']]); button_check($status===200 && str_contains($body,$button[0]),'View/API renderer parity');
    foreach(['javascript:alert(1)','//evil.test','/\\evil.test','https://user:password@example.test/'] as $url) {
        $post['content']=":::button\nlabel: Unsafe\nurl: $url\n:::";
        [$status]=button_handler(['path'=>'/admin/blog-save.php','role'=>'editor','post'=>$post]); button_check($status===422,'Reject unsafe URL');
    }
    button_check(str_contains(blog_render_button_block("text: Alias\nurl: https://example.org/\nalign: right\nnew_tab: true"),'Alias'),'Text alias compatibility');
    echo "PASS: legacy blocks, editor saves/reopening, URL rejection, button settings, shared view/API HTML and preserved playnow path/query.\n";
} finally { button_cleanup($dir); }
