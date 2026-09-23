<?php
declare(strict_types=1);

// Run: php scripts/generate-game-sitemaps.php
require_once __DIR__ . '/../../includes/blog-storage.php';
require_once __DIR__ . '/../../includes/game-restrictions.php';

const GAME_SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

function game_sitemap_document(string $root): DOMDocument
{
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;
    $doc->appendChild($doc->createElementNS(GAME_SITEMAP_NS, $root));
    return $doc;
}

function game_sitemap_load(string $xml): DOMDocument
{
    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = false;
    $doc->formatOutput = true;
    $previous = libxml_use_internal_errors(true);
    try {
        if (!$doc->loadXML($xml, LIBXML_NONET) || $doc->doctype !== null
            || $doc->documentElement->namespaceURI !== GAME_SITEMAP_NS
            || !in_array($doc->documentElement->localName, ['urlset', 'sitemapindex'], true)) {
            throw new RuntimeException('Invalid sitemap XML.');
        }
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    return $doc;
}

function game_sitemap_entry(DOMDocument $doc, string $type, array $fields): void
{
    $entry = $doc->createElementNS(GAME_SITEMAP_NS, $type);
    foreach ($fields as $key => $value) {
        $element = $doc->createElementNS(GAME_SITEMAP_NS, $key);
        $element->appendChild($doc->createTextNode($value));
        $entry->appendChild($element);
    }
    $doc->documentElement->appendChild($entry);
}

/** Uses a read-only connection: deliberately avoids blogs_pdo() and its migrations. */
function generate_game_sitemaps(string $dbPath, string $output): array
{
    if (!is_file($dbPath) || !is_dir($output)) {
        throw new RuntimeException('Database or output directory does not exist.');
    }
    $lockPath = realpath($output) === site_root_path() ? app_root_path() . '/.game-sitemaps.lock' : $output . '/.game-sitemaps.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another sitemap generation is running.');
    }
    $staged = [];
    try {
        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            (defined('Pdo\\Sqlite::ATTR_OPEN_FLAGS') ? constant('Pdo\\Sqlite::ATTR_OPEN_FLAGS') : PDO::SQLITE_ATTR_OPEN_FLAGS)
                => (defined('Pdo\\Sqlite::OPEN_READONLY') ? constant('Pdo\\Sqlite::OPEN_READONLY') : PDO::SQLITE_OPEN_READONLY),
        ]);
        $pdo->exec('PRAGMA query_only = ON');
        $allowed = game_public_eligibility_sql();
        $rows = $pdo->query("SELECT MIN(id) AS id, slug, MAX(updated_at) AS updated_at
                            FROM games WHERE $allowed GROUP BY slug ORDER BY id ASC");
        $games = [];
        $excluded = (int) $pdo->query('SELECT COUNT(*) FROM games WHERE published=1 AND done_processing=1 AND (' . game_visibility_sql() . ') AND (' . game_public_slug_sql() . ') AND NOT (' . games_ph_allowed_sql() . ')')->fetchColumn();
        foreach ($rows as $row) {
            $games[] = $row;
        }
        $files = [];
        $chunks = [];
        $checkerUrls = [];
        foreach (array_chunk($games, 1000) as $i => $chunk) {
            $name = 'sitemap-games-' . ($i + 1) . '.xml';
            $doc = game_sitemap_document('urlset');
            foreach ($chunk as $game) {
                $fields = ['loc' => public_url('/game/' . rawurlencode($game['slug']) . '/')];
                $checkerUrls[$fields['loc']] = public_url('/' . $name);
                if ((int) $game['updated_at'] > 0) {
                    $fields['lastmod'] = gmdate('Y-m-d', (int) $game['updated_at']);
                }
                $fields += ['changefreq' => 'weekly', 'priority' => '0.6'];
                game_sitemap_entry($doc, 'url', $fields);
            }
            $files[$name] = $doc->saveXML();
            $chunks[] = $name;
        }
        $indexPath = $output . '/sitemap-index.xml';
        $index = is_file($indexPath)
            ? game_sitemap_load((string) file_get_contents($indexPath))
            : game_sitemap_document('sitemapindex');
        $defaults = [];
        if ($index->documentElement->localName === 'urlset') {
            // Preserve legacy page entries verbatim in a separate sitemap.
            if (is_file($output . '/sitemap-pages.xml')) {
                throw new RuntimeException('Cannot migrate legacy index: sitemap-pages.xml already exists.');
            }
            $files['sitemap-pages.xml'] = $index->saveXML();
            $index = game_sitemap_document('sitemapindex');
            $defaults[] = 'sitemap-pages.xml';
        }
        foreach (['sitemap.xml', 'sitemap-blog.php'] as $name) {
            if (is_file($output . '/' . $name)) {
                $defaults[] = $name;
            }
        }
        $existing = [];
        foreach (iterator_to_array($index->getElementsByTagNameNS(GAME_SITEMAP_NS, 'sitemap')) as $entry) {
            $loc = trim($entry->getElementsByTagNameNS(GAME_SITEMAP_NS, 'loc')->item(0)?->textContent ?? '');
            $path = parse_url($loc, PHP_URL_PATH) ?? '';
            if (preg_match('~^/sitemap-games(?:-[0-9]+)?\.(?:xml|php)$~', $path)
                && parse_url($loc, PHP_URL_HOST) === parse_url(site_base_url(), PHP_URL_HOST)) {
                $entry->parentNode->removeChild($entry);
            } else {
                $existing[$loc] = true;
            }
        }
        foreach (array_merge($defaults, $chunks) as $name) {
            $loc = public_url('/' . $name);
            if (!isset($existing[$loc])) {
                game_sitemap_entry($index, 'sitemap', ['loc' => $loc]);
                $existing[$loc] = true;
            }
        }
        $files['sitemap-index.xml'] = $index->saveXML();
        $robotsPath = $output . '/robots.txt';
        $robots = is_file($robotsPath) ? (string) file_get_contents($robotsPath) : '';
        $directive = 'Sitemap: ' . public_url('/sitemap-index.xml');
        if (!preg_match('~^\h*Sitemap:\h*' . preg_quote(public_url('/sitemap-index.xml'), '~') . '\h*$~mi', $robots)) {
            $robots = rtrim($robots) . "\n" . $directive . "\n";
        }
        // Validate and stage every file before replacing any published file.
        foreach ($files as $name => $xml) {
            game_sitemap_load($xml);
        }
        $files['robots.txt'] = $robots;
        foreach ($files as $name => $contents) {
            $tmp = tempnam($output, '.sitemap-');
            if ($tmp === false) {
                throw new RuntimeException('Cannot stage sitemap.');
            }
            $staged[$name] = $tmp;
            if (file_put_contents($tmp, $contents) !== strlen($contents) || !chmod($tmp, 0644)) {
                throw new RuntimeException('Cannot write staged sitemap.');
            }
        }
        foreach ($staged as $name => $tmp) {
            if (!rename($tmp, $output . '/' . $name)) {
                throw new RuntimeException('Cannot publish ' . $name);
            }
            unset($staged[$name]);
        }
        // Remove only numbered chunks, after the new index has been published.
        foreach (glob($output . '/sitemap-games-*.xml') ?: [] as $path) {
            if (preg_match('/^sitemap-games-[0-9]+\.xml$/', basename($path))
                && !in_array(basename($path), $chunks, true) && !unlink($path)) {
                throw new RuntimeException('Cannot remove obsolete chunk ' . basename($path));
            }
        }
        // Only update an already installed Index Checker, after successful publication.
        if ($pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='index_checks'")->fetchColumn()) {
            require_once __DIR__ . '/../../includes/index-checker.php';
            $writer = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $writer->exec('PRAGMA busy_timeout=5000');
            index_checker_sync_game_sitemaps($writer, $checkerUrls, time());
        }
        if ($dbPath === games_db_path()) {
            $shared = blogs_pdo();
            if ($shared->query("SELECT 1 FROM main.sqlite_master WHERE type='table' AND name='index_checks'")->fetchColumn()) {
                require_once __DIR__ . '/../../includes/index-checker.php';
                index_checker_sync_game_sitemaps($shared, $checkerUrls, time());
            }
        }
        return ['eligible' => count($games), 'excluded' => $excluded, 'chunks' => count($chunks)];
    } finally {
        foreach ($staged as $tmp) {
            if (is_file($tmp)) unlink($tmp);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

if ((realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ || (defined('GAMES_SCRIPT_ENTRY') && GAMES_SCRIPT_ENTRY === basename(__FILE__)))) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }
    try {
        blogs_pdo(); // Apply schema defaults before opening the read-only sitemap snapshot.
        $result = generate_game_sitemaps(games_db_path(), site_root_path());
        printf("Eligible games: %s\nPH-restricted excluded: %s\nSitemaps generated: %d\nURLs written: %s\nSitemap index updated.\nrobots.txt verified.\n",
            number_format($result['eligible']), number_format($result['excluded']), $result['chunks'], number_format($result['eligible']));
    } catch (Throwable $error) {
        fwrite(STDERR, 'Sitemap generation failed: ' . $error->getMessage() . "\n");
        exit(1);
    }
}
