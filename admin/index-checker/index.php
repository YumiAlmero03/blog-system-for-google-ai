<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/index-checker.php';
header('Cache-Control: no-store');
$pdo=index_checker_pdo(); $message='';
if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
    require_valid_csrf();
    try {
        $ids=isset($_POST['recheck']) ? [$_POST['recheck']] : ($_POST['ids'] ?? []);
        if (!is_array($ids)) throw new InvalidArgumentException('Invalid URL selection.');
        $count=index_checker_queue($pdo,$ids); csrf_rotate();
        $_SESSION['index_checker_notice']=$count.' URL(s) queued for recheck. The scheduled worker will inspect them within quota.';
        header('Location: /admin/index-checker/',true,303); exit;
    } catch (InvalidArgumentException $error) { http_response_code(422); $message=$error->getMessage(); }
}
if (isset($_SESSION['index_checker_notice'])) { $message=$_SESSION['index_checker_notice']; unset($_SESSION['index_checker_notice']); }
function checker_filter(string $key): string { return isset($_GET[$key]) && is_string($_GET[$key]) ? substr(trim($_GET[$key]),0,2048) : ''; }
function checker_date(mixed $time): string { return $time ? gmdate('Y-m-d H:i',(int)$time).' UTC' : '—'; }
$search=checker_filter('search'); $status=checker_filter('status'); $type=checker_filter('type'); $sitemap=checker_filter('sitemap');
$order=checker_filter('sort')==='oldest' ? 'ASC' : 'DESC';
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
$stmt=$pdo->prepare('SELECT * FROM index_checks'.$sql.' ORDER BY last_checked_at '.$order.',id ASC LIMIT ? OFFSET ?');
foreach ($params as $i=>$value) $stmt->bindValue($i+1,$value);
$stmt->bindValue(count($params)+1,$size,PDO::PARAM_INT); $stmt->bindValue(count($params)+2,($page-1)*$size,PDO::PARAM_INT); $stmt->execute(); $rows=$stmt->fetchAll();
$summary=$pdo->query('SELECT check_status,COUNT(*) FROM index_checks WHERE in_sitemap=1 GROUP BY check_status')->fetchAll(PDO::FETCH_KEY_PAIR);
$sitemaps=$pdo->query('SELECT DISTINCT sitemap_url FROM index_checks ORDER BY sitemap_url')->fetchAll(PDO::FETCH_COLUMN);
$last=$pdo->query('SELECT * FROM index_check_runs ORDER BY id DESC LIMIT 1')->fetch();
$title='Index Checker'; require __DIR__ . '/../partials/support-head.php';
?>
<?php if ($message!==''): ?><p role="status"><?= h($message) ?></p><?php endif; ?>
<p>Total Sitemap URLs: <strong><?= array_sum($summary) ?></strong> · Indexed: <?= (int)($summary['Indexed'] ?? 0) ?> · Not Indexed: <?= (int)($summary['Not Indexed'] ?? 0) ?> · Pending: <?= (int)($summary['Pending'] ?? 0) ?> · Errors: <?= (int)($summary['Error'] ?? 0) ?> · Unknown: <?= (int)($summary['Unknown'] ?? 0) ?></p>
<p>Last Daily Run: <?= $last ? h(checker_date($last['started_at']).' · '.$last['state'].' · '.$last['processed'].' inspected') : 'Not run yet' ?>. Times are UTC.</p>
<p>Results describe Google's stored index information. Rechecks are queued for the scheduled worker. <a href="/admin/settings.php">Settings</a></p>
<form method="get" class="support-actions">
<label>URL <input type="search" name="search" value="<?= h($search) ?>"></label>
<label>Status <select name="status"><option value="">All</option><?php foreach ($statuses as $option): ?><option <?= $status===$option ? 'selected' : '' ?>><?= h($option) ?></option><?php endforeach; ?></select></label>
<label>Type <select name="type"><option value="">All</option><?php foreach ($types as $option): ?><option <?= $type===$option ? 'selected' : '' ?>><?= h($option) ?></option><?php endforeach; ?></select></label>
<label>Sitemap <select name="sitemap"><option value="">All</option><?php foreach ($sitemaps as $option): ?><option value="<?= h($option) ?>" <?= $sitemap===$option ? 'selected' : '' ?>><?= h(basename($option)) ?></option><?php endforeach; ?></select></label>
<label>Last checked <select name="sort"><option value="newest">Newest first</option><option value="oldest" <?= $order==='ASC' ? 'selected' : '' ?>>Oldest first</option></select></label>
<label><input type="checkbox" name="inactive" value="1" <?= checker_filter('inactive')==='1' ? 'checked' : '' ?>> Include removed URLs</label>
<button class="btn btn-secondary btn-sm">Filter</button>
</form>
<form method="post"><?= csrf_input() ?>
<div class="support-table"><table><thead><tr><th>Select</th><th>URL</th><th>Type</th><th>Index Status</th><th>Google Verdict</th><th>Last Crawl</th><th>Last Checked</th><th>Next Check</th><th>Sitemap</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr>
<td><input aria-label="Select <?= h($row['url']) ?>" type="checkbox" name="ids[]" value="<?= (int)$row['id'] ?>"></td>
<td style="overflow-wrap:anywhere;max-width:400px"><?= h($row['url']) ?><?php if (!(int)$row['in_sitemap']): ?><br><small>Inactive / removed</small><?php endif; ?></td>
<td><?= h($row['content_type']) ?></td><td><?= h($row['check_status']) ?><?php if ((int)$row['queued']): ?><br><small>Queued for recheck</small><?php endif; ?><?php if ($row['error_message']!==''): ?><br><small><?= h($row['error_message']) ?></small><?php endif; ?></td>
<td><?= h($row['verdict'] ?: '—') ?><br><small><?= h($row['coverage_state']) ?></small>
<?php if ($row['last_checked_at']): ?><details><summary>Details</summary>Indexing: <?= h($row['indexing_state']) ?><br>Robots: <?= h($row['robots_state']) ?><br>Google canonical: <?= h($row['google_canonical']) ?><br>User canonical: <?= h($row['user_canonical']) ?></details><?php endif; ?></td>
<td><?= h($row['last_crawl_at'] ?: '—') ?></td><td><?= h(checker_date($row['last_checked_at'])) ?></td><td><?= $row['next_check_at'] ? h(checker_date($row['next_check_at'])) : 'As quota allows' ?></td><td title="<?= h($row['sitemap_url']) ?>"><?= h(basename($row['sitemap_url'])) ?></td>
<td><button class="btn btn-secondary btn-sm" name="recheck" value="<?= (int)$row['id'] ?>">Recheck</button></td>
</tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="10">No results. Configure the worker and run sitemap synchronization to populate this list.</td></tr><?php endif; ?>
</tbody></table></div><div class="support-actions"><button class="btn btn-secondary btn-sm" type="submit">Queue selected URLs</button></div>
</form>
<div class="support-actions">Page <?= $page ?> of <?= $pages ?> · <?= $total ?> results
<?php foreach (['Previous'=>$page-1,'Next'=>$page+1] as $label=>$target): if ($target<1 || $target>$pages) continue; ?><a class="btn btn-secondary btn-sm" href="?<?= h(http_build_query(array_merge($_GET,['page'=>$target]))) ?>"><?= h($label) ?></a><?php endforeach; ?></div>
</main></body></html>
