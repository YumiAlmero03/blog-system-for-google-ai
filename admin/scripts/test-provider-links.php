<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$dir=sys_get_temp_dir().'/provider-links-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
putenv('APP_STORAGE_DIR='.$dir); putenv('GAME_SITEMAP_OUTPUT_DIR='.$dir); putenv('SITE_BASE_URL=https://example.test');
putenv('ADMIN_USERNAME=super_user'); putenv('ADMIN_PASSWORD_HASH='.password_hash('Visibility test password 123!',PASSWORD_DEFAULT));
putenv('ADMIN_JWT_SECRET='.str_repeat('provider-link-',6)); ini_set('session.save_path',$dir);
require_once __DIR__ . '/../includes/auth.php'; require_once __DIR__ . '/../includes/blog-renderer.php';
function pl_check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function pl_handler(array $request): array {
    $p=proc_open([PHP_BINARY,__DIR__ . '/test-game-visibility.php','--handler'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],json_encode($request)); fclose($pipes[0]); $body=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    pl_check(proc_close($p)===0 && preg_match('/^STATUS:(\d+)$/',$errors,$m)===1,'Handler failed: '.$errors); return [(int)$m[1],$body];
}
function pl_remove(string $dir): void { foreach(scandir($dir) as $f) { if ($f==='.' || $f==='..') continue; $p=$dir.'/'.$f; is_dir($p)?pl_remove($p):unlink($p); } rmdir($dir); }
try {
    $pdo=blogs_pdo(); $_SESSION['user']=auth_authenticate_credentials('super_user','Visibility test password 123!');
    foreach(['admin','editor'] as $role) admin_user_save(['username'=>$role,'display_name'=>$role,'password'=>'Visibility test password 123!','active'=>1,'role'=>$role]);
    $pdo->exec('INSERT INTO games(api_id,name,slug,provider,provider_slug,published,done_processing) VALUES(1,"One","one","First","first",1,1),(2,"Two","two","Second","second",1,1)');
    [$status,$body]=pl_handler(['path'=>'/admin/settings/providers/index.php','role'=>'admin','method'=>'GET']);
    pl_check($status===200 && str_contains($body,'Uncheck All Providers'),'Bulk control rendered');
    $doc=new DOMDocument(); @$doc->loadHTML($body); $xpath=new DOMXPath($doc);
    $keys=$xpath->query('//form[@id="uncheck-all-providers"]/input[@name="provider_keys"]')->item(0)->getAttribute('value');
    $post=['action'=>'uncheck_all','provider_keys'=>$keys];
    foreach (['editor'] as $role) { [$status]=pl_handler(['path'=>'/admin/settings/providers/index.php','role'=>$role,'post'=>$post]); pl_check($status===403,'Editor bulk denied'); }
    [$status]=pl_handler(['path'=>'/admin/settings/providers/index.php','role'=>'admin','bad_csrf'=>true,'post'=>$post]); pl_check($status===403,'Bulk CSRF');
    [$status,$body]=pl_handler(['path'=>'/admin/settings/providers/index.php','role'=>'admin','post'=>$post]);
    pl_check($status===200 && str_contains($body,'All loaded providers unchecked and saved.'),'Bulk autosave result');
    pl_check((int)$pdo->query('SELECT COUNT(*) FROM game_provider_settings WHERE approved=0')->fetchColumn()===2,'Bulk persisted');
    [$status,$body]=pl_handler(['path'=>'/api/slot-list.php','method'=>'GET']); pl_check($status===200 && json_decode($body,true)['pagination']['total']===0,'Public API follows bulk approval immediately');
    [$status,$body]=pl_handler(['path'=>'/admin/settings/providers/index.php','role'=>'admin','method'=>'GET']); @$doc->loadHTML($body); $xpath=new DOMXPath($doc);
    pl_check($xpath->query('//input[@name="approved" and @checked]')->length===0,'Refresh unchecked');
    [$status]=pl_handler(['path'=>'/admin/settings/providers/index.php','role'=>'admin','post'=>['provider_key'=>'slug:first','approved'=>'1']]);
    pl_check($status===200 && public_game_find('one')!==null,'Individual reapproval');
    [$status]=pl_handler(['path'=>'/admin/settings/providers/index.php','role'=>'admin','post'=>['action'=>'uncheck_all','provider_keys'=>json_encode(['slug:first','missing'])]]);
    pl_check($status===422 && public_game_find('one')!==null,'Invalid bulk rolls back atomically');
    $pairs=['/slots'=>'/slots/','https://example.test/blog/post'=>'https://example.test/blog/post/','/arcade'=>'/arcade/','/game/example?ref=home#faq'=>'/game/example/?ref=home#faq','https://example.test?x=1#top'=>'https://example.test/?x=1#top'];
    foreach($pairs as $before=>$after) pl_check(blog_normalize_internal_url($before)===$after,'Normalize '.$before);
    foreach(['https://external.test/slots','//external.test/slots','mailto:a@example.test','tel:123','javascript:alert(1)','#anchor','?query=1','/image.webp','/file.pdf','/script.js','/style.css','/sitemap.xml','/api/blog.php','/api/game.php','/uploads/no-extension','/slots/','https://example.test.evil.test/path'] as $url) pl_check(blog_normalize_internal_url($url)===$url,'Preserve '.$url);
    $content=<<<'MD'
[Slots](/slots) and [External](https://other.test/slots).
<a href="/arcade?x=1#part">Arcade</a>
![Image](/image.webp)

:::button
label: Play
url: /playnow?source=blog#go
:::

:::table
| Link | [Table](/game/example) |
:::

:::quote
[Quote](https://example.test/blog/example)
:::

:::faq
Q: Go?
A: [FAQ](/slots?ref=faq#top)
:::

:::custom-code
---html
<a href="/slots">Go</a><script>const html = '<a href="/unchanged">';</script>
---css
.x { background:url('/unchanged'); }
---js
const url = '/unchanged';
:::

`[Literal](/unchanged)`

```
[Code](/unchanged)
```
MD;
    $normalized=blog_normalize_content_links($content);
    foreach(['[Slots](/slots/)','href="/arcade/?x=1#part"','url: /playnow/?source=blog#go','[Table](/game/example/)','[Quote](https://example.test/blog/example/)','[FAQ](/slots/?ref=faq#top)','<a href="/slots/">Go</a>','![Image](/image.webp)','const html = \'<a href="/unchanged">\';','`[Literal](/unchanged)`','[Code](/unchanged)'] as $expected) pl_check(str_contains($normalized,$expected),'Context: '.$expected);
    pl_check(str_contains($normalized,'<a href="/arcade/?x=1#part">Arcade</a>'),'HTML closing tags unchanged');
    pl_check(blog_normalize_content_links(str_replace("\n","\r\n",$content))===str_replace("\n","\r\n",$normalized),'CRLF preservation');
    pl_check(blog_count_internal_links($normalized)===blog_count_internal_links($content),'Internal link count preserved');
    pl_check(blog_normalize_content_links($normalized)===$normalized,'Normalization idempotent');
    $blog=blog_normalize_existing(['slug'=>'links','title'=>'Links','category'=>'Guides','excerpt'=>'Excerpt','content'=>$content]);
    blogs_upsert($blog); pl_check(blogs_find('links')['content']===$normalized,'Shared save normalizes');
    $blog['slug']=$blog['id']='imported-links'; blogs_upsert_with_pdo($pdo,$blog); pl_check(blogs_find('imported-links')['content']===$normalized,'Import write normalizes');
    [$status,$body]=pl_handler(['path'=>'/api/blog.php','method'=>'GET','get'=>['slug'=>'links']]);
    pl_check($status===200 && str_contains(json_decode($body,true)['blog']['content_html'],'https://example.test/playnow/?source=blog#go'),'API rendering preserves playnow target/query');
    // Exercise the existing editor/Quick Edit save endpoint as an editor.
    $post=['id'=>'quick-links','slug'=>'quick-links','title'=>'Links','seo_title'=>'Links','category'=>'Guides','excerpt'=>'Excerpt','status'=>'published','content'=>'[New](/slots)'];
    [$status]=pl_handler(['path'=>'/admin/blog-save.php','role'=>'editor','post'=>$post]); pl_check($status===200 && blogs_find('quick-links')['content']==='[New](/slots/)','Editor/Quick Edit save path');
    pl_check((int)$pdo->query('SELECT COUNT(*) FROM games')->fetchColumn()===2,'Provider/game records preserved');
    echo "PASS: bulk persistence/refresh/public filtering, individual reapproval, CSRF/role checks, atomic rollback, internal-link exclusions and contexts, idempotence, shared save/import/editor paths and Blog API rendering.\n";
} finally { pl_remove($dir); }
