<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/sports-registry.php';

$error = '';
$notice = '';
$syncDetails = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_keys') {
            if (($_SESSION['user']['role'] ?? '') !== 'super_user' && ($_SESSION['user']['role'] ?? '') !== 'admin') {
                throw new InvalidArgumentException('Insufficient permissions.');
            }
            if (isset($_POST['odds_api_key'])) {
                sports_odds_api_key_set((string) $_POST['odds_api_key']);
            }
            if (isset($_POST['api_sports_key'])) {
                sports_api_sports_key_set((string) $_POST['api_sports_key']);
            }
            $notice = 'API keys updated successfully.';
            csrf_rotate();
        } elseif ($action === 'sync_odds_sports') {
            $syncStats = sports_registry_sync_odds_sports();
            $notice = sprintf(
                'Synced sports from The Odds API: %d categories created, %d updated; %d tournaments created, %d updated.',
                $syncStats['categories_created'],
                $syncStats['categories_updated'],
                $syncStats['tournaments_created'],
                $syncStats['tournaments_updated']
            );
            $syncDetails = $syncStats;
            csrf_rotate();
        } elseif ($action === 'sync_all_participants') {
            $syncStats = sports_registry_sync_all_participants();
            $notice = sprintf(
                'Bulk participant sync completed: processed %d tournaments (%d failed). Found %d participants, %d new, %d updated, %d linked.',
                $syncStats['tournaments_processed'],
                $syncStats['tournaments_failed'],
                $syncStats['participants_found'],
                $syncStats['new_participants'],
                $syncStats['updated_participants'],
                $syncStats['linked_tournaments']
            );
            $syncDetails = $syncStats;
            csrf_rotate();
        } elseif ($action === 'enrich_logos') {
            $enrichStats = sports_registry_enrich_participant_images();
            $notice = sprintf(
                'API-Sports logo enrichment completed: enriched %d participants (%d failures, %d skipped manual).',
                $enrichStats['enriched'],
                $enrichStats['image_failures'],
                $enrichStats['skipped_manual']
            );
            $syncDetails = $enrichStats;
            csrf_rotate();
        } elseif ($action === 'create_category') {
            $created = sports_category_save([
                'name' => $_POST['name'] ?? '',
                'slug' => $_POST['slug'] ?? '',
                'enabled' => isset($_POST['enabled']) ? 1 : 0,
            ]);
            if (isset($_FILES['category_image']) && ($_FILES['category_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                sports_category_save_image((int) $created['id'], $_FILES['category_image']);
            }
            $notice = "Sports Category '{$created['name']}' created successfully.";
            csrf_rotate();
        } elseif ($action === 'toggle_category') {
            $id = (int) ($_POST['id'] ?? 0);
            $cat = sports_category_get($id);
            if ($cat) {
                sports_category_save([
                    'id' => $id,
                    'name' => $cat['name'],
                    'slug' => $cat['slug'],
                    'enabled' => empty($cat['enabled']) ? 1 : 0,
                ]);
                $notice = 'Category status updated.';
                csrf_rotate();
            }
        } elseif ($action === 'delete_category') {
            $id = (int) ($_POST['id'] ?? 0);
            if (sports_category_delete($id)) {
                $notice = "Category deleted.";
                csrf_rotate();
            } else {
                $error = "Failed to delete category.";
            }
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        $error = "Operation failed: " . $e->getMessage();
    }
}

$categories = sports_category_get_all();
$totalParticipants = (int) sports_registry_pdo()->query('SELECT COUNT(*) FROM participants')->fetchColumn();

$oddsKey = sports_odds_api_key_get();
$apiSportsKey = sports_api_sports_key_get();

$title = 'Sports Registry';
require __DIR__ . '/../../../partials/support-head.php';
?>
<style>
.sports-grid { display: grid; gap: 24px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
.badge-on { background: #dcfce7; color: #166534; }
.badge-off { background: #fee2e2; color: #991b1b; }
.badge-key { background: #f1f5f9; color: #475569; font-family: monospace; }
.sport-thumb { width: 36px; height: 36px; border-radius: 6px; object-fit: contain; background: #1e293b; vertical-align: middle; border: 1px solid var(--border); }
.sport-thumb-empty { width: 36px; height: 36px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; background: var(--surface-soft); color: var(--text-muted); font-size: 0.75rem; border: 1px dashed var(--border); }
.sports-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 18px; margin-bottom: 24px; }
.flex-between { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
.actions-bar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
</style>

<div class="sports-grid">
  <div>
    <div class="flex-between" style="margin-bottom: 16px;">
      <div>
        <a href="/admin/settings.php">← Back to Settings</a> ·
        <a href="/admin/plugin/odds-api/sports/">Odds API Layouts</a>
      </div>
      <div class="actions-bar">
        <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('keys-modal').showModal()">🔑 API Keys (Odds: <?= $oddsKey !== '' ? 'OK' : 'Missing' ?> · API-Sports: <?= $apiSportsKey !== '' ? 'OK' : 'Optional' ?>)</button>

        <form method="post" style="margin:0;display:inline;">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="sync_odds_sports">
          <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Fetch all sports and competitions from The Odds API (/v4/sports/)?');">🔄 Sync Sports</button>
        </form>

        <form method="post" style="margin:0;display:inline;">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="sync_all_participants">
          <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('Sync participants for all enabled tournaments from The Odds API?');">👥 Sync All Participants</button>
        </form>

        <a class="btn btn-secondary btn-sm" href="/admin/plugin/odds-api/registry/images.php">🖼️ Enrich Logos</a>

        <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('add-cat-modal').showModal()">+ Add Category</button>
      </div>
    </div>

    <?php if ($notice): ?><p role="status" style="padding:10px 14px;background:#e9f8ef;border:1px solid #9bd5af;color:#0d6630;border-radius:6px;"><?= h($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p role="alert" style="padding:10px 14px;background:#fff1f1;border:1px solid #e5aaaa;color:var(--danger);border-radius:6px;"><?= h($error) ?></p><?php endif; ?>

    <!-- SECTION 1: Sports Categories -->
    <div class="sports-card">
      <div class="flex-between" style="margin-bottom:12px;">
        <span style="font-size:1.15rem;font-weight:700;">Sports Categories (<?= count($categories) ?>)</span>
        <span style="font-size:0.85rem;color:var(--text-muted);">Organized by The Odds API <code>group</code>. Category images use <code>{slug}_sports</code> reference.</span>
      </div>
      <div class="support-table">
        <table data-paginate data-pagination-label="Sports Categories">
          <thead>
            <tr>
              <th>Image</th>
              <th>Name</th>
              <th>Slug</th>
              <th>Image Key</th>
              <th>Tournaments</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$categories): ?>
              <tr><td colspan="7" style="text-align:center;padding:24px;color:var(--text-muted);">No categories registered yet. Click "Sync Sports" above to import from The Odds API.</td></tr>
            <?php endif; ?>
            <?php foreach ($categories as $cat): ?>
              <tr>
                <td>
                  <?php if (!empty($cat['image_path'])): ?>
                    <img src="<?= h($cat['image_path']) ?>" alt="<?= h($cat['name']) ?>" class="sport-thumb">
                  <?php else: ?>
                    <span class="sport-thumb-empty">—</span>
                  <?php endif; ?>
                </td>
                <td><strong><a href="/admin/plugin/odds-api/registry/edit.php?id=<?= (int) $cat['id'] ?>"><?= h($cat['name']) ?></a></strong></td>
                <td><code><?= h($cat['slug']) ?></code></td>
                <td><code><?= h($cat['image_key']) ?></code></td>
                <td><a href="/admin/plugin/odds-api/registry/registration.php?sport=<?= h($cat['slug']) ?>"><strong><?= (int) $cat['tournaments_count'] ?> tournament(s)</strong></a></td>
                <td>
                  <form method="post" style="margin:0;display:inline;">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="toggle_category">
                    <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
                    <button type="submit" class="badge <?= $cat['enabled'] ? 'badge-on' : 'badge-off' ?>" style="cursor:pointer;border:none;">
                      <?= $cat['enabled'] ? 'On' : 'Off' ?>
                    </button>
                  </form>
                </td>
                <td>
                  <a class="btn btn-secondary btn-sm" href="/admin/plugin/odds-api/registry/registration.php?sport=<?= h($cat['slug']) ?>">Tournaments</a> ·
                  <a class="btn btn-secondary btn-sm" href="/admin/plugin/odds-api/registry/edit.php?id=<?= (int) $cat['id'] ?>">Edit</a>
                  <form method="post" style="display:inline;" onsubmit="return confirm('Delete category “<?= h($cat['name']) ?>” and its tournaments?');">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="delete_category">
                    <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm" style="padding:2px 6px;">✕</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<!-- Modal: API Keys -->
<dialog id="keys-modal" style="border:1px solid var(--border);border-radius:10px;padding:24px;background:var(--surface);color:inherit;max-width:540px;width:100%;">
  <form method="post">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="save_keys">
    <p style="font-weight:700;font-size:1.1rem;margin:0 0 8px;">API Configuration</p>
    <p style="font-size:0.85rem;color:var(--text-muted);">Stored securely server-side in SQLite. Never exposed publicly or to client browsers.</p>
    <p><label><strong>The Odds API Key</strong> (Primary Source)<br><input type="password" name="odds_api_key" value="<?= h($oddsKey) ?>" maxlength="256" style="width:100%;box-sizing:border-box;" placeholder="Enter The Odds API key"></label><br><small style="color:var(--text-muted);">Used for /v4/sports/ and /v4/sports/{sport}/participants.</small></p>
    <p><label><strong>API-Sports.io Key</strong> (Secondary Image Enrichment Only)<br><input type="password" name="api_sports_key" value="<?= h($apiSportsKey) ?>" maxlength="256" style="width:100%;box-sizing:border-box;" placeholder="Enter API-Sports.io key (optional)"></label><br><small style="color:var(--text-muted);">Used only to fetch club badges/logos for existing participants.</small></p>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:20px;">
      <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('keys-modal').close()">Cancel</button>
      <button type="submit" class="btn btn-primary btn-sm">Save Keys</button>
    </div>
  </form>
</dialog>

<!-- Modal: Add Category -->
<dialog id="add-cat-modal" style="border:1px solid var(--border);border-radius:10px;padding:24px;background:var(--surface);color:inherit;max-width:500px;width:100%;">
  <form method="post" enctype="multipart/form-data">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="create_category">
    <p style="font-weight:700;font-size:1.1rem;margin:0 0 8px;">Register Sports Category</p>
    <p><label>Name<br><input name="name" required maxlength="100" style="width:100%;box-sizing:border-box;" placeholder="e.g. Basketball, Soccer"></label></p>
    <p><label>Slug (optional)<br><input name="slug" maxlength="100" style="width:100%;box-sizing:border-box;" placeholder="e.g. basketball"></label><br><small style="color:var(--text-muted);">Stable image key will be <code>{slug}_sports</code>.</small></p>
    <p><label>Category Image (manual upload)<br><input type="file" name="category_image" accept="image/jpeg,image/png,image/webp,image/avif"></label></p>
    <p><label><input type="checkbox" name="enabled" value="1" checked> Enabled</label></p>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:20px;">
      <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('add-cat-modal').close()">Cancel</button>
      <button type="submit" class="btn btn-primary btn-sm">Save Category</button>
    </div>
  </form>
</dialog>
</main></body></html>
