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
