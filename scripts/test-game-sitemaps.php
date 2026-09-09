<?php
declare(strict_types=1);
require_once __DIR__ . '/generate-game-sitemaps.php';

function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$dir = sys_get_temp_dir() . '/game-sitemaps-test-' . bin2hex(random_bytes(6));
mkdir($dir);
try {
    $db = $dir . '/games.sqlite';
    $pdo = new PDO('sqlite:' . $db);
    $pdo->exec('CREATE TABLE games (id INTEGER PRIMARY KEY, slug TEXT, updated_at INTEGER, published INTEGER, done_processing INTEGER, restrictions TEXT)');
    $insert = $pdo->prepare('INSERT INTO games (slug, updated_at, published, done_processing, restrictions) VALUES (?, 1700000000, ?, ?, ?)');
    $cases = [
        ['ph', 'PH', false], ['csv-ph', 'US,PH,CN', false],
        ['csv-ok', 'US,CN', true], ['null-ok', null, true], ['empty-ok', '', true],
        ['json-ph', '["PH"]', false], ['json-csv-ph', '["US,PH,CN"]', false],
        ['json-ok', '["US","CN"]', true], ['word-ok', '["SPH","PHX","ALPHA"]', true],
        ['alias', '["Philippines"]', false], ['lower-ph', '["us, ph, cn"]', false],
    ];
    foreach ($cases as [$slug, $restriction]) $insert->execute([$slug, 1, 1, $restriction]);
    $insert->execute(['unfinished', 1, 0, null]);
    $insert->execute(['unpublished', 0, 1, null]);
    $insert->execute(['bad slug', 1, 1, null]);
    $insert->execute(['', 1, 1, null]);
    $insert->execute(['csv-ok', 1, 1, null]);
    for ($i = 0; $i < 2000; $i++) $insert->execute(['filler-' . $i, 1, 1, null]);
    $legacy = game_sitemap_document('urlset');
    game_sitemap_entry($legacy, 'url', ['loc' => public_url('/contact/')]);
    file_put_contents($dir . '/sitemap-index.xml', $legacy->saveXML());
    file_put_contents($dir . '/sitemap-blog.php', '<?php');
    file_put_contents($dir . '/robots.txt', "User-agent: *\nDisallow: /admin/\n");
    file_put_contents($dir . '/sitemap-games-99.xml', 'obsolete');
    $before = hash_file('sha256', $db);
    $result = generate_game_sitemaps($db, $dir);
    check($result === ['eligible' => 2005, 'excluded' => 6, 'chunks' => 3], 'Counts');
    $urls = [];
    foreach (glob($dir . '/sitemap-games-*.xml') as $path) {
        $doc = game_sitemap_load(file_get_contents($path));
        $locs = $doc->getElementsByTagName('loc');
        check($locs->length <= 1000, 'Chunk limit');
        foreach ($locs as $loc) $urls[] = $loc->textContent;
    }
    foreach ($cases as [$slug, , $expected]) check(in_array(public_url('/game/' . $slug . '/'), $urls, true) === $expected, $slug);
    check(!in_array(public_url('/game/unfinished/'), $urls), 'Unprocessed excluded');
    check(!in_array(public_url('/game/unpublished/'), $urls), 'Unpublished excluded');
    check(count($urls) === count(array_unique($urls)), 'No duplicates');
    check(!is_file($dir . '/sitemap-games-99.xml'), 'Stale chunk removed');
    $index = file_get_contents($dir . '/sitemap-index.xml');
    foreach (['sitemap-games-1.xml', 'sitemap-games-2.xml', 'sitemap-games-3.xml', 'sitemap-pages.xml', 'sitemap-blog.php'] as $name) check(str_contains($index, $name), 'Index entry ' . $name);
    check(str_contains(file_get_contents($dir . '/sitemap-pages.xml'), '/contact/'), 'Legacy pages preserved');
    generate_game_sitemaps($db, $dir);
    check($index === file_get_contents($dir . '/sitemap-index.xml'), 'Stable repeat');
    check(substr_count(file_get_contents($dir . '/robots.txt'), 'Sitemap: ' . public_url('/sitemap-index.xml')) === 1, 'Robots no duplicate');
    check($before === hash_file('sha256', $db), 'Database unchanged');
    $pdo->exec("DELETE FROM games WHERE slug LIKE 'filler-%'");
    generate_game_sitemaps($db, $dir);
    check(!is_file($dir . '/sitemap-games-2.xml') && !is_file($dir . '/sitemap-games-3.xml'), 'Shrink cleanup');
    check(!str_contains(file_get_contents($dir . '/sitemap-index.xml'), 'sitemap-games-2.xml'), 'Shrink index');
    $pdo->exec('DELETE FROM games');
    check(generate_game_sitemaps($db, $dir)['chunks'] === 0, 'Zero games');
    check(glob($dir . '/sitemap-games-*.xml') === [], 'Zero cleanup');
    file_put_contents($dir . '/sitemap-index.xml', '<broken>');
    $robots = file_get_contents($dir . '/robots.txt');
    $failed = false;
    try { generate_game_sitemaps($db, $dir); } catch (RuntimeException $e) { $failed = true; }
    check($failed && file_get_contents($dir . '/robots.txt') === $robots, 'Invalid XML fails before publishing');
    echo "PASS: all 10 requested checks, repeatability, read-only database, legacy preservation, zero games, and invalid XML failure.\n";
} finally {
    foreach (glob($dir . '/*') as $path) unlink($path);
    foreach (glob($dir . '/.*') as $path) if (is_file($path)) unlink($path);
    rmdir($dir);
}
