<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/sports-registry.php';

$slug = trim((string) ($_GET['sport'] ?? ''));
$id = (int) ($_GET['id'] ?? 0);
if ($slug === '' && $id <= 0 && preg_match('#/(?:registry|sports)/([A-Za-z0-9_-]+)/registration#', (string) ($_SERVER['REQUEST_URI'] ?? ''), $uriMatches)) {
    $slug = $uriMatches[1];
}
$sport = $slug !== '' ? sports_category_get($slug) : ($id > 0 ? sports_category_get($id) : null);

if (!$sport) {
    http_response_code(404);
    exit('Sport not found.');
}

$sportId = (int) $sport['id'];
$notice = '';
$error = '';
$syncStats = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'sync_odds_participants') {
            $singleTournId = !empty($_POST['tournament_id']) ? (int) $_POST['tournament_id'] : null;
            if ($singleTournId) {
                $t = tournament_get($singleTournId);
                $res = sports_registry_sync_tournament_participants($t);
                $notice = sprintf("Tournament %s synchronized: %d participants found (%d new, %d updated).", $t['odds_sport_key'], $res['participants_found'], $res['new_participants'], $res['updated_participants']);
            } else {
                $tournaments = tournament_get_all($sportId, true);
                $found = 0; $new = 0; $up = 0;
                foreach ($tournaments as $t) {
                    try {
                        $r = sports_registry_sync_tournament_participants($t);
                        $found += $r['participants_found'];
                        $new += $r['new_participants'];
                        $up += $r['updated_participants'];
                    } catch (Throwable $e) {}
                }
                $notice = sprintf("Sync completed for %s: %d participants found (%d new, %d updated).", $sport['name'], $found, $new, $up);
            }
            csrf_rotate();
        } elseif ($action === 'enrich_logos') {
            $res = sports_registry_enrich_participant_images($sportId);
            $notice = sprintf("Enrichment completed: %d logos downloaded (%d failures, %d skipped manual).", $res['enriched'], $res['image_failures'], $res['skipped_manual']);
            csrf_rotate();
        } elseif ($action === 'enrich_tournament_logos') {
            $tId = (int) ($_POST['tournament_id'] ?? 0);
            $t = tournament_get($tId);
            if ($t) {
                $res = sports_registry_enrich_tournament_logos($t);
                $notice = sprintf(
                    'Tournament %s enriched: %d logos downloaded (%d failures, %d skipped manual, %d unmatched).',
                    $t['odds_sport_key'],
                    $res['enriched'],
                    $res['image_failures'],
                    $res['skipped_manual'],
                    $res['unmatched']
                );
                csrf_rotate();
            }
        } elseif ($action === 'create_tournament') {
            tournament_save([
                'sports_category_id' => $sportId,
                'odds_sport_key' => $_POST['odds_sport_key'] ?? '',
                'title' => $_POST['title'] ?? '',
                'description' => $_POST['description'] ?? '',
                'active' => isset($_POST['active']) ? 1 : 0,
                'has_outrights' => isset($_POST['has_outrights']) ? 1 : 0,
                'enabled' => isset($_POST['enabled']) ? 1 : 0,
            ]);
            $notice = 'Tournament added successfully.';
            csrf_rotate();
        } elseif ($action === 'delete_tournament') {
            $tId = (int) ($_POST['tournament_id'] ?? 0);
            tournament_delete($tId);
            $notice = 'Tournament removed.';
            csrf_rotate();
        } elseif ($action === 'toggle_tournament') {
            $tId = (int) ($_POST['tournament_id'] ?? 0);
            $t = tournament_get($tId);
            if ($t) {
                tournament_save([
                    'id' => $tId,
                    'sports_category_id' => $sportId,
                    'odds_sport_key' => $t['odds_sport_key'],
                    'title' => $t['title'],
                    'description' => $t['description'],
                    'active' => $t['active'],
                    'has_outrights' => $t['has_outrights'],
                    'enabled' => empty($t['enabled']) ? 1 : 0,
                ]);
                $notice = 'Tournament status updated.';
                csrf_rotate();
            }
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        $error = 'Operation failed: ' . $e->getMessage();
    }
}

