<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/index-checker.php';
require_once __DIR__ . '/../includes/indexing-queue.php';
header('Cache-Control: no-store');
$pdo=index_checker_pdo(); $message='';
if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
    require_valid_csrf();
    try {
        $action = $_POST['action'] ?? (isset($_POST['publish_google']) ? 'publish_google' : 'recheck');
        if ($action === 'publish_google') {
            $ids = isset($_POST['publish_google']) ? [$_POST['publish_google']] : ($_POST['ids'] ?? []);
            if (!is_array($ids) || !$ids) throw new InvalidArgumentException('Select between 1 and 50 URLs to submit.');
            $result = indexing_google_publish_manual($pdo, $ids);
            csrf_rotate();
            $_SESSION['index_checker_notice'] = $result['message'];
            header('Location: /admin/index-checker/', true, 303); exit;
        } elseif ($action === 'run_auto_submit') {
            $result = indexing_google_run_auto_batch($pdo);
            csrf_rotate();
            $_SESSION['index_checker_notice'] = $result['message'];
            header('Location: /admin/index-checker/', true, 303); exit;
        } else {
            $ids = isset($_POST['recheck']) ? [$_POST['recheck']] : ($_POST['ids'] ?? []);
            if (!is_array($ids)) throw new InvalidArgumentException('Invalid URL selection.');
            $count = index_checker_queue($pdo, $ids); csrf_rotate();
            $_SESSION['index_checker_notice'] = $count.' URL(s) queued for recheck. The scheduled worker will inspect them within quota.';
            header('Location: /admin/index-checker/', true, 303); exit;
        }
    } catch (InvalidArgumentException $error) { http_response_code(422); $message = $error->getMessage(); }
    catch (Throwable $error) { http_response_code(500); $message = $error->getMessage(); }
}
if (isset($_SESSION['index_checker_notice'])) { $message=$_SESSION['index_checker_notice']; unset($_SESSION['index_checker_notice']); }
function checker_filter(string $key): string { return isset($_GET[$key]) && is_string($_GET[$key]) ? substr(trim($_GET[$key]),0,2048) : ''; }
function checker_date(mixed $time): string { return $time ? gmdate('Y-m-d H:i',(int)$time).' UTC' : '—'; }
$search=checker_filter('search'); $status=checker_filter('status'); $type=checker_filter('type'); $sitemap=checker_filter('sitemap');
$sort=checker_filter('sort');
$orderSql = match ($sort) {
    'errors' => "CASE WHEN check_status='Error' THEN 1 WHEN check_status='Not Indexed' THEN 2 WHEN check_status='Unknown' THEN 3 WHEN check_status='Pending' THEN 4 ELSE 5 END ASC, last_checked_at DESC, id ASC",
    'oldest' => 'last_checked_at ASC, id ASC',
    default => 'last_checked_at DESC, id ASC'
};
$page=max(1,min(100000,(int)checker_filter('page'))); $size=50; $where=[]; $params=[];
if (checker_filter('inactive')!=='1') $where[]='in_sitemap=1';
if ($search!=='') { $where[]="url LIKE ? ESCAPE '\\'"; $params[]=blog_like_term($search); }
$statuses=['Indexed','Not Indexed','Unknown','Error','Pending']; $types=['page','blog','game','other'];
if (in_array($status,$statuses,true)) { $where[]='check_status=?'; $params[]=$status; }
if (in_array($type,$types,true)) { $where[]='content_type=?'; $params[]=$type; }
if ($sitemap!=='') { $where[]='sitemap_url=?'; $params[]=$sitemap; }
$sql=$where ? ' WHERE '.implode(' AND ',$where) : '';
$stmt=$pdo->prepare('SELECT COUNT(*) FROM index_checks'.$sql); $stmt->execute($params); $total=(int)$stmt->fetchColumn();
$pages=max(1,(int)ceil($total/$size)); $page=min($page,$pages);
$stmt=$pdo->prepare('SELECT * FROM index_checks'.$sql.' ORDER BY '.$orderSql.' LIMIT ? OFFSET ?');
foreach ($params as $i=>$value) $stmt->bindValue($i+1,$value);
$stmt->bindValue(count($params)+1,$size,PDO::PARAM_INT); $stmt->bindValue(count($params)+2,($page-1)*$size,PDO::PARAM_INT); $stmt->execute(); $rows=$stmt->fetchAll();
$summary=$pdo->query('SELECT check_status,COUNT(*) FROM index_checks WHERE in_sitemap=1 GROUP BY check_status')->fetchAll(PDO::FETCH_KEY_PAIR);
$sitemaps=$pdo->query('SELECT DISTINCT sitemap_url FROM index_checks ORDER BY sitemap_url')->fetchAll(PDO::FETCH_COLUMN);
$last=$pdo->query('SELECT * FROM index_check_runs ORDER BY id DESC LIMIT 1')->fetch();
$autoStatus=indexing_automation_status($pdo);
$canRunAutoBatch=$autoStatus['google_configured'] && $autoStatus['indexing_api_enabled'] && $autoStatus['google_cooldown_until']===null && $autoStatus['quota_remaining']>0;
$title='Index Checker'; require __DIR__ . '/../partials/support-head.php';
?>
<?php if ($message!==''): ?><p role="status"><?= h($message) ?></p><?php endif; ?>
<p>Total Sitemap URLs: <strong><?= array_sum($summary) ?></strong> · Indexed: <?= (int)($summary['Indexed'] ?? 0) ?> · Not Indexed: <?= (int)($summary['Not Indexed'] ?? 0) ?> · Pending: <?= (int)($summary['Pending'] ?? 0) ?> · Errors: <?= (int)($summary['Error'] ?? 0) ?> · Unknown: <?= (int)($summary['Unknown'] ?? 0) ?></p>
<p>Last Daily Run: <?= $last ? h(checker_date($last['started_at']).' · '.$last['state'].' · '.$last['processed'].' inspected') : 'Not run yet' ?>. Times are UTC.</p>
<p>Results describe Google's stored index information. Rechecks are queued for the scheduled worker. <a href="/admin/settings/seo/">SEO Settings</a> · <a href="/admin/settings.php">Settings</a></p>
<section style="margin:12px 0;padding:12px 14px;background:var(--surface-soft,#f8f9fa);border:1px solid var(--border,#ddd);border-radius:8px;font-size:0.9rem;">
  <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;">
    <div>
      <strong>Google Indexing API (Manual Submissions):</strong>
      <?php if (!$autoStatus['google_configured'] || !$autoStatus['indexing_api_enabled']): ?>
        <span>Google Indexing API disabled</span>
      <?php elseif ($autoStatus['google_cooldown_until'] !== null): ?>
        <span style="color:#b76e00;">Cooldown until <?= h(checker_date($autoStatus['google_cooldown_until'])) ?></span>
      <?php elseif ($autoStatus['auto_submit_enabled']): ?>
        <span style="color:#0d6630;">Manual auto-submit available (up to <?= (int)$autoStatus['batch_limit'] ?>/run, <?= (int)$autoStatus['daily_limit'] ?>/day, re-submits Not Indexed every <?= (int)$autoStatus['resubmit_days'] ?>d)</span>
      <?php else: ?>
        <span>Manual submissions available; automatic backfill disabled in SEO Settings</span>
      <?php endif; ?>
      <br>
      <small style="color:var(--text-muted,#555);">
        Google 24h Quota: <strong><?= (int)$autoStatus['used_24h'] ?> / <?= (int)$autoStatus['daily_limit'] ?></strong> (<?= (int)$autoStatus['quota_remaining'] ?> remaining) ·
        Queued: <strong><?= (int)$autoStatus['google_queued'] ?></strong> ·
        Unsubmitted unindexed URLs: <strong><?= (int)$autoStatus['unsubmitted_unindexed'] ?></strong> ·
        IndexNow: <strong><?= $autoStatus['indexnow_enabled'] ? h($autoStatus['indexnow_status']) : 'Disabled' ?></strong> (<?= (int)$autoStatus['indexnow_queued'] ?> pending)
      </small>
    </div>
    <form method="post" style="margin:0;">
      <?= csrf_input() ?>
      <button class="btn btn-primary btn-sm" type="submit" name="action" value="run_auto_submit" <?= $canRunAutoBatch ? '' : 'disabled' ?> title="Immediately queue and submit the next priority batch of unindexed URLs (blogs → pages → games) within daily quota">Run Auto-Submit Batch Now</button>
    </form>
  </div>
  <?php if (!empty($autoStatus['gsc_browser']['installed'])): ?>
  <details style="margin-top:8px;">
    <summary style="cursor:pointer;font-size:0.84rem;color:var(--text-muted,#555);">Optional GSC Browser Worker (Search Console UI — separate from API)</summary>
    <p style="margin:6px 0 0;font-size:0.84rem;">
      <?php $gb = $autoStatus['gsc_browser']; ?>
      <?php if ($gb['ledger_found']): ?>
        Ledger status (read-only): Confirmed: <strong><?= (int)($gb['counts']['confirmed'] ?? 0) ?></strong> ·
        Already Indexed: <strong><?= (int)($gb['counts']['indexed'] ?? 0) ?></strong> ·
        Not Indexable: <strong><?= (int)($gb['counts']['not_indexable'] ?? 0) ?></strong> ·
        Uncertain: <strong><?= (int)($gb['counts']['uncertain'] ?? 0) ?></strong> ·
        Clicks (24h): <strong><?= (int)$gb['attempts_24h'] ?></strong>
        <?php if (is_array($gb['paused'])): ?>
          · <span style="color:#d9534f;">Paused (<?= h($gb['paused']['reason']) ?><?= !empty($gb['paused']['until']) ? ' until ' . h(checker_date($gb['paused']['until'])) : '' ?>)</span>
        <?php endif; ?>
      <?php elseif ($gb['configured']): ?>
        Worker installed and state directory configured, but no ledger exists yet for this property.
      <?php else: ?>
        Worker files are installed in <code>admin/scripts/gsc-browser/</code>, but browser automation is not enabled. Scheduled processing uses Search Console sitemap submission, URL Inspection, and IndexNow; Google per-URL API submissions are manual only.
      <?php endif; ?>
    </p>
  </details>
  <?php endif; ?>
