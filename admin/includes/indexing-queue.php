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
}

function indexing_reconcile(PDO $pdo, string $kind): int
{
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
        foreach ($events as $url=>$removed) {
            $version=bin2hex(random_bytes(12));
            $queue->execute([$url,$kind,$removed,$version]); $google->execute([$url,$kind,$removed,$version]);
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
