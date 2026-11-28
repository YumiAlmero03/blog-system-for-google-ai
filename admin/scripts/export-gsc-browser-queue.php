<?php
declare(strict_types=1);

/** Read-only queue export. Deliberately does not load the application bootstrap. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function gsc_browser_export_origin(string $url): ?string
{
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        return null;
    }
    $scheme = strtolower($parts['scheme']);
    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
}

function gsc_browser_export_site(string $site): string
{
    $parts = parse_url($site);
    if (!is_array($parts) || strlen($site) > 2048 || filter_var($site, FILTER_VALIDATE_URL) === false
        || strtolower($parts['scheme'] ?? '') !== 'https'
        || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || !in_array($parts['path'] ?? '', ['', '/'], true)
        || (isset($parts['port']) && $parts['port'] !== 443)
        || preg_match('/[\x00-\x20\x7f\\\\]/', $site)) {
        throw new InvalidArgumentException('--site must be a public HTTPS origin, for example https://example.com.');
    }
    $host = strtolower($parts['host'] ?? '');
    if (filter_var($host, FILTER_VALIDATE_IP) !== false
        || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host)
        || preg_match('/(?:^|\.)(?:localhost|local|internal|invalid|test|example|onion)$/D', $host)) {
        throw new InvalidArgumentException('--site must use a public domain; localhost, IP addresses, and private names are not allowed.');
    }
    return 'https://' . $host;
}

function gsc_browser_export_safe_url(string $url, string $site): bool
{
    if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false
        || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
        || gsc_browser_export_origin($url) !== gsc_browser_export_origin($site)) {
        return false;
    }
    $parts = parse_url($url);
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        return false;
    }
    $path = rawurldecode($parts['path'] ?? '/');
    if (preg_match('/[\x00-\x20\x7f\\\\]/', $path) || str_contains($path, '//')
        || preg_match('~(?:^|/)\.{1,2}(?:/|$)|%[0-9a-f]{2}~i', $path)) {
        return false;
    }
    return !preg_match('~^/(?:admin|api|storage|uploads|chats|scripts|includes|private|promo|promo-code|bonus|login[^/]*|logout[^/]*|\.)(?:/|$)|(?:^|/)\.~i', $path);
}

function gsc_browser_export_open(string $path): PDO
{
    if (!str_starts_with($path, '/') || !is_file($path) || !is_readable($path)) {
        throw new InvalidArgumentException('--db must name an existing readable SQLite file using an absolute path.');
    }
    $resolved = realpath($path);
    if ($resolved === false) {
        throw new RuntimeException('Unable to resolve the SQLite file.');
    }
    // URI mode=ro prevents accidental writes or creation, including before query_only is set.
    $uri = str_replace('%2F', '/', rawurlencode($resolved));
    $pdo = new PDO('sqlite:file:' . $uri . '?mode=ro', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA query_only = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

function gsc_browser_export_has_table(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$name]);
    return $stmt->fetchColumn() !== false;
}

function gsc_browser_export_type(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH) ?: '/';
    if (preg_match('~^/blogs?/[^/]+/?$~D', $path)) {
        return 'blog';
    }
    if (preg_match('~^/games?/[^/]+/?$~D', $path)) {
        return 'game';
    }
    return 'page';
}

function gsc_browser_export_slugs(PDO $pdo, string $type, int $now): array
{
    if ($type === 'blog') {
        // Mirrors blog_public_visibility_sql(); importing blog-storage.php would bootstrap writes.
        $stmt = $pdo->prepare("SELECT slug FROM blog_posts WHERE status='published' OR (status='scheduled' AND scheduled_at IS NOT NULL AND scheduled_at<=?)");
        $stmt->execute([$now]);
    } else {
        // These files declare pure SQL helpers only; no storage bootstrap or migrations execute.
        require_once __DIR__ . '/../plugin/games/includes/game-visibility.php';
        $stmt = $pdo->query('SELECT DISTINCT slug FROM games WHERE ' . game_public_eligibility_sql());
    }
    return array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
}

/** Submission history is read only; even uncertain attempts must be excluded from automatic retries. */
function gsc_browser_export_ledger(?string $file): array
{
    if ($file === null) {
        return [];
    }
    if (!str_starts_with($file, '/')) {
        throw new InvalidArgumentException('--ledger must be an absolute path to the private submission ledger.');
    }
    if (!file_exists($file)) {
        return [];
    }
    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException('The submission ledger is not a readable file.');
    }
    $raw = file_get_contents($file);
    if ($raw === false) {
        throw new RuntimeException('Unable to read the submission ledger.');
    }
    try {
        $ledger = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Invalid submission ledger JSON; inspect it instead of resetting submission history.');
    }
    if (!$ledger instanceof stdClass || ($ledger->version ?? null) !== 1
        || !is_string($ledger->property ?? null) || $ledger->property === ''
        || !($ledger->records ?? null) instanceof stdClass) {
        throw new RuntimeException('Invalid submission ledger structure; expected version 1, a property string, and an object of records.');
    }
    $recorded = [];
    foreach (get_object_vars($ledger->records) as $url => $record) {
        if (!$record instanceof stdClass || !is_string($record->status ?? null)) {
            throw new RuntimeException('Invalid submission ledger record; every record must contain a string status.');
        }
        $recorded[$url] = true;
    }
    return $recorded;
}

