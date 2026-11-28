<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../scripts/export-gsc-browser-queue.php';

function gsc_export_check(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
}

function gsc_export_cli(array $arguments): array
{
    $process = proc_open([PHP_BINARY, __DIR__ . '/../scripts/export-gsc-browser-queue.php', ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Unable to start exporter subprocess.'); }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

$directory = sys_get_temp_dir() . '/gsc-export-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$db = $directory . '/blogs.sqlite';
$gamesDb = $directory . '/games.sqlite';
$site = 'https://example.com';
try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE index_checks (
        id INTEGER PRIMARY KEY, url TEXT UNIQUE, content_type TEXT, in_sitemap INTEGER DEFAULT 1,
        check_status TEXT DEFAULT 'Pending', robots_state TEXT DEFAULT '', indexing_state TEXT DEFAULT '',
        last_checked_at INTEGER, created_at INTEGER DEFAULT 1)");
    $pdo->exec('CREATE TABLE blog_posts (slug TEXT, status TEXT, scheduled_at INTEGER)');
    $pdo->exec('CREATE TABLE app_settings (setting_key TEXT,setting_value TEXT)');
    $pdo->exec("INSERT INTO app_settings VALUES ('google_config','NEVER_EXPORT_PRIVATE_CREDENTIALS')");
    $insert = $pdo->prepare('INSERT INTO index_checks(url,content_type,last_checked_at) VALUES(?,?,?)');
    foreach ([
        ['/game/good/', 'game', null], ['/about/', 'page', 10],
        ['/blog/older/', 'blog', 20], ['/blog/newer/', 'blog', 30],
        ['/blog/never/', 'blog', null], ['/blog/scheduled/', 'blog', 40],
        ['/blog/draft/', 'blog', null], ['/blog/future/', 'blog', null],
        ['/game/hidden/', 'game', null], ['/game/unprocessed/', 'game', null],
        ['/game/restricted/', 'game', null], ['/game/restricted-csv/', 'game', null],
        ['/game/blocked-provider/', 'game', null], ['/game/unpublished/', 'game', null],
        ['/admin/', 'page', null], ['/api/private/', 'page', null],
        ['/%61dmin/secret/', 'page', null], ['/%2561dmin/secret/', 'page', null],
        ['/private/', 'page', null], ['//admin/', 'page', null], ['/about/?a=1', 'page', null],
        ['/about/#fragment', 'page', null], ['/../admin/', 'page', null],
        ['/indexed/', 'page', null], ['/removed/', 'page', null],
        ['/robots-blocked/', 'page', null], ['/meta-blocked/', 'page', null], ['/header-blocked/', 'page', null],
    ] as [$path, $type, $checked]) { $insert->execute([$site . $path, $type, $checked]); }
    $pdo->exec("UPDATE index_checks SET check_status='Indexed' WHERE url='$site/indexed/'");
    $pdo->exec("UPDATE index_checks SET in_sitemap=0 WHERE url='$site/removed/'");
    $pdo->exec("UPDATE index_checks SET robots_state='DISALLOWED' WHERE url='$site/robots-blocked/'");
    $pdo->exec("UPDATE index_checks SET indexing_state='BLOCKED_BY_META_TAG' WHERE url='$site/meta-blocked/'");
    $pdo->exec("UPDATE index_checks SET indexing_state='BLOCKED_BY_HTTP_HEADER' WHERE url='$site/header-blocked/'");
    $insert->execute(['https://other.example.com/about/', 'page', null]);
    $insert->execute(['http://localhost:8082/blog/never/', 'blog', null]);
    $post = $pdo->prepare('INSERT INTO blog_posts VALUES(?,?,?)');
    foreach (['older', 'newer', 'never'] as $slug) { $post->execute([$slug, 'published', null]); }
    $post->execute(['scheduled', 'scheduled', time() - 60]);
    $post->execute(['future', 'scheduled', time() + 3600]);
    $post->execute(['draft', 'draft', null]);

    $games = new PDO('sqlite:' . $gamesDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $games->exec('CREATE TABLE games (slug TEXT, published INTEGER, done_processing INTEGER, restrictions TEXT, is_viewable INTEGER, provider_slug TEXT, provider TEXT)');
    $games->exec('CREATE TABLE game_module_settings (id INTEGER PRIMARY KEY,enabled INTEGER)');
    $games->exec('CREATE TABLE game_provider_settings (provider_key TEXT,approved INTEGER)');
    $games->exec("INSERT INTO game_provider_settings VALUES('slug:blocked',0)");
    $gameInsert = $games->prepare('INSERT INTO games VALUES(?,?,?,?,?,?,?)');
    foreach ([
        ['good', 1, 1, '[]', 1, 'good', 'Good'],
        ['hidden', 1, 1, '[]', 0, 'good', 'Good'],
        ['unprocessed', 1, 0, '[]', 1, 'good', 'Good'],
        ['restricted', 1, 1, '["PHILIPPINES"]', 1, 'good', 'Good'],
        ['restricted-csv', 1, 1, 'US, PH', 1, 'good', 'Good'],
        ['blocked-provider', 1, 1, '[]', 1, 'blocked', 'Blocked'],
        ['unpublished', 0, 1, '[]', 1, 'good', 'Good'],
    ] as $game) { $gameInsert->execute($game); }

    $before = [hash_file('sha256', $db), hash_file('sha256', $gamesDb)];
    $warnings = [];
    $warn = static function (string $message) use (&$warnings): void { $warnings[] = $message; };
    $result = gsc_browser_export_queue($db, $site, 'all', 1000, $warn);
    $expected = array_map(static fn(string $path): string => $site . $path,
        ['/blog/never/', '/blog/older/', '/blog/newer/', '/blog/scheduled/', '/about/', '/game/good/']);
    gsc_export_check($result === ['version' => 1, 'site' => $site, 'urls' => $expected], 'Eligibility, URL safety, or priority order failed.');
    gsc_export_check(count($warnings) === 1 && str_contains($warnings[0], '2 URL'), 'Wrong-origin URLs should be skipped with a diagnostic.');
    gsc_export_check(gsc_browser_export_queue($db, $site, 'all', 2, $warn)['urls'] === array_slice($expected, 0, 2), 'Limit must apply after eligibility filtering.');
    gsc_export_check(gsc_browser_export_queue($db, $site, 'game', 10, $warn)['urls'] === [$site . '/game/good/'], 'Game type filter failed.');
    gsc_export_check(gsc_browser_export_queue($db, $site, 'page', 10, $warn)['urls'] === [$site . '/about/'], 'Page type filter failed.');
    gsc_export_check($before === [hash_file('sha256', $db), hash_file('sha256', $gamesDb)], 'Export modified an input database.');
    $readonly = gsc_browser_export_open($db);
    gsc_export_check((int) $readonly->query('PRAGMA query_only')->fetchColumn() === 1, 'query_only must be enabled.');
    try {
        $readonly->exec("INSERT INTO app_settings VALUES('write','forbidden')");
        throw new LogicException('Read-only connection allowed a write.');
    } catch (PDOException $expectedFailure) { /* Both mode=ro and query_only prevent writes. */ }
    $readonly = null;

    [$status, $stdout, $stderr] = gsc_export_cli(['--db', $db, '--site=' . $site, '--limit=2']);
    gsc_export_check($status === 0 && json_decode($stdout, true)['urls'] === array_slice($expected, 0, 2), 'CLI should emit valid JSON only on stdout.');
    gsc_export_check(!str_contains($stdout . $stderr, 'NEVER_EXPORT_PRIVATE_CREDENTIALS'), 'Private Google configuration leaked.');
    $ledgerFile = $directory . '/ledger.json';
    gsc_export_check(gsc_browser_export_queue($db, $site, 'all', 1, $warn, $ledgerFile)['urls'] === [$expected[0]], 'A missing ledger must allow the first run.');
    gsc_export_check(!file_exists($ledgerFile), 'Reading a missing ledger must not create one.');
    foreach (['confirmed', 'uncertain', 'request_started', 'indexed', 'not_indexable'] as $status) {
        file_put_contents($ledgerFile, json_encode(['version' => 1, 'property' => $site . '/', 'records' => [$expected[0] => ['status' => $status]]], JSON_THROW_ON_ERROR));
        $ledgerBefore = hash_file('sha256', $ledgerFile);
        gsc_export_check(gsc_browser_export_queue($db, $site, 'all', 1, $warn, $ledgerFile)['urls'] === [$expected[1]], 'Recorded URLs must be filtered before the export limit, regardless of status.');
        gsc_export_check(hash_file('sha256', $ledgerFile) === $ledgerBefore, 'Exporter changed submission history.');
    }
    [$status, $stdout, $stderr] = gsc_export_cli(['--db', $db, '--site', $site, '--limit=1', '--ledger', $ledgerFile]);
    gsc_export_check($status === 0 && json_decode($stdout, true)['urls'] === [$expected[1]], 'CLI ledger filtering failed.');
    foreach ([
        '{broken', '[]', '{}',
        '{"version":1,"property":"https://example.com/","records":[]}',
        '{"version":1,"property":12,"records":{}}',
        '{"version":2,"property":"https://example.com/","records":{}}',
        '{"version":1,"property":"https://example.com/","records":{"https://example.com/":null}}',
        '{"version":1,"property":"https://example.com/","records":{"https://example.com/":{"status":1}}}',
    ] as $invalidLedger) {
        file_put_contents($ledgerFile, $invalidLedger);
        [$status, $stdout, $stderr] = gsc_export_cli(['--db', $db, '--site', $site, '--ledger', $ledgerFile]);
        gsc_export_check($status !== 0 && $stdout === '' && str_contains($stderr, 'ledger'), 'Malformed ledger must fail without emitting a queue.');
    }
    file_put_contents($ledgerFile, '{"version":1,"property":"https://example.com/","records":{}}');
    gsc_export_check(gsc_browser_export_queue($db, $site, 'all', 1, $warn, $ledgerFile)['urls'] === [$expected[0]], 'An empty records object must be valid.');
    foreach ([
        ['--db', $db], ['--db', 'relative.sqlite', '--site', $site],
        ['--db', $directory . '/missing.sqlite', '--site', $site],
        ['--db', $db, '--site', 'http://example.com'],
        ['--db', $db, '--site', 'https://localhost'],
        ['--db', $db, '--site', 'https://127.0.0.1'],
        ['--db', $db, '--site', 'https://example.local'],
        ['--db', $db, '--site', 'https://example.com/admin/'],
        ['--db', $db, '--site', 'https://user@example.com'],
        ['--db', $db, '--site', $site, '--limit', '0'],
        ['--db', $db, '--site', $site, '--limit', '10001'],
        ['--db', $db, '--site', $site, '--type', 'secret'],
        ['--db', $db, '--site', $site, '--unknown', 'value'],
        ['--db', $db, '--site', $site, '--ledger', 'relative-ledger.json'],
    ] as $arguments) {
        [$status, $stdout, $stderr] = gsc_export_cli($arguments);
        gsc_export_check($status !== 0 && $stdout === '' && $stderr !== '', 'Invalid CLI input did not fail closed: ' . json_encode($arguments));
    }
    gsc_export_check(!is_file($directory . '/missing.sqlite'), 'Missing --db path was created.');
    foreach (['https://user@example.com/about/', 'https://example.com/about/?', 'https://example.com/about/#', 'https://example.com/%00admin/', 'https://example.com:8443/about/'] as $url) {
        gsc_export_check(!gsc_browser_export_safe_url($url, $site), 'Unsafe URL accepted: ' . $url);
    }
    gsc_export_check(gsc_browser_export_site('https://EXAMPLE.COM:443/') === $site, 'HTTPS origin normalization failed.');

    $games->exec('INSERT INTO game_module_settings VALUES(1,0)');
    gsc_export_check(gsc_browser_export_queue($db, $site, 'game', 10, $warn)['urls'] === [], 'Disabled game module must export no games.');
    $games = null;
    rename($gamesDb, $directory . '/unavailable-games.sqlite');
    $warnings = [];
    gsc_export_check(gsc_browser_export_queue($db, $site, 'game', 10, $warn)['urls'] === [] && count($warnings) === 1, 'Missing catalogue must skip games with an actionable diagnostic.');

    $insert->execute(['https://EXAMPLE.COM:443/blog/never/', 'blog', null]);
    $insert->execute(['https://EXAMPLE.COM:443', 'page', null]);
    $insert->execute([$site, 'page', null]);
    $canonicalQueue = gsc_browser_export_queue($db, $site, 'all', 1000, $warn)['urls'];
    gsc_export_check(count(array_keys($canonicalQueue, $site . '/blog/never/', true)) === 1, 'Host/default-port variants must deduplicate before export.');
    gsc_export_check(count(array_keys($canonicalQueue, $site . '/', true)) === 1, 'Empty root paths must export one canonical root URL.');
    gsc_export_check(!in_array('https://EXAMPLE.COM:443', $canonicalQueue, true), 'Noncanonical host/port text must not leak into the queue.');
    file_put_contents($ledgerFile, json_encode(['version' => 1, 'property' => $site . '/', 'records' => [
        $site . '/blog/never/' => ['status' => 'confirmed'], $site . '/' => ['status' => 'confirmed'],
    ]], JSON_THROW_ON_ERROR));
    gsc_export_check(gsc_browser_export_queue($db, $site, 'all', 1, $warn, $ledgerFile)['urls'] === [$expected[1]], 'Normalized ledger keys must skip host/default-port variants before the limit.');
    gsc_export_check(gsc_browser_export_queue($db, $site, 'page', 1, $warn, $ledgerFile)['urls'] === [$site . '/about/'], 'Canonical root ledger entry must not starve later pages.');

    $pdo->exec("UPDATE index_checks SET in_sitemap=0 WHERE url LIKE 'https://example.com/%'");
    $pdo->exec("UPDATE index_checks SET in_sitemap=0 WHERE url IN ('https://EXAMPLE.COM:443','https://example.com') OR url LIKE 'https://EXAMPLE.COM:443/%'");
    try {
        gsc_browser_export_queue($db, $site, 'all', 1000, $warn);
        throw new LogicException('Wrong-origin-only queue was silently exported empty.');
    } catch (RuntimeException $error) {
        gsc_export_check(!$error instanceof LogicException && str_contains($error->getMessage(), 'localhost URLs are never rewritten'), 'Wrong-origin-only queue needs an actionable error.');
    }
    $pdo->exec('UPDATE index_checks SET in_sitemap=0');
    gsc_export_check(gsc_browser_export_queue($db, $site, 'all', 1000, $warn)['urls'] === [], 'A genuinely empty queue should be exported successfully.');
    $pdo = null;
    fwrite(STDOUT, "GSC browser queue export checks passed.\n");
} finally {
    // Only these test-owned files live in this random private temporary directory.
    $pdo = null; $games = null; $readonly = null;
    foreach (scandir($directory) as $filename) {
        if ($filename !== '.' && $filename !== '..') { unlink($directory . '/' . $filename); }
    }
    rmdir($directory);
}
