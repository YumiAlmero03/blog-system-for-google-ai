<?php
declare(strict_types=1);
require_once __DIR__ . '/blog-storage.php';

function indexing_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS indexing_public_urls (url TEXT PRIMARY KEY, kind TEXT NOT NULL, fingerprint TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS indexing_notifications (url TEXT PRIMARY KEY, kind TEXT NOT NULL, removed INTEGER NOT NULL, version TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, next_at INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS indexing_google_events (url TEXT PRIMARY KEY, kind TEXT NOT NULL, removed INTEGER NOT NULL, version TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS indexing_jobs (name TEXT PRIMARY KEY, version TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, next_at INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec('CREATE INDEX IF NOT EXISTS indexing_notifications_due ON indexing_notifications(next_at)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS google_indexing_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, url TEXT NOT NULL, type TEXT NOT NULL, status_code INTEGER NOT NULL, attempted_at INTEGER NOT NULL)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS google_indexing_attempts_time ON google_indexing_attempts(attempted_at)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS google_indexing_queue (url TEXT PRIMARY KEY, kind TEXT NOT NULL, removed INTEGER NOT NULL, version TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, next_at INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS google_indexing_queue_due ON google_indexing_queue(next_at)");
}

function indexing_reconcile(PDO $pdo, string $kind): int
{
    if ($kind === 'game' && !function_exists('games_storage_attach')) return 0;
    indexing_schema($pdo);
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if ($kind === 'game') {
            $rows = $pdo->query('SELECT slug,name,short_description,long_description,updated_at FROM games WHERE '.game_public_eligibility_sql().' ORDER BY id');
        } elseif ($kind === 'blog') {
            $stmt=$pdo->prepare('SELECT slug,title,content,excerpt,featured_image,updated_at FROM blog_posts WHERE '.blog_public_visibility_sql());
            $stmt->execute([':visibility_now'=>time()]); $rows=$stmt;
        } else throw new InvalidArgumentException('Unknown indexing content type.');
        $current=[];
        foreach ($rows as $row) {
            if ($row['slug']==='' || normalize_slug($row['slug'])!==$row['slug']) continue;
            $current[public_url('/'.$kind.'/'.$row['slug'].'/')]=hash('sha256',json_encode($row));
        }
        $stmt=$pdo->prepare('SELECT url,fingerprint FROM indexing_public_urls WHERE kind=?'); $stmt->execute([$kind]); $old=$stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $events=[];
        foreach ($current as $url=>$fingerprint) if (($old[$url] ?? null)!==$fingerprint) $events[$url]=0;
        foreach ($old as $url=>$fingerprint) if (!isset($current[$url])) $events[$url]=1;
        $save=$pdo->prepare('INSERT INTO indexing_public_urls(url,kind,fingerprint) VALUES(?,?,?) ON CONFLICT(url) DO UPDATE SET fingerprint=excluded.fingerprint');
        $delete=$pdo->prepare('DELETE FROM indexing_public_urls WHERE url=?');
        $queue=$pdo->prepare('INSERT INTO indexing_notifications(url,kind,removed,version) VALUES(?,?,?,?) ON CONFLICT(url) DO UPDATE SET removed=excluded.removed,version=excluded.version');
        $google=$pdo->prepare('INSERT INTO indexing_google_events(url,kind,removed,version) VALUES(?,?,?,?) ON CONFLICT(url) DO UPDATE SET removed=excluded.removed,version=excluded.version');
        $googleQueue=$pdo->prepare('INSERT INTO google_indexing_queue(url,kind,removed,version) VALUES(?,?,?,?) ON CONFLICT(url) DO UPDATE SET removed=excluded.removed,version=excluded.version');
        foreach ($events as $url=>$removed) {
            $version=bin2hex(random_bytes(12));
            $queue->execute([$url,$kind,$removed,$version]); $google->execute([$url,$kind,$removed,$version]);
            $googleQueue->execute([$url,$kind,$removed,$version]);
            if ($removed) $delete->execute([$url]); else $save->execute([$url,$kind,$current[$url]]);
        }
        if ($events) {
            $pdo->prepare("INSERT INTO indexing_jobs(name,version) VALUES('google-sitemap',?) ON CONFLICT(name) DO UPDATE SET version=excluded.version")->execute([bin2hex(random_bytes(12))]);
            @error_log(gmdate('c').' Indexing queue: '.count($events).' '.$kind." URL change(s).\n",3,blog_storage_dir().'/indexing.log');
        }
        if ($own) $pdo->commit();
        return count($events);
    } catch (Throwable $error) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

// Publishing must never depend on remote indexing or queue availability; cron reconciles again.
function indexing_content_changed(PDO $pdo, string $kind): void
{
    try { indexing_reconcile($pdo,$kind); }
    catch (Throwable $error) { error_log('Indexing queue update failed; cron will reconcile public content.'); }
}

function indexing_google_events(PDO $pdo): void
{
    foreach ($pdo->query('SELECT * FROM indexing_google_events')->fetchAll(PDO::FETCH_ASSOC) as $event) {
        if ($event['removed']) {
            $pdo->prepare('UPDATE index_checks SET in_sitemap=0,queued=0 WHERE url=?')->execute([$event['url']]);
        } else {
            $pdo->prepare('INSERT INTO index_checks(url,sitemap_url,content_type,queued,created_at,updated_at) VALUES(?,?,?,1,?,?) ON CONFLICT(url) DO UPDATE SET queued=1,in_sitemap=1,next_check_at=0,updated_at=excluded.updated_at')
                ->execute([$event['url'],public_url($event['kind']==='blog'?'/sitemap-blog.php':'/sitemap-index.xml'),$event['kind'],time(),time()]);
        }
        $pdo->prepare('DELETE FROM indexing_google_events WHERE url=? AND version=?')->execute([$event['url'],$event['version']]);
    }
}

function indexing_google_sitemap_job(PDO $pdo): void
{
    require_once __DIR__ . '/index-checker-client.php';
    if (!function_exists('games_storage_attach')) return;
    require_once __DIR__ . '/../scripts/generate-game-sitemaps.php';
    $state=google_state();
    if (!empty($state['submission_pending'])) $pdo->prepare("INSERT OR IGNORE INTO indexing_jobs(name,version) VALUES('google-sitemap',?)")->execute([bin2hex(random_bytes(12))]);
    $job=$pdo->query("SELECT * FROM indexing_jobs WHERE name='google-sitemap' AND next_at<=".time())->fetch(PDO::FETCH_ASSOC);
    if (!$job) return;
    try {
        generate_game_sitemaps(games_db_path(),env_value('GAME_SITEMAP_OUTPUT_DIR') ?: site_root_path());
        $state=google_state(); $original=$state; $state['submission_pending']=true; google_write_state($state,$original);
        google_submit_pending_sitemap();
        $pdo->prepare('DELETE FROM indexing_jobs WHERE name=? AND version=?')->execute([$job['name'],$job['version']]);
    } catch (Throwable $error) {
        $delay=min(86400,3600*(2**min(5,(int)$job['attempts'])));
        $pdo->prepare('UPDATE indexing_jobs SET attempts=attempts+1,next_at=? WHERE name=? AND version=?')->execute([time()+$delay,$job['name'],$job['version']]);
        throw new RuntimeException('Google sitemap submission deferred.');
    }
}

function google_indexing_daily_limit(): int
{
    $raw = env_value('GOOGLE_INDEXING_DAILY_LIMIT');
    if ($raw === null || $raw === '' || !ctype_digit($raw)) {
        return 200;
    }
    return max(1, min(2000, (int) $raw));
}

function google_indexing_batch_limit(): int
{
    $raw = env_value('GOOGLE_INDEXING_BATCH');
    if ($raw === null || $raw === '' || !ctype_digit($raw)) {
        return 50;
    }
    return max(1, min(200, (int) $raw));
}

function google_indexing_resubmit_seconds(): int
{
    $raw = env_value('GOOGLE_INDEXING_RESUBMIT_DAYS');
    if ($raw === null || $raw === '' || !ctype_digit($raw)) {
        return 7 * 86400;
    }
    return max(1, min(90, (int) $raw)) * 86400;
}

function google_indexing_quota_remaining(PDO $pdo, ?int $dailyLimit = null): int
{
    indexing_schema($pdo);
    $dailyLimit ??= google_indexing_daily_limit();
    $now = time();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM google_indexing_attempts WHERE attempted_at > ?');
    $stmt->execute([$now - 86400]);
    $used = (int)$stmt->fetchColumn();
    return max(0, $dailyLimit - $used);
}

/**
 * Automatically queues eligible unindexed / unsubmitted sitemap URLs from index_checks
 * into google_indexing_queue (matching what manual submission targets), respecting
 * robots/noindex blocks, live content eligibility, resubmit intervals, and daily quota.
 */
function indexing_google_queue_unindexed(PDO $pdo, ?int $limit = null, ?int $now = null): int
{
    indexing_schema($pdo);
    require_once __DIR__ . '/google-credentials.php';
    require_once __DIR__ . '/index-checker.php';
    if (!google_safe_status()['configured'] || !google_indexing_enabled() || !google_indexing_auto_submit_enabled()) {
        return 0;
    }
    $now ??= time();
    if ($pdo->query("SELECT 1 FROM indexing_jobs WHERE name='google-indexing-cooldown' AND next_at>" . $now)->fetchColumn()) {
        return 0;
    }
    $hasTable = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='index_checks'")->fetchColumn();
    if (!$hasTable) {
        return 0;
    }

    $dailyLimit = google_indexing_daily_limit();
    $quota = google_indexing_quota_remaining($pdo, $dailyLimit);
    if ($quota <= 0) {
        return 0;
    }
    if ($limit === null) {
        $stmtDue = $pdo->prepare('SELECT COUNT(*) FROM google_indexing_queue WHERE next_at <= ?');
        $stmtDue->execute([$now]);
        $alreadyQueued = (int) $stmtDue->fetchColumn();
        $needed = max(0, $quota - $alreadyQueued);
    } else {
        $needed = max(0, min($limit, $quota));
    }
    if ($needed <= 0) {
        return 0;
    }

    $config = index_checker_config();
    $sets = index_checker_eligible_sets($pdo);
    $retryCutoff = $now - 86400;
    $resubmitCutoff = $now - google_indexing_resubmit_seconds();

    $stmt = $pdo->prepare("
        SELECT id, url, content_type, check_status, google_submitted_at, google_submit_status, updated_at, created_at
        FROM index_checks
        WHERE in_sitemap = 1
          AND check_status != 'Indexed'
          AND COALESCE(robots_state, '') != 'DISALLOWED'
          AND COALESCE(indexing_state, '') NOT IN ('BLOCKED_BY_META_TAG', 'BLOCKED_BY_HTTP_HEADER')
          AND NOT EXISTS (SELECT 1 FROM google_indexing_queue q WHERE q.url = index_checks.url)
          AND (
              google_submitted_at IS NULL
              OR (google_submit_status != '' AND google_submit_status != 'Success' AND updated_at <= :retryCutoff)
              OR (check_status = 'Not Indexed' AND google_submitted_at <= :resubmitCutoff)
          )
        ORDER BY
          CASE
              WHEN google_submitted_at IS NULL THEN 0
              WHEN check_status = 'Not Indexed' THEN 1
              ELSE 2
          END ASC,
          CASE content_type
              WHEN 'blog' THEN 0
              WHEN 'page' THEN 1
              WHEN 'game' THEN 2
              ELSE 3
          END ASC,
          CASE
              WHEN check_status = 'Not Indexed' THEN 0
              WHEN check_status = 'Pending' THEN 1
              ELSE 2
          END ASC,
          COALESCE(google_submitted_at, 0) ASC,
          created_at ASC,
          id ASC
    ");
    $stmt->execute([
        ':retryCutoff' => $retryCutoff,
        ':resubmitCutoff' => $resubmitCutoff,
    ]);

    $insert = $pdo->prepare(
        'INSERT INTO google_indexing_queue(url, kind, removed, version, attempts, next_at)
         VALUES(?, ?, 0, ?, 0, 0)
         ON CONFLICT(url) DO NOTHING'
    );
    $queued = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $url = (string) ($row['url'] ?? '');
        if ($url === '' || !index_checker_eligible($url, $config, $sets)) {
            continue;
        }
        $kind = (string) ($row['content_type'] ?: index_checker_type($url));
        $insert->execute([$url, $kind, bin2hex(random_bytes(12))]);
        if ($insert->rowCount() > 0) {
            $queued++;
            if ($queued >= $needed) {
                break;
            }
        }
    }

    if ($queued > 0) {
        @error_log(gmdate('c') . ' Google Indexing auto-queue: ' . $queued . " unindexed/unsubmitted URL(s).\n", 3, blog_storage_dir() . '/indexing.log');
    }

    return $queued;
}

function indexing_google_process_queue(PDO $pdo, ?callable $http = null, ?int $limit = null): int
{
    indexing_schema($pdo);
    require_once __DIR__ . '/google-credentials.php';
    if (!google_safe_status()['configured'] || !google_indexing_enabled()) {
        return 0;
    }
    if ($pdo->query("SELECT 1 FROM indexing_jobs WHERE name='google-indexing-cooldown' AND next_at>" . time())->fetchColumn()) {
        return 0;
    }
    $lock = fopen(blog_storage_dir() . '/google-indexing-worker.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) fclose($lock);
        return 0;
    }
    try {
        $dailyLimit = google_indexing_daily_limit();
        $now = time();
        $quota = google_indexing_quota_remaining($pdo, $dailyLimit);
        if ($quota <= 0) {
            return 0;
        }
        $batchSize = $limit !== null ? min($quota, max(1, $limit)) : min($quota, google_indexing_batch_limit());
        $stmt = $pdo->prepare("
            SELECT * FROM google_indexing_queue
            WHERE next_at <= ?
            ORDER BY
              CASE kind WHEN 'blog' THEN 0 WHEN 'page' THEN 1 WHEN 'game' THEN 2 ELSE 3 END ASC,
              next_at ASC,
              url ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, $now, PDO::PARAM_INT);
        $stmt->bindValue(2, $batchSize, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return 0;
        }

        $processed = 0;
        foreach ($rows as $row) {
            $type = $row['removed'] ? 'URL_DELETED' : 'URL_UPDATED';
            try {
                $res = google_publish_url($row['url'], $type, $http);
                $statusCode = (int)$res['status'];
                $attemptTime = time();
                $pdo->prepare('INSERT INTO google_indexing_attempts(url, type, status_code, attempted_at) VALUES(?,?,?,?)')
                    ->execute([$row['url'], $type, $statusCode, $attemptTime]);

                if ($res['success']) {
                    $pdo->prepare('DELETE FROM google_indexing_queue WHERE url=? AND version=?')
                        ->execute([$row['url'], $row['version']]);
                    try {
                        $pdo->prepare('UPDATE index_checks SET google_submitted_at=?, google_submit_status="Success", updated_at=? WHERE url=?')
                            ->execute([$attemptTime, $attemptTime, $row['url']]);
                    } catch (Throwable) {}
                    $processed++;
                } elseif ($statusCode === 429 || $statusCode === 403) {
                    $delay = 86400;
                    try {
                        $pdo->prepare('UPDATE index_checks SET google_submit_status=?, updated_at=? WHERE url=?')
                            ->execute(['HTTP ' . $statusCode, $attemptTime, $row['url']]);
                    } catch (Throwable) {}
                    $pdo->prepare("INSERT INTO indexing_jobs(name, version, next_at) VALUES('google-indexing-cooldown', ?, ?) ON CONFLICT(name) DO UPDATE SET next_at = excluded.next_at")
                        ->execute([bin2hex(random_bytes(12)), $attemptTime + $delay]);
                    $pdo->prepare('UPDATE google_indexing_queue SET attempts=attempts+1, next_at=? WHERE url=? AND version=?')
                        ->execute([$attemptTime + $delay, $row['url'], $row['version']]);
                    break;
                } else {
                    try {
                        $pdo->prepare('UPDATE index_checks SET google_submit_status=?, updated_at=? WHERE url=?')
                            ->execute(['HTTP ' . $statusCode, $attemptTime, $row['url']]);
                    } catch (Throwable) {}
                    $attempts = (int)$row['attempts'] + 1;
                    $delay = min(86400, 600 * (2 ** min(6, $attempts - 1)));
                    $pdo->prepare('UPDATE google_indexing_queue SET attempts=?, next_at=? WHERE url=? AND version=?')
                        ->execute([$attempts, $attemptTime + $delay, $row['url'], $row['version']]);
                }
            } catch (Throwable $e) {
                error_log('Google Indexing API publish failed: ' . $e->getMessage());
                $attempts = (int)$row['attempts'] + 1;
                $delay = min(86400, 600 * (2 ** min(6, $attempts - 1)));
                $pdo->prepare('UPDATE google_indexing_queue SET attempts=?, next_at=? WHERE url=? AND version=?')
                    ->execute([$attempts, time() + $delay, $row['url'], $row['version']]);
            }
        }
        $pdo->prepare('DELETE FROM google_indexing_attempts WHERE attempted_at < ?')->execute([time() - 172800]);
        return $processed;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function indexing_google_run_auto_batch(PDO $pdo, ?callable $http = null, ?int $limit = null): array
{
    require_once __DIR__ . '/google-credentials.php';
    if (!google_safe_status()['configured']) {
        throw new RuntimeException('Google credentials are not configured. Please configure them in SEO Settings.');
    }
    if (!google_indexing_enabled()) {
        throw new RuntimeException('Google Indexing API is disabled. Please enable it in SEO Settings.');
    }
    $dailyLimit = google_indexing_daily_limit();
    if ($pdo->query("SELECT 1 FROM indexing_jobs WHERE name='google-indexing-cooldown' AND next_at>" . time())->fetchColumn()) {
        throw new RuntimeException('Google Indexing API is currently in 24-hour cooldown due to a previous HTTP 429 or 403 response.');
    }
    $quotaBefore = google_indexing_quota_remaining($pdo, $dailyLimit);
    if ($quotaBefore <= 0) {
        throw new RuntimeException('Google Indexing API daily quota (' . $dailyLimit . '/day) reached. Retry once rolling 24h quota frees up.');
    }
    $queued = indexing_google_queue_unindexed($pdo, $limit);
    $published = indexing_google_process_queue($pdo, $http, $limit);
    $remaining = google_indexing_quota_remaining($pdo, $dailyLimit);
    return [
        'queued' => $queued,
        'published' => $published,
        'quota_remaining' => $remaining,
        'daily_limit' => $dailyLimit,
        'message' => 'Auto-submit batch complete: ' . $queued . ' unindexed URL(s) queued, ' . $published . ' published to Google Indexing API (' . $remaining . '/' . $dailyLimit . ' daily quota remaining).',
    ];
}

function indexing_google_publish_manual(PDO $pdo, array $ids, ?callable $http = null): array
{
    indexing_schema($pdo);
    require_once __DIR__ . '/google-credentials.php';
    if (!google_safe_status()['configured']) {
        throw new RuntimeException('Google credentials are not configured. Please configure them in SEO Settings.');
    }
    if (!google_indexing_enabled()) {
        throw new RuntimeException('Google Indexing API is disabled. Please enable it in SEO Settings.');
    }
    if (count($ids) < 1 || count($ids) > 50) {
        throw new InvalidArgumentException('Select between 1 and 50 URLs to submit.');
    }
    foreach ($ids as $id) {
        if (!is_scalar($id) || !ctype_digit((string)$id) || (int)$id < 1) {
            throw new InvalidArgumentException('Invalid URL selection.');
        }
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $dailyLimit = google_indexing_daily_limit();
    $quota = google_indexing_quota_remaining($pdo, $dailyLimit);
    if ($quota <= 0) {
        throw new RuntimeException('Google Indexing API daily quota (' . $dailyLimit . '/day) reached. Retry in 24 hours.');
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, url, in_sitemap FROM index_checks WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        throw new InvalidArgumentException('No matching URLs found.');
    }

    $submitted = 0;
    $failed = 0;
    $now = time();
    foreach ($rows as $row) {
        if ($submitted >= $quota) {
            break;
        }
        $type = (int)$row['in_sitemap'] ? 'URL_UPDATED' : 'URL_DELETED';
        try {
            $res = google_publish_url($row['url'], $type, $http);
            $statusCode = (int)$res['status'];
            $pdo->prepare('INSERT INTO google_indexing_attempts(url, type, status_code, attempted_at) VALUES(?,?,?,?)')
                ->execute([$row['url'], $type, $statusCode, time()]);

            if ($res['success']) {
                $submitted++;
                try {
                    $pdo->prepare('UPDATE index_checks SET google_submitted_at=?, google_submit_status="Success", updated_at=? WHERE id=?')
                        ->execute([$now, $now, $row['id']]);
                    $pdo->prepare('DELETE FROM google_indexing_queue WHERE url=?')
                        ->execute([$row['url']]);
                } catch (Throwable) {}
            } else {
                $failed++;
                try {
                    $pdo->prepare('UPDATE index_checks SET google_submit_status=?, updated_at=? WHERE id=?')
                        ->execute(['HTTP ' . $statusCode, $now, $row['id']]);
                } catch (Throwable) {}
                if ($statusCode === 429 || $statusCode === 403) {
                    break;
                }
            }
        } catch (Throwable $e) {
            $failed++;
            error_log('Google publish manual failed: ' . $e->getMessage());
        }
    }

    $msg = $submitted . ' URL(s) submitted to Google Indexing API.';
    if ($failed > 0) {
        $msg .= ' ' . $failed . ' URL(s) failed or quota reached.';
    }
    return [
        'submitted' => $submitted,
        'failed' => $failed,
        'message' => $msg,
    ];
}

/**
 * Strictly read-only summary of the optional GSC browser worker ledger (when configured
 * outside the public webroot via GSC_BROWSER_STATE_DIR). Never writes or mixes browser
 * results into Google Indexing API columns.
 */
function indexing_gsc_browser_status(?string $property = null): array
{
    $cliPath = dirname(__DIR__) . '/scripts/gsc-browser/cli.mjs';
    $installed = is_file($cliPath);
    $stateDir = env_value('GSC_BROWSER_STATE_DIR');
    $base = [
        'installed' => $installed,
        'configured' => false,
        'ledger_found' => false,
        'counts' => [],
        'attempts_24h' => 0,
        'paused' => null,
    ];
    if (!$installed || !is_string($stateDir) || trim($stateDir) === '' || !str_starts_with($stateDir, '/')) {
        return $base;
    }
    $resolved = @realpath($stateDir);
    $webRoot = @realpath(site_root_path()) ?: site_root_path();
    if ($resolved === false || !is_dir($resolved)
        || $resolved === $webRoot || str_starts_with($resolved, rtrim($webRoot, '/') . '/')) {
        return $base;
    }
    $base['configured'] = true;
    require_once __DIR__ . '/google-credentials.php';
    $prop = $property ?? google_property();
    if ($prop === '') {
        return $base;
    }
    $key = substr(hash('sha256', $prop), 0, 24);
    $ledgerFile = $resolved . '/ledger-' . $key . '.json';
    if (!is_file($ledgerFile) || !is_readable($ledgerFile) || is_link($ledgerFile)) {
        return $base;
    }
    $raw = @file_get_contents($ledgerFile);
    if (!is_string($raw) || $raw === '') {
        return $base;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || ($data['version'] ?? null) !== 1 || ($data['property'] ?? null) !== $prop || !is_array($data['records'] ?? null)) {
        return $base;
    }
    $counts = [];
    foreach ($data['records'] as $record) {
        if (is_array($record) && is_string($record['status'] ?? null)) {
            $s = $record['status'];
            $counts[$s] = ($counts[$s] ?? 0) + 1;
        }
    }
    $cutoffMs = (time() - 86400) * 1000;
    $attempts24h = 0;
    if (is_array($data['attempts'] ?? null)) {
        foreach ($data['attempts'] as $ts) {
            if (is_numeric($ts) && (float) $ts > $cutoffMs) {
                $attempts24h++;
            }
        }
    }
    $paused = null;
    if (is_array($data['paused'] ?? null) && is_string($data['paused']['reason'] ?? null)) {
        $paused = [
            'reason' => $data['paused']['reason'],
            'until' => isset($data['paused']['until']) && is_numeric($data['paused']['until']) ? (int) floor(((float) $data['paused']['until']) / 1000) : null,
        ];
    }
    $base['ledger_found'] = true;
    $base['counts'] = $counts;
    $base['attempts_24h'] = $attempts24h;
    $base['paused'] = $paused;
    return $base;
}

/**
 * Read-only snapshot of automated indexing status for the Admin Index Checker dashboard.
 */
function indexing_automation_status(PDO $pdo): array
{
    indexing_schema($pdo);
    require_once __DIR__ . '/google-credentials.php';
    require_once __DIR__ . '/indexnow.php';

    $now = time();
    $google = google_safe_status();
    $indexnow = indexnow_settings();
    $dailyLimit = google_indexing_daily_limit();
    $quotaRemaining = google_indexing_quota_remaining($pdo, $dailyLimit);
    $used24h = max(0, $dailyLimit - $quotaRemaining);

    $queuedGoogle = (int) $pdo->query('SELECT COUNT(*) FROM google_indexing_queue')->fetchColumn();
    $queuedIndexNow = (int) $pdo->query('SELECT COUNT(*) FROM indexing_notifications')->fetchColumn();

    $googleCooldown = $pdo->query("SELECT next_at FROM indexing_jobs WHERE name='google-indexing-cooldown' AND next_at>" . $now)->fetchColumn();
    $indexnowCooldown = $pdo->query("SELECT next_at FROM indexing_jobs WHERE name='indexnow-cooldown' AND next_at>" . $now)->fetchColumn();

    $unsubmittedUnindexed = 0;
    if ($pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='index_checks'")->fetchColumn()) {
        $unsubmittedUnindexed = (int) $pdo->query("
            SELECT COUNT(*) FROM index_checks
            WHERE in_sitemap = 1
              AND check_status != 'Indexed'
              AND COALESCE(robots_state, '') != 'DISALLOWED'
              AND COALESCE(indexing_state, '') NOT IN ('BLOCKED_BY_META_TAG', 'BLOCKED_BY_HTTP_HEADER')
              AND google_submitted_at IS NULL
        ")->fetchColumn();
    }

    return [
        'google_configured' => (bool) ($google['configured'] ?? false),
        'indexing_api_enabled' => (bool) ($google['indexing_api_enabled'] ?? false),
        'auto_submit_enabled' => (bool) ($google['auto_submit_unindexed'] ?? false),
        'daily_limit' => $dailyLimit,
        'batch_limit' => google_indexing_batch_limit(),
        'resubmit_days' => (int) (google_indexing_resubmit_seconds() / 86400),
        'used_24h' => $used24h,
        'quota_remaining' => $quotaRemaining,
        'google_queued' => $queuedGoogle,
        'unsubmitted_unindexed' => $unsubmittedUnindexed,
        'google_cooldown_until' => $googleCooldown ? (int) $googleCooldown : null,
        'indexnow_enabled' => (bool) ($indexnow['enabled'] ?? false),
        'indexnow_status' => (string) ($indexnow['status'] ?? 'Not Configured'),
        'indexnow_queued' => $queuedIndexNow,
        'indexnow_cooldown_until' => $indexnowCooldown ? (int) $indexnowCooldown : null,
        'gsc_browser' => indexing_gsc_browser_status((string) ($google['property'] ?? '')),
    ];
}