/** Returns strings only; diagnostic messages contain no Google configuration or credentials. */
function gsc_browser_export_queue(string $db, string $site, string $type = 'all', int $limit = 1000, ?callable $warn = null, ?string $ledger = null): array
{
    $site = gsc_browser_export_site($site);
    if (!in_array($type, ['all', 'blog', 'game', 'page'], true) || $limit < 1 || $limit > 10000) {
        throw new InvalidArgumentException('--type must be all, blog, game, or page; --limit must be between 1 and 10000.');
    }
    $warn ??= static function (string $message): void { fwrite(STDERR, $message . "\n"); };
    $recorded = gsc_browser_export_ledger($ledger);
    $pdo = gsc_browser_export_open($db);
    if (!gsc_browser_export_has_table($pdo, 'index_checks')) {
        throw new RuntimeException('The database has no index_checks table. Run the existing Index Checker on the live site first.');
    }
    $rows = $pdo->query("SELECT id,url,last_checked_at,created_at FROM index_checks
        WHERE in_sitemap=1 AND check_status<>'Indexed'
        AND COALESCE(robots_state,'')<>'DISALLOWED'
        AND COALESCE(indexing_state,'') NOT IN ('BLOCKED_BY_META_TAG','BLOCKED_BY_HTTP_HEADER')")->fetchAll();
    $candidates = [];
    $wrongOrigin = 0;
    $sameOrigin = 0;
    foreach ($rows as $row) {
        $row['type'] = gsc_browser_export_type((string) $row['url']);
        if ($type !== 'all' && $row['type'] !== $type) {
            continue;
        }
        if (gsc_browser_export_origin((string) $row['url']) !== gsc_browser_export_origin($site)) {
            $wrongOrigin++;
            continue;
        }
        $sameOrigin++;
        if (gsc_browser_export_safe_url((string) $row['url'], $site)) {
            // Match JavaScript URL.href for accepted HTTPS URLs: lowercase host,
            // omit default :443, and spell an empty root path as /. Keep the path
            // unchanged so slug eligibility and URL identity use the stored path.
            $row['url'] = $site . (parse_url((string) $row['url'], PHP_URL_PATH) ?: '/');
            $candidates[] = $row;
        }
    }
    if ($wrongOrigin > 0 && $sameOrigin === 0) {
        throw new RuntimeException('All candidate URLs use a different origin than --site. Check the live Website URL and run Index Checker sitemap discovery again; localhost URLs are never rewritten to a public domain.');
    }
    if ($wrongOrigin > 0) {
        $warn('Skipped ' . $wrongOrigin . ' URL(s) outside the explicit --site origin.');
    }
    $types = array_column($candidates, 'type');
    $sets = [];
    $now = time();
    if (in_array('blog', $types, true)) {
        if (gsc_browser_export_has_table($pdo, 'blog_posts')) {
            $sets['blog'] = gsc_browser_export_slugs($pdo, 'blog', $now);
        } else {
            $sets['blog'] = [];
            $warn('Skipped blog URLs: blog_posts is unavailable, so public visibility cannot be verified.');
        }
    }
    if (in_array('game', $types, true)) {
        $gamePdo = $pdo;
        $gameFile = dirname((string) realpath($db)) . '/games.sqlite';
        try {
            if (!gsc_browser_export_has_table($gamePdo, 'games') && is_file($gameFile)) {
                $gamePdo = gsc_browser_export_open($gameFile);
            }
            if (!gsc_browser_export_has_table($gamePdo, 'games')) {
                throw new RuntimeException('Game catalogue is unavailable.');
            }
            $sets['game'] = gsc_browser_export_slugs($gamePdo, 'game', $now);
        } catch (Throwable $error) {
            $sets['game'] = [];
            $warn('Skipped game URLs: the existing game catalogue/visibility schema is unavailable; public eligibility cannot be verified.');
        }
    }
    $priority = ['blog' => 0, 'page' => 1, 'game' => 2];
    usort($candidates, static function (array $a, array $b) use ($priority): int {
        return [$priority[$a['type']], $a['last_checked_at'] === null ? 0 : 1, (int) $a['last_checked_at'], (int) $a['created_at'], (int) $a['id']]
            <=> [$priority[$b['type']], $b['last_checked_at'] === null ? 0 : 1, (int) $b['last_checked_at'], (int) $b['created_at'], (int) $b['id']];
    });
    $urls = [];
    foreach ($candidates as $row) {
        // Apply history before --limit so earlier submitted rows cannot starve the remaining backlog.
        if (isset($recorded[$row['url']])) {
            continue;
        }
        if (isset($sets[$row['type']])) {
            $slug = basename(trim((string) parse_url($row['url'], PHP_URL_PATH), '/'));
            if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) || strlen($slug) > 96 || !isset($sets[$row['type']][$slug])) {
                continue;
            }
        }
        $urls[$row['url']] = true;
        if (count($urls) >= $limit) {
            break;
        }
    }
    return ['version' => 1, 'site' => $site, 'urls' => array_keys($urls)];
}

