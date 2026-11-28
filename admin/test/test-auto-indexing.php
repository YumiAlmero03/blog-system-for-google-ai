<?php
/**
 * Tests automatic unindexed URL queuing, priority ordering (blogs -> pages -> games),
 * resubmit intervals, quota/batch/cooldown enforcement, manual deduplication,
 * Admin Index Checker UI automation panel, and read-only GSC browser ledger status.
 *
 * Run from www: php admin/test/test-auto-indexing.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dir = sys_get_temp_dir() . '/auto-indexing-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$stateDir = sys_get_temp_dir() . '/gsc-state-' . bin2hex(random_bytes(6));
mkdir($stateDir, 0700);

putenv('APP_STORAGE_DIR=' . $dir);
putenv('GOOGLE_PRIVATE_DIR=' . $dir . '/private');
putenv('SITE_BASE_URL=https://example.test');
putenv('ADMIN_USERNAME=super_user');
putenv('ADMIN_PASSWORD_HASH=' . password_hash('Visibility test password 123!', PASSWORD_DEFAULT));
putenv('ADMIN_JWT_SECRET=' . str_repeat('auto-index-secret-', 4));
putenv('GOOGLE_INDEXING_ENABLED');
putenv('GOOGLE_INDEXING_AUTO_SUBMIT');
putenv('GOOGLE_INDEXING_DAILY_LIMIT');
putenv('GOOGLE_INDEXING_BATCH');
putenv('GOOGLE_INDEXING_RESUBMIT_DAYS');
putenv('GSC_BROWSER_STATE_DIR');
ini_set('session.save_path', $dir);

require_once __DIR__ . '/../includes/index-checker.php';
require_once __DIR__ . '/../includes/indexing-queue.php';
require_once __DIR__ . '/../includes/indexnow.php';
require_once __DIR__ . '/../includes/auth.php';

function ai_check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

function ai_remove(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $file = $path . '/' . $f;
        is_dir($file) ? ai_remove($file) : @unlink($file);
    }
    @rmdir($path);
}

function ai_handler(array $request): array
{
    $p = proc_open(
        [PHP_BINARY, __DIR__ . '/test-game-visibility.php', '--handler'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    fwrite($pipes[0], json_encode($request));
    fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    ai_check(proc_close($p) === 0 && preg_match('/^STATUS:(\d+)$/m', $error, $m) === 1, 'Handler error: ' . $error);
    return [(int) $m[1], $body];
}

try {
    $pdo = index_checker_pdo();
    indexing_schema($pdo);

    // Create test credentials in SQLite
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $pem);
    $credJson = json_encode([
        'type' => 'service_account',
        'project_id' => 'auto-idx-proj',
        'private_key' => $pem,
        'client_email' => 'bot@auto-idx-proj.iam.gserviceaccount.com',
        'token_uri' => 'https://oauth2.googleapis.com/token',
    ]);

    // 1. Toggle & preference checks
    google_save_configuration('https://example.test/', $credJson, false, false, null);
    ai_check(!google_indexing_enabled(), 'Indexing API disabled initially');
    ai_check(!google_indexing_auto_submit_enabled(), 'Auto-submit inactive while Indexing API is disabled');
    ai_check(google_indexing_auto_submit_preference(), 'Auto-submit preference defaults to true');

    google_save_configuration('https://example.test/', null, false, true, null);
    ai_check(google_indexing_enabled(), 'Indexing API enabled');
    ai_check(google_indexing_auto_submit_enabled(), 'Auto-submit active by default once Indexing API enabled');

    google_save_configuration('https://example.test/', null, false, true, false);
    ai_check(!google_indexing_auto_submit_enabled(), 'Auto-submit can be explicitly disabled in SQLite');
    ai_check(!google_indexing_auto_submit_preference(), 'Auto-submit preference persisted false');

    google_save_configuration('https://example.test/', null, false, true, true);
    ai_check(google_indexing_auto_submit_enabled(), 'Auto-submit re-enabled in SQLite');

    // Seed public & ineligible content
    $pdo->exec("INSERT INTO games(api_id,name,slug,provider,provider_slug,published,done_processing,restrictions,is_viewable) VALUES
        (1,'Good Game','good-game','Good','good',1,1,'[]',1),
        (2,'Good Game 2','good-game-2','Good','good',1,1,'[]',1),
        (3,'Hidden Game','hidden-game','Good','good',1,1,'[]',0),
        (4,'Restricted Game','restricted-game','Good','good',1,1,'[\"PH\"]',1)");
    foreach (['good-blog' => 'published', 'stale-blog' => 'published', 'draft-blog' => 'draft'] as $slug => $status) {
        $post = blog_normalize_existing([
            'slug' => $slug,
            'title' => $slug,
            'category' => 'Guides',
            'content' => 'Body',
            'excerpt' => 'Excerpt',
            'status' => $status,
        ]);
        blogs_upsert($post);
    }
    // Clear queues created by blogs_upsert so we test auto-backfill from index_checks cleanly
    $pdo->exec('DELETE FROM google_indexing_queue');

    $now = time();
    $site = 'https://example.test';
    $insert = $pdo->prepare('INSERT INTO index_checks(url,sitemap_url,content_type,in_sitemap,check_status,robots_state,indexing_state,created_at,updated_at,google_submitted_at,google_submit_status) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $rows = [
        // Eligible never-submitted (should be queued in order: blog -> page -> game)
        [$site . '/game/good-game/', $site . '/sitemap-games-1.xml', 'game', 1, 'Not Indexed', 'ALLOWED', 'INDEXING_ALLOWED', $now - 100, $now - 100, null, ''],
        [$site . '/about/', $site . '/sitemap-pages.xml', 'page', 1, 'Pending', 'ALLOWED', 'INDEXING_ALLOWED', $now - 90, $now - 90, null, ''],
        [$site . '/blog/good-blog/', $site . '/sitemap-blog.php', 'blog', 1, 'Not Indexed', 'ALLOWED', 'INDEXING_ALLOWED', $now - 80, $now - 80, null, ''],

        // Eligible Not Indexed submitted 8 days ago (past 7-day resubmit window -> SHOULD queue)
        [$site . '/blog/stale-blog/', $site . '/sitemap-blog.php', 'blog', 1, 'Not Indexed', 'ALLOWED', 'INDEXING_ALLOWED', $now - 900000, $now - 900000, $now - (8 * 86400), 'Success'],

        // Recently submitted Not Indexed (2 days ago -> must NOT queue)
        [$site . '/game/good-game-2/', $site . '/sitemap-games-1.xml', 'game', 1, 'Not Indexed', 'ALLOWED', 'INDEXING_ALLOWED', $now - 200000, $now - 200000, $now - (2 * 86400), 'Success'],

        // Eligible previously failed > 24h ago (SHOULD queue)
        [$site . '/faq/', $site . '/sitemap-pages.xml', 'page', 1, 'Pending', 'ALLOWED', 'INDEXING_ALLOWED', $now - 100000, $now - 90000, $now - 90000, 'HTTP 500'],

        // Recently failed < 24h ago (must NOT queue yet)
        [$site . '/terms/', $site . '/sitemap-pages.xml', 'page', 1, 'Pending', 'ALLOWED', 'INDEXING_ALLOWED', $now - 100000, $now - 1800, $now - 1800, 'HTTP 500'],

        // Must NEVER be queued:
        [$site . '/already-indexed/', $site . '/sitemap-pages.xml', 'page', 1, 'Indexed', 'ALLOWED', 'INDEXING_ALLOWED', $now - 100, $now - 100, null, ''],
        [$site . '/removed-page/', $site . '/sitemap-pages.xml', 'page', 0, 'Not Indexed', 'ALLOWED', 'INDEXING_ALLOWED', $now - 100, $now - 100, null, ''],
        [$site . '/robots-blocked/', $site . '/sitemap-pages.xml', 'page', 1, 'Not Indexed', 'DISALLOWED', 'INDEXING_ALLOWED', $now - 100, $now - 100, null, ''],
        [$site . '/meta-noindex/', $site . '/sitemap-pages.xml', 'page', 1, 'Not Indexed', 'ALLOWED', 'BLOCKED_BY_META_TAG', $now - 100, $now - 100, null, ''],
        [$site . '/header-noindex/', $site . '/sitemap-pages.xml', 'page', 1, 'Not Indexed', 'ALLOWED', 'BLOCKED_BY_HTTP_HEADER', $now - 100, $now - 100, null, ''],
        [$site . '/blog/draft-blog/', $site . '/sitemap-blog.php', 'blog', 1, 'Not Indexed', 'ALLOWED', 'INDEXING_ALLOWED', $now - 100, $now - 100, null, ''],
        [$site . '/game/hidden-game/', $site . '/sitemap-games-1.xml', 'game', 1, 'Not Indexed', 'ALLOWED', 'INDEXING_ALLOWED', $now - 100, $now - 100, null, ''],
        [$site . '/game/restricted-game/', $site . '/sitemap-games-1.xml', 'game', 1, 'Not Indexed', 'ALLOWED', 'INDEXING_ALLOWED', $now - 100, $now - 100, null, ''],
        [$site . '/admin/secret/', $site . '/sitemap-pages.xml', 'page', 1, 'Not Indexed', 'ALLOWED', 'INDEXING_ALLOWED', $now - 100, $now - 100, null, ''],
        ['https://other.test/page/', $site . '/sitemap-pages.xml', 'page', 1, 'Not Indexed', 'ALLOWED', 'INDEXING_ALLOWED', $now - 100, $now - 100, null, ''],
    ];
    foreach ($rows as $r) {
        $insert->execute($r);
    }

    // 2. Run auto-queue
    $queuedCount = indexing_google_queue_unindexed($pdo, null, $now);
    ai_check($queuedCount === 5, 'Expected exactly 5 eligible URLs queued, got ' . $queuedCount);

    $queuedUrls = $pdo->query('SELECT url FROM google_indexing_queue ORDER BY url')->fetchAll(PDO::FETCH_COLUMN);
    $expectedQueued = [
        $site . '/about/',
        $site . '/blog/good-blog/',
        $site . '/blog/stale-blog/',
        $site . '/faq/',
        $site . '/game/good-game/',
    ];
    sort($expectedQueued);
    ai_check($queuedUrls === $expectedQueued, 'Queued URL set mismatch: ' . json_encode($queuedUrls));

    // Re-running immediately must be idempotent (0 duplicates added)
    ai_check(indexing_google_queue_unindexed($pdo, null, $now) === 0, 'Auto-queue must be idempotent');

    // 3. Test batch limit & priority order (blogs -> pages -> games)
    $publishedOrder = [];
    $publishStatus = 200;
    $http = static function (string $url, ?string $body = null) use (&$publishedOrder, &$publishStatus): array {
        if ($url === 'https://oauth2.googleapis.com/token') {
            return ['status' => 200, 'headers' => [], 'body' => '{"access_token":"tok-auto-test","expires_in":3600}'];
        }
        if (str_contains($url, 'urlNotifications:publish')) {
            $payload = json_decode((string) $body, true);
            $publishedOrder[] = $payload['url'];
            return ['status' => $publishStatus, 'headers' => [], 'body' => '{"urlNotificationMetadata":{}}'];
        }
        return ['status' => 200, 'headers' => [], 'body' => '{}'];
    };

    putenv('GOOGLE_INDEXING_BATCH=3');
    $batch1 = indexing_google_process_queue($pdo, $http);
    ai_check($batch1 === 3, 'GOOGLE_INDEXING_BATCH=3 must publish 3 URLs, got ' . $batch1);
    // First two must be the 2 blogs, third must be one of the pages (/about/ or /faq/)
    ai_check(str_starts_with($publishedOrder[0], $site . '/blog/'), 'First published URL must be a blog: ' . $publishedOrder[0]);
    ai_check(str_starts_with($publishedOrder[1], $site . '/blog/'), 'Second published URL must be a blog: ' . $publishedOrder[1]);
    ai_check(in_array($publishedOrder[2], [$site . '/about/', $site . '/faq/'], true), 'Third published URL must be a page: ' . $publishedOrder[2]);

    // Verify index_checks updated identically to manual submit
    $stmtCheck = $pdo->prepare('SELECT google_submitted_at, google_submit_status FROM index_checks WHERE url=?');
    $stmtCheck->execute([$site . '/blog/good-blog/']);
    $rowCheck = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    $stmtCheck->closeCursor();
    $stmtCheck = null;
    ai_check(!empty($rowCheck['google_submitted_at']) && $rowCheck['google_submit_status'] === 'Success', 'Cron publish records timestamp and Success status in index_checks');

    // 4. Test manual publish deduplicates against google_indexing_queue
    $gameId = (int) $pdo->query("SELECT id FROM index_checks WHERE url='{$site}/game/good-game/'")->fetchColumn();
    $manualRes = indexing_google_publish_manual($pdo, [$gameId], $http);
    ai_check($manualRes['submitted'] === 1, 'Manual submit succeeded');
    $stillInQueue = (int) $pdo->query("SELECT COUNT(*) FROM google_indexing_queue WHERE url='{$site}/game/good-game/'")->fetchColumn();
    ai_check($stillInQueue === 0, 'Manual submit must remove URL from google_indexing_queue to avoid double submission');

    // 5. Test remaining 1 URL gets published via indexing_google_run_auto_batch
    $autoBatchRes = indexing_google_run_auto_batch($pdo, $http);
    ai_check($autoBatchRes['published'] === 1, 'Remaining queued URL published by run_auto_batch');
    ai_check((int) $pdo->query('SELECT COUNT(*) FROM google_indexing_queue')->fetchColumn() === 0, 'Queue empty after publishing all eligible URLs');

    // 6. Test HTTP 429 updates index_checks status and activates 24h cooldown
    $pdo->exec("INSERT INTO google_indexing_queue(url,kind,removed,version,attempts,next_at) VALUES('{$site}/game/good-game-2/','game',0,'v429',0,0)");
    $publishStatus = 429;
    $rateLimited = indexing_google_process_queue($pdo, $http);
    ai_check($rateLimited === 0, 'HTTP 429 must publish 0');
    $status429 = $pdo->query("SELECT google_submit_status FROM index_checks WHERE url='{$site}/game/good-game-2/'")->fetchColumn();
    ai_check($status429 === 'HTTP 429', 'HTTP 429 status recorded on index_checks');
    $cooldown = (int) $pdo->query("SELECT next_at FROM indexing_jobs WHERE name='google-indexing-cooldown'")->fetchColumn();
    ai_check($cooldown > time() + 80000, '24h cooldown set after HTTP 429');
    $pdo->exec("DELETE FROM indexing_jobs WHERE name='google-indexing-cooldown'; DELETE FROM google_indexing_queue");

    // 7. Test read-only GSC browser ledger status (outside webroot vs inside webroot)
    $prop = 'https://example.test/';
    $keyHash = substr(hash('sha256', $prop), 0, 24);
    $ledgerPath = $stateDir . '/ledger-' . $keyHash . '.json';
    $ledgerPayload = json_encode([
        'version' => 1,
        'property' => $prop,
        'records' => [
            'https://example.test/blog/good-blog/' => ['status' => 'confirmed', 'at' => time() * 1000],
            'https://example.test/about/' => ['status' => 'indexed', 'at' => time() * 1000],
        ],
        'attempts' => [time() * 1000],
        'paused' => ['reason' => 'quota', 'until' => (time() + 3600) * 1000],
    ], JSON_THROW_ON_ERROR);
    file_put_contents($ledgerPath, $ledgerPayload);
    $ledgerBefore = hash_file('sha256', $ledgerPath);

    // Must reject state dir inside webroot
    putenv('GSC_BROWSER_STATE_DIR=' . dirname(__DIR__));
    $badBrowserStatus = indexing_gsc_browser_status($prop);
    ai_check($badBrowserStatus['configured'] === false && $badBrowserStatus['ledger_found'] === false, 'State dir inside webroot must be rejected');

    // Valid private directory outside webroot
    putenv('GSC_BROWSER_STATE_DIR=' . $stateDir);
    $goodBrowserStatus = indexing_gsc_browser_status($prop);
    ai_check($goodBrowserStatus['installed'] === true && $goodBrowserStatus['configured'] === true && $goodBrowserStatus['ledger_found'] === true, 'Valid private ledger read cleanly');
    ai_check(($goodBrowserStatus['counts']['confirmed'] ?? 0) === 1 && ($goodBrowserStatus['counts']['indexed'] ?? 0) === 1, 'Ledger counts parsed accurately');
    ai_check($goodBrowserStatus['attempts_24h'] === 1 && ($goodBrowserStatus['paused']['reason'] ?? '') === 'quota', 'Ledger attempts & pause parsed accurately');
    ai_check(hash_file('sha256', $ledgerPath) === $ledgerBefore, 'Reading GSC browser status must not modify ledger');

    // 8. Admin UI & authorization checks
    $_SESSION['user'] = auth_authenticate_credentials('super_user', 'Visibility test password 123!');
    foreach (['admin', 'editor'] as $role) {
        admin_user_save(['username' => $role, 'display_name' => $role, 'password' => 'Visibility test password 123!', 'active' => 1, 'role' => $role]);
    }
    $insert = null;
    [$status, $body] = ai_handler(['path' => '/admin/index-checker/index.php', 'role' => 'admin', 'method' => 'GET']);
    ai_check($status === 200 && str_contains($body, 'Automated Index Submission (Cron)'), 'Index Checker displays Automated Index Submission panel');
    ai_check(str_contains($body, 'Run Auto-Submit Batch Now'), 'Index Checker displays Run Auto-Submit Batch Now button');
    ai_check(str_contains($body, 'Optional GSC Browser Worker'), 'Index Checker displays optional GSC Browser Worker section');
    ai_check(!str_contains($body, $stateDir) && !str_contains($body, 'PRIVATE KEY'), 'Admin HTML must not leak private paths or keys');

    [$status] = ai_handler(['path' => '/admin/index-checker/index.php', 'role' => 'editor', 'method' => 'POST', 'post' => ['action' => 'run_auto_submit']]);
    ai_check($status === 403, 'Editor denied run_auto_submit');
    [$status] = ai_handler(['path' => '/admin/index-checker/index.php', 'role' => 'admin', 'bad_csrf' => true, 'method' => 'POST', 'post' => ['action' => 'run_auto_submit']]);
    ai_check($status === 403, 'CSRF required for run_auto_submit');

    echo "PASS: auto-submit toggle, unindexed backfill & eligibility filtering, blog->page->game priority, batch/quota/cooldown, manual deduplication, read-only GSC browser ledger status, and admin UI permissions.\n";
} finally {
    session_write_close();
    ai_remove($dir);
    ai_remove($stateDir);
}