</section>
<form method="get" class="support-actions">
<label>URL <input type="search" name="search" value="<?= h($search) ?>"></label>
<label>Status <select name="status"><option value="">All</option><?php foreach ($statuses as $option): ?><option <?= $status===$option ? 'selected' : '' ?>><?= h($option) ?></option><?php endforeach; ?></select></label>
<label>Type <select name="type"><option value="">All</option><?php foreach ($types as $option): ?><option <?= $type===$option ? 'selected' : '' ?>><?= h($option) ?></option><?php endforeach; ?></select></label>
<label>Sitemap <select name="sitemap"><option value="">All</option><?php foreach ($sitemaps as $option): ?><option value="<?= h($option) ?>" <?= $sitemap===$option ? 'selected' : '' ?>><?= h(basename($option)) ?></option><?php endforeach; ?></select></label>
<label>Last checked <select name="sort"><option value="newest">Newest first</option><option value="errors" <?= $sort==='errors' ? 'selected' : '' ?>>Errors first</option><option value="oldest" <?= $sort==='oldest' ? 'selected' : '' ?>>Oldest first</option></select></label>
<label><input type="checkbox" name="inactive" value="1" <?= checker_filter('inactive')==='1' ? 'checked' : '' ?>> Include removed URLs</label>
<button class="btn btn-secondary btn-sm">Filter</button>
</form>
<form method="post"><?= csrf_input() ?>
<div class="support-table"><table><thead><tr><th><input type="checkbox" id="index-select-all" aria-label="Select all URLs on this page"></th><th>URL</th><th>Type</th><th>Index Status</th><th>Google Verdict</th><th>Last Crawl</th><th>Last Checked</th><th>Next Check</th><th>Sitemap</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr>
<td><input aria-label="Select <?= h($row['url']) ?>" type="checkbox" name="ids[]" value="<?= (int)$row['id'] ?>"></td>
<td style="overflow-wrap:anywhere;max-width:400px"><?= h($row['url']) ?><?php if (!(int)$row['in_sitemap']): ?><br><small>Inactive / removed</small><?php endif; ?></td>
<td><?= h($row['content_type']) ?></td><td><?= h($row['check_status']) ?><?php if ((int)$row['queued']): ?><br><small>Queued for recheck</small><?php endif; ?>
<?php if (!empty($row['google_submitted_at'])): ?><br><small style="color:var(--text-muted);">Submitted: <?= h(checker_date($row['google_submitted_at'])) ?></small><?php endif; ?>
<?php if (!empty($row['google_submit_status']) && $row['google_submit_status'] !== 'Success'): ?><br><small style="color:#d9534f;">Submit: <?= h($row['google_submit_status']) ?></small><?php endif; ?>
<?php if ($row['error_message']!==''): ?><br><small><?= h($row['error_message']) ?></small><?php endif; ?></td>
<td><?= h($row['verdict'] ?: '—') ?><br><small><?= h($row['coverage_state']) ?></small>
<?php if ($row['last_checked_at']): ?><details><summary>Details</summary>Indexing: <?= h($row['indexing_state']) ?><br>Robots: <?= h($row['robots_state']) ?><br>Google canonical: <?= h($row['google_canonical']) ?><br>User canonical: <?= h($row['user_canonical']) ?></details><?php endif; ?></td>
<td><?= h($row['last_crawl_at'] ?: '—') ?></td><td><?= h(checker_date($row['last_checked_at'])) ?></td><td><?= $row['next_check_at'] ? h(checker_date($row['next_check_at'])) : 'As quota allows' ?></td><td title="<?= h($row['sitemap_url']) ?>"><?= h(basename($row['sitemap_url'])) ?></td>
<td>
<button class="btn btn-secondary btn-sm" name="recheck" value="<?= (int)$row['id'] ?>" title="Queue for Search Console URL Inspection">Recheck</button>
<button class="btn btn-secondary btn-sm" name="publish_google" value="<?= (int)$row['id'] ?>" title="Submit directly to Google Indexing API">Submit to Google</button>
</td>
</tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="10">No results. Configure the worker and run sitemap synchronization to populate this list.</td></tr><?php endif; ?>
</tbody></table></div><div class="support-actions">
<button class="btn btn-secondary btn-sm" type="submit" name="action" value="recheck">Queue selected URLs</button>
<button class="btn btn-secondary btn-sm" type="submit" name="action" value="publish_google">Submit selected to Google Indexing API</button>
</div>
</form>
<div class="support-actions">Page <?= $page ?> of <?= $pages ?> · <?= $total ?> results
<?php foreach (['Previous'=>$page-1,'Next'=>$page+1] as $label=>$target): if ($target<1 || $target>$pages) continue; ?><a class="btn btn-secondary btn-sm" href="?<?= h(http_build_query(array_merge($_GET,['page'=>$target]))) ?>"><?= h($label) ?></a><?php endforeach; ?></div>
<script>
(() => {
  const selectAll = document.getElementById('index-select-all');
  if (!selectAll) return;
  selectAll.addEventListener('change', () => {
    document.querySelectorAll('input[name="ids[]"]').forEach((box) => { box.checked = selectAll.checked; });
  });
})();
</script>
</main></body></html>
