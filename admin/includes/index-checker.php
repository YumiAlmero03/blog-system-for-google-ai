<?php
declare(strict_types=1);
require_once __DIR__ . '/index-checker-client.php';

function index_checker_pdo(): PDO
{
    $pdo=blogs_pdo();
    $pdo->exec('CREATE TABLE IF NOT EXISTS index_checks (
        id INTEGER PRIMARY KEY AUTOINCREMENT, url TEXT NOT NULL UNIQUE, sitemap_url TEXT NOT NULL,
        content_type TEXT NOT NULL, in_sitemap INTEGER NOT NULL DEFAULT 1, queued INTEGER NOT NULL DEFAULT 0,
        check_status TEXT NOT NULL DEFAULT "Pending", coverage_state TEXT NOT NULL DEFAULT "", verdict TEXT NOT NULL DEFAULT "",
        indexing_state TEXT NOT NULL DEFAULT "", robots_state TEXT NOT NULL DEFAULT "", last_crawl_at TEXT NOT NULL DEFAULT "",
        google_canonical TEXT NOT NULL DEFAULT "", user_canonical TEXT NOT NULL DEFAULT "",
        last_checked_at INTEGER, next_check_at INTEGER NOT NULL DEFAULT 0, error_message TEXT NOT NULL DEFAULT "",
        failures INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS index_checks_due ON index_checks(in_sitemap,next_check_at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS index_check_attempts (id INTEGER PRIMARY KEY, property TEXT NOT NULL, attempted_at INTEGER NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS index_attempt_quota ON index_check_attempts(property,attempted_at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS index_check_runs (id INTEGER PRIMARY KEY, started_at INTEGER NOT NULL, finished_at INTEGER, processed INTEGER NOT NULL DEFAULT 0, state TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS index_check_limits (property TEXT PRIMARY KEY, blocked_until INTEGER NOT NULL DEFAULT 0)');
    return $pdo;
}

function index_checker_type(string $url): string
{
    $path=parse_url($url,PHP_URL_PATH) ?: '/';
    if (preg_match('~^/(blogs?)/[^/]+/?$~',$path)) return 'blog';
    if (preg_match('~^/(games?)/[^/]+/?$~',$path)) return 'game';
    return preg_match('~\.[^/]+$~',$path) ? 'other' : 'page';
}

// A single grouped eligibility snapshot protects against outdated sitemap files.
function index_checker_eligible_sets(PDO $pdo): array
{
    $games=$pdo->query('SELECT DISTINCT slug FROM games WHERE '.game_public_eligibility_sql())->fetchAll(PDO::FETCH_COLUMN);
    $stmt=$pdo->prepare('SELECT slug FROM blog_posts WHERE '.blog_public_visibility_sql()); $stmt->execute([':visibility_now'=>time()]);
    return ['game'=>array_fill_keys($games,true),'blog'=>array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN),true)];
}

function index_checker_eligible(string $url,array $config,array $sets): bool
{
    if (!index_checker_url($url) || index_checker_origin($url)!==index_checker_origin($config['site']) || !index_checker_in_property($url,$config['property']) || !index_checker_safe_path($url)) return false;
    $type=index_checker_type($url);
    if (isset($sets[$type])) {
        $slug=basename(trim((string)parse_url($url,PHP_URL_PATH),'/'));
        return normalize_slug($slug)===$slug && isset($sets[$type][$slug]);
    }
    return true;
}

function index_checker_discover(array $config, array $sets, ?callable $fetch = null): array
{
    $fetch ??= static fn(string $url): array => index_checker_http($url);
    $pending=[$config['site'].'/sitemap-index.xml']; $seen=[]; $urls=[];
    while ($pending) {
        $sitemap=array_shift($pending);
        if (isset($seen[$sitemap])) continue;
        if (count($seen)>=1000 || count($urls)>200000) throw new RuntimeException('Sitemap discovery limit exceeded.');
        if (!index_checker_url($sitemap) || index_checker_origin($sitemap)!==index_checker_origin($config['site']) || !index_checker_safe_path($sitemap)) throw new RuntimeException('Unsafe child sitemap location.');
        $seen[$sitemap]=true; $response=$fetch($sitemap);
        if ($response['status']!==200 || preg_match('/<!DOCTYPE|<!ENTITY/i',$response['body'])) throw new RuntimeException('Sitemap fetch or XML validation failed.');
        $xml=new DOMDocument(); $previous=libxml_use_internal_errors(true);
        try { $ok=$xml->loadXML($response['body'],LIBXML_NONET | LIBXML_NOBLANKS); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $root=$xml->documentElement;
        if (!$ok || !$root || !in_array($root->localName,['urlset','sitemapindex'],true) || !in_array($root->namespaceURI,[null,'','http://www.sitemaps.org/schemas/sitemap/0.9'],true)) throw new RuntimeException('Invalid sitemap XML.');
        $xpath=new DOMXPath($xml);
        $entry=$root->localName==='sitemapindex' ? 'sitemap' : 'url';
        foreach ($xpath->query('/*/*[local-name()="'.$entry.'"]/*[local-name()="loc"]') as $node) {
            $url=trim($node->textContent);
            if ($entry==='sitemap') { $pending[]=$url; if (count($pending)>10000) throw new RuntimeException('Too many sitemap references.'); }
            elseif (index_checker_eligible($url,$config,$sets)) $urls[$url] ??= ['sitemap_url'=>$sitemap,'content_type'=>index_checker_type($url)];
            if (count($urls)>200000) throw new RuntimeException('Too many sitemap URLs.');
        }
    }
    return $urls;
}

function index_checker_sync(PDO $pdo,array $urls,int $now): void
{
    $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE index_checks SET in_sitemap=0 WHERE in_sitemap=1');
        $stmt=$pdo->prepare('INSERT INTO index_checks(url,sitemap_url,content_type,created_at,updated_at) VALUES(?,?,?,?,?)
            ON CONFLICT(url) DO UPDATE SET sitemap_url=excluded.sitemap_url,content_type=excluded.content_type,in_sitemap=1,updated_at=excluded.updated_at');
        foreach ($urls as $url=>$meta) $stmt->execute([$url,$meta['sitemap_url'],$meta['content_type'],$now,$now]);
        $pdo->commit();
    } catch (Throwable $error) { $pdo->rollBack(); throw $error; }
}

// Regeneration changes game membership only; preserve blog/page results and schedules.
function index_checker_sync_game_sitemaps(PDO $pdo, array $urls, int $now): void
{
    $pdo->beginTransaction();
    try {
        $pdo->exec("UPDATE index_checks SET in_sitemap=0 WHERE content_type='game'");
        $stmt = $pdo->prepare("INSERT INTO index_checks(url,sitemap_url,content_type,created_at,updated_at) VALUES(?,?,'game',?,?)
            ON CONFLICT(url) DO UPDATE SET sitemap_url=excluded.sitemap_url,in_sitemap=1,updated_at=excluded.updated_at");
        foreach ($urls as $url => $sitemap) $stmt->execute([$url,$sitemap,$now,$now]);
        $pdo->exec("UPDATE index_checks SET queued=0 WHERE content_type='game' AND in_sitemap=0");
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function index_checker_queue(PDO $pdo,array $ids): int
{
    if (!$ids || count($ids)>100) throw new InvalidArgumentException('Select between 1 and 100 URLs.');
    foreach ($ids as $id) if (!is_scalar($id) || !ctype_digit((string)$id) || (int)$id<1) throw new InvalidArgumentException('Invalid URL selection.');
    $ids=array_values(array_unique(array_map('intval',$ids)));
    $stmt=$pdo->prepare('UPDATE index_checks SET queued=1,next_check_at=0,updated_at=? WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
    $stmt->execute(array_merge([time()],$ids)); return $stmt->rowCount();
}

function index_checker_due(PDO $pdo,int $limit,int $now): array
{
    $stmt=$pdo->prepare('SELECT * FROM index_checks WHERE (in_sitemap=1 OR queued=1) AND next_check_at<=?
        ORDER BY CASE WHEN last_checked_at IS NULL THEN 0 WHEN queued=1 THEN 1 WHEN check_status="Not Indexed" THEN 2 WHEN check_status IN ("Error","Unknown") THEN 3 ELSE 4 END,
        next_check_at,created_at,id LIMIT ?');
    $stmt->bindValue(1,$now,PDO::PARAM_INT); $stmt->bindValue(2,$limit,PDO::PARAM_INT); $stmt->execute(); return $stmt->fetchAll();
}

function index_checker_reserve(PDO $pdo,array $config,int $now): bool
{
    // Atomic accounting even if another worker reaches this helper outside the cron lock.
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $stmt=$pdo->prepare('SELECT blocked_until FROM index_check_limits WHERE property=?'); $stmt->execute([$config['property']]);
        $blocked=(int)$stmt->fetchColumn();
        $stmt=$pdo->prepare('SELECT COUNT(*) AS daily,COALESCE(SUM(attempted_at>?),0) AS minute FROM index_check_attempts WHERE property=? AND attempted_at>?');
        $stmt->execute([$now-60,$config['property'],$now-86400]); $used=$stmt->fetch();
        if ($blocked>$now || (int)$used['daily']>=$config['daily'] || (int)$used['minute']>=$config['minute']) { $pdo->exec('COMMIT'); return false; }
        $pdo->prepare('INSERT INTO index_check_attempts(property,attempted_at) VALUES(?,?)')->execute([$config['property'],$now]);
        $pdo->prepare('DELETE FROM index_check_attempts WHERE attempted_at<?')->execute([$now-172800]);
        $pdo->exec('COMMIT'); return true;
    } catch (Throwable $e) { $pdo->exec('ROLLBACK'); throw $e; }
}

function index_checker_block(PDO $pdo,string $property,int $until): void
{
    $pdo->prepare('INSERT INTO index_check_limits(property,blocked_until) VALUES(?,?) ON CONFLICT(property) DO UPDATE SET blocked_until=MAX(blocked_until,excluded.blocked_until)')->execute([$property,$until]);
}

function index_checker_result(PDO $pdo,array $row,array $response,array $config,int $now): string
{
    $data=json_decode($response['body'],true); $status=(int)$response['status']; $error=''; $stop=''; $result=[];
    if ($status===200 && is_array($data)) {
        $candidate=$data['inspectionResult']['indexStatusResult'] ?? [];
        $result=is_array($candidate) ? $candidate : [];
        $verdict=$result['verdict'] ?? '';
        $simple=$verdict==='PASS' ? 'Indexed' : (in_array($verdict,['FAIL','NEUTRAL'],true) ? 'Not Indexed' : 'Unknown');
    } else {
        $simple='Error'; $error=$status ? 'Google inspection failed (HTTP '.$status.').' : 'Inspection request failed.';
        $reasons=array_column(is_array($data['error']['errors'] ?? null) ? $data['error']['errors'] : [],'reason');
        if ($status===429 || array_intersect($reasons,['quotaExceeded','rateLimitExceeded','dailyLimitExceeded','userRateLimitExceeded']) || ($data['error']['status'] ?? '')==='RESOURCE_EXHAUSTED') $stop='Quota';
        elseif (in_array($status,[401,403],true)) $stop='Authentication';
    }
    $failures=in_array($simple,['Error','Unknown'],true) ? (int)$row['failures']+1 : 0;
    $delay=match($simple) { 'Indexed'=>$config['indexed'], 'Not Indexed'=>$config['not_indexed'], default=>min($config['max_backoff'],$config['retry']*(2**min(8,max(0,$failures-1)))) };
    if ($stop!=='') {
        $delay=max($delay,$stop==='Quota' ? 86400 : 3600);
        $retry=$response['headers']['retry-after'][0] ?? '';
        if (ctype_digit((string)$retry)) $delay=max($delay,min(604800,(int)$retry));
        elseif (is_string($retry) && ($stamp=strtotime($retry))!==false) $delay=max($delay,min(604800,max(0,$stamp-$now)));
        index_checker_block($pdo,$config['property'],$now+$delay);
    }
    $fields=[];
    foreach (['coverageState'=>'coverage_state','verdict'=>'verdict','indexingState'=>'indexing_state','robotsTxtState'=>'robots_state','lastCrawlTime'=>'last_crawl_at','googleCanonical'=>'google_canonical','userCanonical'=>'user_canonical'] as $key=>$column) {
        $fields[]=$simple==='Error' ? ($row[$column] ?? '') : (is_string($result[$key] ?? null) ? substr($result[$key],0,2048) : '');
    }
    $pdo->prepare('UPDATE index_checks SET check_status=?,coverage_state=?,verdict=?,indexing_state=?,robots_state=?,last_crawl_at=?,google_canonical=?,user_canonical=?,last_checked_at=?,next_check_at=?,error_message=?,failures=?,queued=0,updated_at=? WHERE id=?')
        ->execute(array_merge([$simple],$fields,[$now,$now+$delay,$error,$failures,$now,$row['id']]));
    return $stop;
}

function index_checker_page_allowed(array $response): bool
{
    if (in_array((int)$response['status'],[401,403,404,410],true)) return false;
    if ((int)$response['status']!==200) throw new RuntimeException('Public URL could not be verified.');
    $rules=implode(',', $response['headers']['x-robots-tag'] ?? []);
    if (preg_match('/\b(noindex|none)\b/i',$rules)) return false;
    $doc=new DOMDocument(); $previous=libxml_use_internal_errors(true);
    try { @$doc->loadHTML($response['body'],LIBXML_NONET); } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    foreach ($doc->getElementsByTagName('meta') as $meta) {
        if (in_array(strtolower($meta->getAttribute('name')),['robots','googlebot'],true) && preg_match('/\b(noindex|none)\b/i',$meta->getAttribute('content'))) return false;
    }
    return true;
}

function index_checker_run(PDO $pdo,array $config,?callable $fetch = null,?callable $inspect = null,?callable $pause = null): array
{
    $probe = str_starts_with($config['property'], 'sc-domain:') ? $config['site'].'/' : $config['property'];
    if (!index_checker_in_property($probe, $config['property']) || index_checker_origin($probe) !== index_checker_origin($config['site'])) throw new RuntimeException('Search Console property must cover the configured site.');
    $fetch ??= static fn(string $url): array => index_checker_http($url);
    $inspect ??= static fn(string $url): array => index_checker_inspect($url,$config);
    $pause ??= static function (): void { usleep(1100000); };
    $lock=fopen(blog_storage_dir().'/index-checker.lock','c');
    if (!$lock || !flock($lock,LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); return ['state'=>'Already running','processed'=>0]; }
    $processed=0; $runId=null;
    try {
        error_log('Index Checker: run started.');
        $pdo->prepare('INSERT INTO index_check_runs(started_at,state) VALUES(?,"Running")')->execute([time()]); $runId=$pdo->lastInsertId();
        $sets=index_checker_eligible_sets($pdo);
        try { $urls=index_checker_discover($config,$sets,$fetch); }
        catch (Throwable $e) { error_log('Index Checker: sitemap discovery failed; previous membership retained.'); throw new RuntimeException('Sitemap discovery failed; no membership changes applied.',0,$e); }
        index_checker_sync($pdo,$urls,time());
        $state='Complete';
        foreach (index_checker_due($pdo,$config['batch'],time()) as $row) {
            if (!index_checker_eligible($row['url'],$config,$sets)) {
                $pdo->prepare('UPDATE index_checks SET in_sitemap=0,queued=0,next_check_at=?,updated_at=? WHERE id=?')->execute([time()+$config['indexed'],time(),$row['id']]); continue;
            }
            try {
                if (!index_checker_page_allowed($fetch($row['url']))) {
                    $pdo->prepare('UPDATE index_checks SET in_sitemap=0,queued=0,next_check_at=?,updated_at=? WHERE id=?')->execute([time()+$config['indexed'],time(),$row['id']]); continue;
                }
            } catch (Throwable $error) {
                // No Google quota consumed: record a safe, retryable fetch failure.
                index_checker_result($pdo,$row,['status'=>0,'body'=>'','headers'=>[]],$config,time());
                $pdo->prepare('UPDATE index_checks SET error_message=? WHERE id=?')->execute(['Public URL verification failed; inspection was not requested.',$row['id']]);
                error_log('Index Checker: public URL verification failed.'); continue;
            }
            if (!index_checker_reserve($pdo,$config,time())) { $state='Quota deferred'; error_log('Index Checker: quota/cooldown reached; remaining URLs queued.'); break; }
            try { $response=$inspect($row['url']); }
            catch (Throwable $error) {
                // Do not log exception text, which may originate from a credential transport.
                $response=['status'=>0,'body'=>'','headers'=>[]];
                index_checker_result($pdo,$row,$response,$config,time());
                index_checker_block($pdo,$config['property'],time()+$config['retry']);
                $processed++; $state='Configuration or transport error'; error_log('Index Checker: authentication or inspection transport failed.'); break;
            }
            $stop=index_checker_result($pdo,$row,$response,$config,time()); $processed++;
            if ($response['status']!==200) error_log('Index Checker: inspection HTTP '.(int)$response['status'].'.');
            if ($stop!=='') { $state=$stop.' deferred'; error_log('Index Checker: '.$stop.' stop; remaining URLs queued.'); break; }
            $pause();
        }
        $pdo->prepare('UPDATE index_check_runs SET finished_at=?,processed=?,state=? WHERE id=?')->execute([time(),$processed,$state,$runId]);
        $pdo->exec('DELETE FROM index_check_runs WHERE id NOT IN (SELECT id FROM index_check_runs ORDER BY id DESC LIMIT 90)');
        error_log('Index Checker: run ended; processed '.$processed.'; '.$state.'.');
        return ['state'=>$state,'sitemap_urls'=>count($urls),'processed'=>$processed];
    } catch (Throwable $error) {
        if ($runId) $pdo->prepare('UPDATE index_check_runs SET finished_at=?,processed=?,state="Failed" WHERE id=?')->execute([time(),$processed,$runId]);
        error_log('Index Checker: run failed.'); throw $error;
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
