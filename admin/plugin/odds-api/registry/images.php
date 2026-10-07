<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/sports-images.php';
$tournamentId = max(0, (int)($_GET['tournament'] ?? 0));
$tournament = $tournamentId ? tournament_get($tournamentId) : null;
if ($tournamentId && !$tournament) { http_response_code(404); exit('Tournament not found.'); }
$result = null;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    try {
        if (!in_array($_POST['action'] ?? '', ['preview','enrich'], true)) throw new InvalidArgumentException('Unknown action.');
        @set_time_limit(120);
        $result = sports_images_enrich($tournamentId ?: null, ['limit'=>5,'dry_run'=>($_POST['action'] ?? '') === 'preview','retry'=>isset($_POST['retry'])]);
        csrf_rotate();
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$pdo = sports_images_db();
$where = '';
$params = [];
if ($tournamentId) {
    $where = " WHERE (entity_type='tournament' AND entity_id=?) OR (entity_type='participant' AND entity_id IN (SELECT participant_id FROM participant_tournaments WHERE tournament_id=?))";
    $params = [$tournamentId, $tournamentId];
}
$stmt = $pdo->prepare('SELECT * FROM sports_image_assets'.$where.' ORDER BY checked_at DESC');
$stmt->execute($params);
$records = $stmt->fetchAll();
$title = 'Sports Images';
require __DIR__ . '/../../../partials/support-head.php';
?>
<p><a href="/admin/plugin/odds-api/registry/">Sports Registry</a> · <a href="/admin/plugin/odds-api/registry/teams.php">Participants</a></p>
<h1>Sports Images<?= $tournament ? ': '.h($tournament['title']) : '' ?></h1>
<p>Find missing team and tournament logos using SportsDataverse first, then Wikidata / Wikimedia Commons. No API key required. Existing images and manual uploads are preserved.</p>
<p>Each batch checks up to five missing images. Uncertain matches are left for review. Unmatched items are retried after seven days; errors after one hour.</p>
<form method="get" class="support-actions">
  <label>Tournament <select name="tournament"><option value="0">All enabled tournaments</option>
  <?php foreach (tournament_get_all(null, true) as $item): ?>
    <option value="<?= (int)$item['id'] ?>" <?= $tournamentId === (int)$item['id'] ? 'selected' : '' ?>><?= h($item['title']) ?></option>
  <?php endforeach; ?></select></label>
  <button class="btn btn-secondary" type="submit">Filter</button>
</form>
<form method="post" class="support-actions">
  <?= csrf_input() ?>
  <button class="btn btn-secondary" name="action" value="preview">Preview next batch</button>
  <button class="btn btn-primary" name="action" value="enrich">Enrich next batch</button>
  <label><input type="checkbox" name="retry" value="1"> Retry unresolved items now</label>
</form>
<?php if ($error): ?><p role="alert" class="support-error"><?= h($error) ?></p><?php endif; ?>
<?php if ($result): ?>
  <p role="status"><?= ($_POST['action'] ?? '') === 'preview' ? 'Candidates found' : 'Images saved' ?>: <?= (int)$result['enriched'] ?>
  (SportsDataverse: <?= (int)$result['sportsdataverse'] ?>; Wikimedia: <?= (int)$result['wikimedia'] ?>).
  Unmatched: <?= (int)$result['unmatched'] ?>. Errors: <?= (int)$result['image_failures'] ?>. Pending: <?= (int)$result['remaining'] ?>.
  <?= $result['stopped'] ? 'Batch stopped by provider rate limit.' : '' ?></p>
  <?php foreach ($result['errors'] as $message): ?><p role="alert" class="support-error"><?= h($message) ?></p><?php endforeach; ?>
  <?php if ($result['results']): ?>
  <div class="support-table"><table data-paginate><thead><tr><th>Name</th><th>Type</th><th>Result</th><th>Source</th></tr></thead><tbody>
  <?php foreach ($result['results'] as $row): ?><tr><td><?= h($row['name']) ?></td><td><?= h($row['type']) ?></td><td><?= h($row['status']) ?></td><td><?= h($row['source']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
<?php endif; ?>
<h2>Image sources and unresolved matches</h2>
<p>Source and license information is retained below. Commons attribution requirements apply to the individual file; SportsDataverse logos remain the property of their owners.</p>
<div class="support-table"><table data-paginate><thead><tr><th>Image</th><th>Name</th><th>Status</th><th>Source / attribution</th><th>Last checked</th></tr></thead><tbody>
<?php foreach ($records as $record): $meta = json_decode($record['metadata'], true) ?: []; ?>
<tr>
  <td><?php if ($record['image_path']): ?><img src="<?= h($record['image_path']) ?>" alt="" width="48" height="48" loading="lazy" style="object-fit:contain;"><?php endif; ?></td>
  <td><a href="<?= $record['entity_type'] === 'participant' ? 'team-edit.php' : 'tournament-edit.php' ?>?id=<?= (int)$record['entity_id'] ?>"><?= h($record['name']) ?></a><br><?= h($record['entity_type']) ?></td>
  <td><?= h($record['status']) ?><?php if ($record['message']): ?><br><?= h($record['message']) ?><?php endif; ?></td>
  <td><?= h($record['source']) ?>
  <?php if (str_starts_with($meta['source_page'] ?? '', 'https://')): ?><br><a href="<?= h($meta['source_page']) ?>" target="_blank" rel="noopener noreferrer">Source file / details</a><?php endif; ?>
  <?php foreach (['provider_id','license','artist','credit','captured_at'] as $key): if (!empty($meta[$key])): ?><br><?= h($meta[$key]) ?><?php endif; endforeach; ?>
  <?php if (str_starts_with($meta['license_url'] ?? '', 'https://')): ?><br><a href="<?= h($meta['license_url']) ?>" target="_blank" rel="noopener noreferrer">License</a><?php endif; ?>
  </td><td><?= h(gmdate('Y-m-d H:i', (int)$record['checked_at'])) ?> UTC</td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
</main></body></html>