function gsc_browser_export_main(array $argv): int
{
    try {
        $options = [];
        for ($i = 1; $i < count($argv); $i++) {
            if ($argv[$i] === '--help') {
                fwrite(STDOUT, "Usage: php export-gsc-browser-queue.php --db /absolute/path/blogs.sqlite --site https://example.com [--type all|blog|game|page] [--limit 1000] [--ledger /private/ledger.json]\n");
                return 0;
            }
            $pair = explode('=', $argv[$i], 2);
            if (!in_array($pair[0], ['--db', '--site', '--type', '--limit', '--ledger'], true) || isset($options[$pair[0]])) {
                throw new InvalidArgumentException('Unknown or repeated option: ' . $pair[0]);
            }
            $value = $pair[1] ?? ($argv[++$i] ?? '');
            if ($value === '' || str_starts_with($value, '--')) {
                throw new InvalidArgumentException('Missing value for ' . $pair[0]);
            }
            $options[$pair[0]] = $value;
        }
        if (!isset($options['--db'], $options['--site'])) {
            throw new InvalidArgumentException('Both --db /absolute/path/blogs.sqlite and --site https://example.com are required.');
        }
        $limit = $options['--limit'] ?? '1000';
        if (!ctype_digit($limit) || strlen($limit) > 5 || (int) $limit < 1 || (int) $limit > 10000) {
            throw new InvalidArgumentException('--limit must be an integer between 1 and 10000.');
        }
        $result = gsc_browser_export_queue($options['--db'], $options['--site'], $options['--type'] ?? 'all', (int) $limit, null, $options['--ledger'] ?? null);
        fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
        return 0;
    } catch (Throwable $error) {
        fwrite(STDERR, 'GSC queue export failed: ' . $error->getMessage() . "\n");
        return 1;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(gsc_browser_export_main($argv));
}