$tournaments = tournament_get_all($sportId, false, false);
$participants = participant_get_all(['sports_category_id' => $sportId]);
$oddsKey = sports_odds_api_key_get();
$apiSportsKey = sports_api_sports_key_get();

$title = $sport['name'] . ' Tournaments';
require __DIR__ . '/../../../partials/support-head.php';
?>
<style>
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
.badge-on { background: #dcfce7; color: #166534; }
.badge-off { background: #fee2e2; color: #991b1b; }
.sport-banner { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 16px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; }
.sport-thumb-lg { width: 44px; height: 44px; border-radius: 8px; object-fit: contain; background: #1e293b; border: 1px solid var(--border); }
</style>

<div>
  <p><a href="/admin/plugin/odds-api/registry/">← Back to Sports Categories</a> · <a href="/admin/plugin/odds-api/registry/edit.php?id=<?= $sportId ?>">Edit Sports Category</a></p>

  <?php if ($notice): ?><p role="status" style="padding:10px 14px;background:#e9f8ef;border:1px solid #9bd5af;color:#0d6630;border-radius:6px;"><?= h($notice) ?></p><?php endif; ?>
  <?php if ($error): ?><p role="alert" style="padding:10px 14px;background:#fff1f1;border:1px solid #e5aaaa;color:var(--danger);border-radius:6px;"><?= h($error) ?></p><?php endif; ?>

  <!-- Sports Category Header Bar -->
  <div class="sport-banner">
    <div style="display:flex;align-items:center;gap:14px;">
      <?php if (!empty($sport['image_path'])): ?>
        <img src="<?= h($sport['image_path']) ?>" alt="<?= h($sport['name']) ?>" class="sport-thumb-lg">
      <?php endif; ?>
      <div>
        <span style="font-size:1.25rem;font-weight:700;display:block;"><?= h($sport['name']) ?> Tournaments</span>
        <span style="font-size:0.85rem;color:var(--text-muted);">Slug: <code><?= h($sport['slug']) ?></code> · Image Key: <code><?= h($sport['image_key']) ?></code> · Status: <span class="badge <?= $sport['enabled'] ? 'badge-on' : 'badge-off' ?>"><?= $sport['enabled'] ? 'Enabled' : 'Disabled' ?></span></span>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('add-tourn-modal').showModal()">+ Add Tournament Key</button>
      <form method="post" style="margin:0;">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="sync_odds_participants">
        <button type="submit" class="btn btn-primary btn-sm" <?= empty($tournaments) ? 'disabled' : '' ?>>👥 Sync Participants</button>
      </form>
      <form method="post" style="margin:0;">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="enrich_logos">
        <button type="submit" class="btn btn-secondary btn-sm" <?= empty($participants) ? 'disabled' : '' ?>>🖼️ Enrich Logos</button>
      </form>
    </div>
  </div>

  <!-- Tournaments Registry Table -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
    <span style="font-size:1.1rem;font-weight:700;">Tournaments Registry (<?= count($tournaments) ?>)</span>
    <span style="font-size:0.85rem;color:var(--text-muted);">The Odds API source of truth. Click any tournament to edit and manage participants.</span>
  </div>

  <div class="support-table">
    <table data-paginate data-pagination-label="<?= h($sport['name']) ?> Tournaments">
      <thead>
        <tr>
          <th>Odds Sport Key</th>
          <th>Title</th>
          <th>Description</th>
          <th>Participants</th>
          <th>Active</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$tournaments): ?>
          <tr><td colspan="7" style="text-align:center;padding:24px;color:var(--text-muted);">No tournaments registered for this category yet. Click "Sync Sports" in the main Sports Categories page to import from The Odds API.</td></tr>
        <?php endif; ?>
        <?php foreach ($tournaments as $t): ?>
          <tr>
            <td><code><strong><a href="/admin/plugin/odds-api/registry/tournaments/<?= (int) $t['id'] ?>/edit/"><?= h($t['odds_sport_key']) ?></a></strong></code></td>
            <td>
              <strong><a href="/admin/plugin/odds-api/registry/tournaments/<?= (int) $t['id'] ?>/edit/"><?= h($t['title']) ?></a></strong>
              <?php if (!empty($t['enrich_api_url'])): ?>
                <div style="font-size:0.75rem;color:var(--text-muted);font-family:monospace;" title="<?= h($t['enrich_api_url']) ?>">Enrich: <?= h($t['enrich_api_url']) ?></div>
              <?php endif; ?>
            </td>
            <td><small><?= h($t['description'] ?: '—') ?></small></td>
            <td><a href="/admin/plugin/odds-api/registry/tournaments/<?= (int) $t['id'] ?>/edit/#participants"><strong><?= (int) $t['participants_count'] ?> participant(s)</strong></a></td>
            <td><span class="badge <?= $t['active'] ? 'badge-on' : 'badge-off' ?>"><?= $t['active'] ? 'Active' : 'Inactive' ?></span></td>
            <td>
              <form method="post" style="margin:0;display:inline;">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="toggle_tournament">
                <input type="hidden" name="tournament_id" value="<?= (int) $t['id'] ?>">
                <button type="submit" class="badge <?= $t['enabled'] ? 'badge-on' : 'badge-off' ?>" style="cursor:pointer;border:none;">
                  <?= $t['enabled'] ? 'On' : 'Off' ?>
                </button>
              </form>
            </td>
            <td>
              <a class="btn btn-primary btn-sm" href="/admin/plugin/odds-api/registry/tournaments/<?= (int) $t['id'] ?>/edit/">Manage</a> ·
              <form method="post" style="display:inline;margin:0;">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="sync_odds_participants">
                <input type="hidden" name="tournament_id" value="<?= (int) $t['id'] ?>">
                <button type="submit" class="btn btn-secondary btn-sm">Sync</button>
              </form> ·
              <form method="post" style="display:inline;margin:0;">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="enrich_tournament_logos">
                <input type="hidden" name="tournament_id" value="<?= (int) $t['id'] ?>">
                <button type="submit" class="btn btn-secondary btn-sm" <?= empty($t['enrich_api_url']) ? 'title="Configure Enrich API URL in Edit"' : 'title="Enrich logos from API-Sports"' ?>>Enrich</button>
              </form> ·
              <form method="post" style="display:inline;margin:0;" onsubmit="return confirm('Remove tournament “<?= h($t['title']) ?>”?');">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="delete_tournament">
                <input type="hidden" name="tournament_id" value="<?= (int) $t['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm" style="padding:2px 6px;">✕</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: Add Tournament -->
<dialog id="add-tourn-modal" style="border:1px solid var(--border);border-radius:10px;padding:24px;background:var(--surface);color:inherit;max-width:500px;width:100%;">
  <form method="post">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="create_tournament">
    <p style="font-weight:700;font-size:1.1rem;margin:0 0 8px;">Add Tournament Key</p>
    <p><label>Odds Sport Key<br><input name="odds_sport_key" required maxlength="100" style="width:100%;box-sizing:border-box;" placeholder="e.g. basketball_nba, soccer_epl"></label></p>
    <p><label>Title<br><input name="title" required maxlength="120" style="width:100%;box-sizing:border-box;" placeholder="e.g. NBA, Premier League"></label></p>
    <p><label>Description (optional)<br><input name="description" maxlength="255" style="width:100%;box-sizing:border-box;" placeholder="e.g. US Basketball"></label></p>
    <p><label><input type="checkbox" name="active" value="1" checked> Active in API</label></p>
    <p><label><input type="checkbox" name="enabled" value="1" checked> Enabled</label></p>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:20px;">
      <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('add-tourn-modal').close()">Cancel</button>
      <button type="submit" class="btn btn-primary btn-sm">Save Tournament</button>
    </div>
  </form>
</dialog>
</main></body></html>
