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

function google_indexing_quota_remaining(PDO $pdo, int $dailyLimit = 200): int
{
    indexing_schema($pdo);
    $now = time();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM google_indexing_attempts WHERE attempted_at > ?');
    $stmt->execute([$now - 86400]);
    $used = (int)$stmt->fetchColumn();
    return max(0, $dailyLimit - $used);
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
        $dailyLimit = (int)(env_value('GOOGLE_INDEXING_DAILY_LIMIT') ?: 200);
        if ($dailyLimit < 1) $dailyLimit = 200;
        $now = time();
        $quota = google_indexing_quota_remaining($pdo, $dailyLimit);
        if ($quota <= 0) {
            return 0;
        }
        $batchSize = $limit ?? min($quota, 50);
        $stmt = $pdo->prepare('SELECT * FROM google_indexing_queue WHERE next_at <= ? ORDER BY next_at, url LIMIT ?');
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
                $pdo->prepare('INSERT INTO google_indexing_attempts(url, type, status_code, attempted_at) VALUES(?,?,?,?)')
                    ->execute([$row['url'], $type, $statusCode, time()]);

                if ($res['success']) {
                    $pdo->prepare('DELETE FROM google_indexing_queue WHERE url=? AND version=?')
                        ->execute([$row['url'], $row['version']]);
                    try {
                        $pdo->prepare('UPDATE index_checks SET google_submitted_at=?, google_submit_status="Success" WHERE url=?')
                            ->execute([time(), $row['url']]);
                    } catch (Throwable) {}
                    $processed++;
                } elseif ($statusCode === 429 || $statusCode === 403) {
                    $delay = 86400;
                    $pdo->prepare("INSERT INTO indexing_jobs(name, version, next_at) VALUES('google-indexing-cooldown', ?, ?) ON CONFLICT(name) DO UPDATE SET next_at = excluded.next_at")
                        ->execute([bin2hex(random_bytes(12)), time() + $delay]);
                    $pdo->prepare('UPDATE google_indexing_queue SET attempts=attempts+1, next_at=? WHERE url=? AND version=?')
                        ->execute([time() + $delay, $row['url'], $row['version']]);
                    break;
                } else {
                    $attempts = (int)$row['attempts'] + 1;
                    $delay = min(86400, 600 * (2 ** min(6, $attempts - 1)));
                    $pdo->prepare('UPDATE google_indexing_queue SET attempts=?, next_at=? WHERE url=? AND version=?')
                        ->execute([$attempts, time() + $delay, $row['url'], $row['version']]);
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
    $dailyLimit = (int)(env_value('GOOGLE_INDEXING_DAILY_LIMIT') ?: 200);
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
