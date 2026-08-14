<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/blog-storage.php';

function games_sitemap_base_url(): string
{
    $baseUrl = env_value('SITE_BASE_URL');
    if (!is_string($baseUrl) || $baseUrl === '') {
        $baseUrl = 'https://gperya-apk.com';
    }

    return rtrim($baseUrl, '/');
}

function games_sitemap_xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

header('Content-Type: application/xml; charset=UTF-8');

$baseUrl = games_sitemap_base_url();
$stmt = blogs_pdo()->query(
    'SELECT slug, MAX(updated_at) AS updated_at
     FROM games
     WHERE slug <> "" AND published = 1
     GROUP BY slug
     ORDER BY slug ASC'
);
$games = $stmt->fetchAll();

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
echo "  <url>\n";
echo "    <loc>" . games_sitemap_xml_escape($baseUrl . '/slots/') . "</loc>\n";
echo "    <changefreq>daily</changefreq>\n";
echo "    <priority>0.8</priority>\n";
echo "  </url>\n";

foreach ($games as $game) {
    $slug = normalize_slug($game['slug'] ?? '');
    if ($slug === '') {
        continue;
    }

    $updatedAt = isset($game['updated_at']) ? (int) $game['updated_at'] : time();
    if ($updatedAt <= 0) {
        $updatedAt = time();
    }

    echo "  <url>\n";
    echo "    <loc>" . games_sitemap_xml_escape($baseUrl . '/game/' . rawurlencode($slug) . '/') . "</loc>\n";
    echo "    <lastmod>" . gmdate('Y-m-d', $updatedAt) . "</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.6</priority>\n";
    echo "  </url>\n";
}

echo "</urlset>\n";
