<?php
/**
 * Checks WordPress XML parsing, metadata, Markdown conversion, statuses, and unsafe input
 * rejection.
 *
 * Parses inline XML fixtures and renders the converted content without importing posts. Requires
 * XML/DOM. An optional XML path checks the three-post sample export.
 *
 * Run from www: php admin/test/test-blog-wordpress-import.php
 * A successful run prints a pass message; failed assertions exit with an error.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/blog-storage.php';
require_once __DIR__ . '/../includes/blog-wordpress-import.php';
require_once __DIR__ . '/../includes/blog-renderer.php';
function wxr_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$xml = <<<'XML'
<rss xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"><channel>
<wp:wxr_version>1.2</wp:wxr_version>
<wp:author><wp:author_login>writer</wp:author_login><wp:author_display_name>Test Writer</wp:author_display_name></wp:author>
<item><title>Test article</title><wp:post_type>post</wp:post_type><wp:post_name>test-article</wp:post_name><wp:status>publish</wp:status><wp:post_date_gmt>2026-05-23 01:59:51</wp:post_date_gmt><dc:creator>writer</dc:creator><category domain="category">Sports News</category><category domain="post_tag">Football</category><content:encoded><![CDATA[<!-- wp:heading --><h2>Heading</h2><p>Hello <strong>world</strong> <a href="https://example.com">link</a>.</p><img src="https://example.com/image.jpg" alt="Photo"><script>alert(1)</script><a href="javascript:alert(1)">Bad link</a>]]></content:encoded><wp:postmeta><wp:meta_key>_thumbnail_id</wp:meta_key><wp:meta_value>20</wp:meta_value></wp:postmeta><wp:postmeta><wp:meta_key>_yoast_wpseo_metadesc</wp:meta_key><wp:meta_value>SEO description</wp:meta_value></wp:postmeta></item>
<item><wp:post_type>attachment</wp:post_type><wp:post_id>20</wp:post_id><wp:attachment_url>https://example.com/featured.jpg</wp:attachment_url></item>
<item><wp:post_type>page</wp:post_type></item>
</channel></rss>
XML;
$m = blog_wordpress_manifest($xml); $b = $m['blogs'][0];
wxr_check(count($m['blogs']) === 1 && $m['skipped'] === 2, 'Only blog posts imported');
wxr_check($b['slug'] === 'test-article' && $b['author'] === 'Test Writer', 'Identity and author');
wxr_check($b['category'] === 'Sports News' && $b['wordpressTags'] === ['Football'], 'Taxonomy');
wxr_check($b['excerpt'] === 'SEO description' && $b['featuredImage'] === 'https://example.com/featured.jpg', 'Metadata and attachment mapping');
wxr_check($b['publishedAt'] === strtotime('2026-05-23 01:59:51 UTC'), 'UTC dates');
$html = blog_render_markdown($b['content']);
wxr_check(str_contains($html, '<h2>Heading</h2>') && str_contains($html, '<strong>world</strong>') && str_contains($html, '<img src="https://example.com/image.jpg"'), 'Content renders as headings, emphasis, images');
wxr_check(!str_contains($html, 'javascript:') && !str_contains($html, 'alert(1)') && !str_contains($html, 'wp:heading'), 'Unsafe and WordPress markup removed');
foreach (['draft' => 'draft', 'pending' => 'draft', 'private' => 'draft', 'future' => 'scheduled'] as $input => $expected) {
    $post = blog_wordpress_manifest(str_replace('>publish<', '>' . $input . '<', $xml))['blogs'][0];
    wxr_check($post['status'] === $expected, 'Status: ' . $input);
    if ($input === 'future') wxr_check($post['scheduledAt'] === $b['publishedAt'], 'Scheduled date');
}
wxr_check(blog_wordpress_manifest(str_replace('<wp:status>', '<wp:post_password>secret</wp:post_password><wp:status>', $xml))['blogs'][0]['status'] === 'draft', 'Password-protected post stays unpublished');
foreach (['<rss>', '<rss><channel/></rss>', '<!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]>' . $xml] as $bad) {
    try { blog_wordpress_manifest($bad); throw new RuntimeException('Invalid XML accepted'); }
    catch (InvalidArgumentException $e) {}
}
wxr_check(!str_contains(blog_wordpress_markdown("<p>:::custom-code\n---html\n&lt;script&gt;alert(1)&lt;/script&gt;\n:::</p>"), ":::"), 'Editor block injection rejected');
if (isset($argv[1])) {
    $actual = blog_wordpress_manifest(file_get_contents($argv[1]));
    wxr_check(count($actual['blogs']) === 3, 'Provided export has three posts');
    foreach ($actual['blogs'] as $post) {
        wxr_check($post['content'] !== '' && strlen($post['content']) <= 60000, 'Content fits importer');
        wxr_check(str_contains(blog_render_markdown($post['content']), '<h2>'), 'Provided post headings render');
    }
    echo "Provided WordPress export: 3 posts parsed successfully.\n";
}
echo "WordPress import tests passed.\n";
