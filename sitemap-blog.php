<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/blog-storage.php';

function sitemap_base_url(): string
{
    $baseUrl = env_value('SITE_BASE_URL');
    if (!is_string($baseUrl) || $baseUrl === '') {
        $baseUrl = 'https://gperya-apk.com';
    }

    return rtrim($baseUrl, '/');
}

function sitemap_xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

header('Content-Type: application/xml; charset=UTF-8');

$baseUrl = sitemap_base_url();
$posts = blogs_all();

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
echo "  <url>\n";
echo "    <loc>" . sitemap_xml_escape($baseUrl . '/blog/') . "</loc>\n";
echo "    <changefreq>daily</changefreq>\n";
echo "    <priority>0.8</priority>\n";
echo "  </url>\n";

foreach ($posts as $post) {
    $slug = normalize_slug($post['slug'] ?? '');
    if ($slug === '') {
        continue;
    }

    $updatedAt = isset($post['updatedAt']) ? (int) $post['updatedAt'] : time();
    echo "  <url>\n";
    echo "    <loc>" . sitemap_xml_escape($baseUrl . '/blog/' . rawurlencode($slug) . '/') . "</loc>\n";
    echo "    <lastmod>" . gmdate('Y-m-d', $updatedAt) . "</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.7</priority>\n";
    echo "  </url>\n";
}

echo "</urlset>\n";
