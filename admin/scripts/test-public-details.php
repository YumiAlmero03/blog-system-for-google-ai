<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dir = sys_get_temp_dir() . '/public-details-' . bin2hex(random_bytes(6)); mkdir($dir,0700);
putenv('APP_STORAGE_DIR=' . $dir);
putenv('SITE_BASE_URL=https://example.test');
require_once __DIR__ . '/../includes/public-detail.php';
require_once __DIR__ . '/../includes/blog-renderer.php';
function check_detail(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
try {
    $pdo = blogs_pdo();
    $insert = $pdo->prepare('INSERT INTO games(api_id,name,slug,url,thumb,published,done_processing,restrictions) VALUES(?,?,?,?,?,?,?,?)');
    foreach ([['good',1,1,'["US","CN"]'],['ph',1,1,'PH'],['csv-ph',1,1,'["US,PH,CN"]'],['unfinished',1,0,'[]'],['private',0,1,'[]'],['word',1,1,'["ALPHA"]']] as $i=>$g) {
        $insert->execute([$i+1,'Game '.$g[0],$g[0],'https://demo.example.test/play','/uploads/game.webp',$g[1],$g[2],$g[3]]);
    }
    check_detail(public_game_find('good')!==null && public_game_find('word')!==null,'Public games');
    foreach (['ph','csv-ph','unfinished','private','missing','BAD slug'] as $slug) check_detail(public_game_find($slug)===null,'Exclude '.$slug);
    $game = public_game_payload(public_game_find('good'));
    check_detail(str_starts_with($game['thumbnail'],'https://') && !isset($game['restrictions']),'Public URL/field whitelist');
    $markdown = <<<'MD'
## Heading
Paragraph **bold** and *italic* [link](/slots/).

[![Linked local](/uploads/blogs/local.webp)](/offers/){nofollow}

[![External **literal** & "quoted"](https://cdn.example.test/image.webp?x=1&y=2)](https://partner.example.test/deal?x=1&y=2){nofollow}

[![Follow image](https://cdn.example.test/follow.webp)](/about/)

![Standalone](https://cdn.example.test/standalone.webp)

[![Unsafe](/uploads/blogs/unsafe.webp)](javascript:alert)

### Subheading
- One
- Two

1. First
2. Second

<script>alert('unsafe')</script>

:::button
label: Go
url: /slots/
nofollow: true
:::

:::table
headings: true
| Heading | Other |
| **Bold** | *Italic* [link](/blog/) |
:::

:::custom-code
---html
<div id="trusted">Custom HTML</div>
---css
#trusted{color:red}
---js
root.dataset.ready='yes';
:::

:::slot-demo
slug: good
title: Stale name
url: https://stale.example.test/
:::

:::faq
Q: Is **this** & that supported?
A: Yes, with [details](/slots/) and *formatting*.
:::

:::quote
A **useful** quote.
:::
MD;
    $rendered = blog_render_content($markdown); $html = $rendered['content_html'];
    foreach (['<h2>Heading</h2>','<h3>Subheading</h3>','<strong>bold</strong>','<em>italic</em>','<ul>','<ol>','blog-button-link','<th>Heading</th>','id="trusted"','#trusted{color:red}',"root.dataset.ready='yes'",'Game good Demo','allowfullscreen','Fullscreen','Play for Real','<blockquote>'] as $fragment) check_detail(str_contains($html,$fragment),'Render '.$fragment);
    check_detail(!str_contains($html,':::') && !str_contains($html,"<script>alert('unsafe')"),'Markers removed/normal HTML escaped');
    check_detail(!str_contains($html,'stale.example.test'),'Selected game data');
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML($html, LIBXML_NONET);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($doc);
    check_detail($xpath->query('//div[@class="blog-slot-demo-frame"]/div[@class="blog-slot-demo-header"]//button')->length===1,'Fullscreen control inside player frame');
    check_detail($xpath->query('//div[@class="blog-slot-demo-frame"]/iframe[@allow="fullscreen"]')->length===1,'Game remains embedded with fullscreen permission');
    check_detail($xpath->query('//section[@class="blog-slot-demo-block"]//h2')->length===0,'Demo title is not H2');
    check_detail($xpath->query('//section[@class="blog-faq-block"]/details[not(@open)]/summary')->length===1,'FAQ initially collapsed with native keyboard control');
    $multipleFaq = blog_render_content(":::faq\nQ: First?\nA: First answer.\nQ: Second?\nA: Second answer.\n:::");
    check_detail(count($multipleFaq['faq_schema']['mainEntity'])===2 && $multipleFaq['faq_schema']['mainEntity'][1]['acceptedAnswer']['text']==='Second answer.','All accordion answers remain in FAQ schema');
    check_detail($rendered['faq_schema']['mainEntity'][0]['name']==='Is this & that supported?','FAQ question exact');
    check_detail($rendered['faq_schema']['mainEntity'][0]['acceptedAnswer']['text']==='Yes, with details and formatting.','FAQ answer exact');
    check_detail(blog_render_content('No FAQ')['faq_schema']===null,'No FAQ null');
    $compact = blog_render_content("## Title\n\nFirst line\nSecond line\n\nNext paragraph.")['content_html'];
    check_detail(!str_contains($compact,"\n") && str_contains($compact,'First line<br>Second line'),'Formatting newlines removed while visible breaks remain');
    $code = blog_render_content("```\nfirst\nsecond\n```")['content_html'];
    check_detail(str_contains($code,"<pre><code>first\nsecond</code></pre>"),'Preformatted code line breaks preserved');
    $script = blog_render_content(":::custom-code\n---js\n// comment\nroot.dataset.ready='yes';\n:::")['content_html'];
    check_detail(str_contains($script,"// comment\nroot.dataset.ready='yes';"),'Custom script line comments preserve next statement');
    check_detail(!str_contains(blog_render_content(":::slot-demo\nslug: ph\n:::")['content_html'],'<iframe'),'Restricted demo excluded');
    $insert = $pdo->prepare('INSERT INTO blog_posts(id,slug,title,category,author,excerpt,content,featured_image,status,date_label,scheduled_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ([['public','published',null],['draft','draft',null],['future','scheduled',time()+3600],['due','scheduled',time()-3600]] as [$slug,$status,$schedule]) {
        $insert->execute([$slug,$slug,'Title','Guides','Author','Excerpt',$markdown,'/uploads/blogs/test.webp',$status,'',$schedule,time(),time()]);
    }
    // Exercise the actual API adapter, not just the shared rendering function.
    $runner = '$_SERVER["REQUEST_METHOD"]="GET"; $_GET["slug"]="public"; require ' . var_export(dirname(__DIR__,2).'/api/blog.php',true) . ';';
    $process = proc_open([PHP_BINARY,'-r',$runner],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    check_detail(is_resource($process),'Start blog API');
    $apiOutput = stream_get_contents($pipes[1]); $apiError = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check_detail(proc_close($process)===0 && $apiError==='','Blog API returns without errors');
    $apiPayload = json_decode($apiOutput,true,512,JSON_THROW_ON_ERROR);
    check_detail(isset($apiPayload['blog']['content_html']),'Blog API contains rendered content');
    $imageDoc = new DOMDocument();
    $previousErrors = libxml_use_internal_errors(true);
    $imageDoc->loadHTML($apiPayload['blog']['content_html']);
    libxml_clear_errors(); libxml_use_internal_errors($previousErrors);
    $images = new DOMXPath($imageDoc);
    $local = $images->query('//a[img[@alt="Linked local"]]')->item(0);
    check_detail($local && $local->getAttribute('href')==='https://example.test/offers/' && str_contains($local->getAttribute('rel'),'nofollow'),'API preserves local image link and nofollow');
    $external = $images->query('//a[img[contains(@src,"cdn.example.test/image.webp")]]')->item(0);
    check_detail($external && $external->getAttribute('href')==='https://partner.example.test/deal?x=1&y=2' && str_contains($external->getAttribute('rel'),'nofollow'),'API preserves absolute image link, query and nofollow');
    $externalImage = $external->getElementsByTagName('img')->item(0);
    check_detail($externalImage->getAttribute('alt')==='External **literal** & "quoted"' && $externalImage->getAttribute('src')==='https://cdn.example.test/image.webp?x=1&y=2','Image attributes remain escaped without Markdown formatting');
    $follow = $images->query('//a[img[@alt="Follow image"]]')->item(0);
    check_detail($follow && $follow->getAttribute('href')==='https://example.test/about/' && !str_contains($follow->getAttribute('rel'),'nofollow'),'Follow image does not acquire nofollow');
    check_detail($images->query('//img[@alt="Standalone" and not(parent::a)]')->length===1,'Standalone image remains unlinked');
    check_detail($images->query('//a[starts-with(@href,"javascript:")]')->length===0,'Unsafe image link rejected');
    $before = $pdo->query('SELECT * FROM blog_posts ORDER BY id')->fetchAll();
    foreach (['public','due'] as $slug) {
        $post = public_blog_find($slug); check_detail($post!==null,'Public blog '.$slug);
        $payload = public_blog_payload($post);
        check_detail($payload['blog']['content_html']===$html && $payload['blog']['readTime']>=1,'Shared output/read time');
    }
    foreach (['draft','future','unknown'] as $slug) check_detail(public_blog_find($slug)===null,'Blog excluded '.$slug);
    check_detail($before===$pdo->query('SELECT * FROM blog_posts ORDER BY id')->fetchAll(),'Stored blogs unchanged');
    echo "PASS: game visibility/URLs, all six blocks, Markdown, trusted code isolation, exact FAQ schema, blog visibility/readTime, deterministic shared HTML, and unchanged stored blogs.\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($dir);
}
