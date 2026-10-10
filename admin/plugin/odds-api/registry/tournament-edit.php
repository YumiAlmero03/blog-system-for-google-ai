<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/sports-registry.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0 && preg_match('#/tournaments/([0-9]+)#', (string) ($_SERVER['REQUEST_URI'] ?? ''), $uriMatches)) {
    $id = (int) $uriMatches[1];
}
$tournament = $id > 0 ? tournament_get($id) : null;

if (!$tournament) {
    http_response_code(404);
    exit('Tournament not found.');
}

$id = (int) $tournament['id'];
$categories = sports_category_get_all();
$notice = '';
$error = '';
$syncResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = $_POST['action'] ?? 'save';

    try {
        if ($action === 'save') {
            $oddsSportKey = trim((string) ($_POST['odds_sport_key'] ?? ''));
            $title = trim((string) ($_POST['title'] ?? ''));
            $catId = (int) ($_POST['sports_category_id'] ?? 0);

            if ($oddsSportKey === '') {
                throw new InvalidArgumentException('Odds Sport Key cannot be empty.');
            }
            if (!preg_match('/^[a-z0-9_]{1,100}$/D', $oddsSportKey)) {
                throw new InvalidArgumentException('Odds Sport Key must contain only lowercase letters, numbers, and underscores with no spaces (e.g. basketball_nba).');
            }
            if ($title === '') {
                throw new InvalidArgumentException('Display Title cannot be empty.');
            }
            if ($catId <= 0) {
                throw new InvalidArgumentException('Please select a valid Sports Category.');
            }

            $updated = tournament_save([
                'id' => $id,
                'sports_category_id' => $catId,
                'odds_sport_key' => $oddsSportKey,
                'title' => $title,
                'description' => trim((string) ($_POST['description'] ?? '')),
                'active' => isset($_POST['active']) ? 1 : 0,
                'has_outrights' => isset($_POST['has_outrights']) ? 1 : 0,
                'enabled' => isset($_POST['enabled']) ? 1 : 0,
                'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                'enrich_api_url' => trim((string) ($_POST['enrich_api_url'] ?? '')),
            ]);

            $tournament = $updated;
            $notice = 'Tournament updated successfully.';
            csrf_rotate();
        } elseif ($action === 'sync_participants') {
            $syncResult = sports_registry_sync_tournament_participants($id);
            $tournament = tournament_get($id);
            $notice = sprintf(
                'Tournament: %s | Sport Key: %s | Participants returned: %d (New: %d, Updated: %d, Failed: %d)',
                $tournament['title'],
                $tournament['odds_sport_key'],
                $syncResult['participants_found'],
                $syncResult['new_participants'],
                $syncResult['updated_participants'],
                $syncResult['failed']
            );
            csrf_rotate();
        } elseif ($action === 'enrich_logos') {
            $enrichResult = sports_registry_enrich_tournament_logos($id);
            $tournament = tournament_get($id);
            $notice = sprintf(
                'Tournament: %s | Enriched: %d participant logo(s) (%d image failures, %d skipped manual, %d unmatched).',
                $tournament['title'],
                $enrichResult['enriched'],
                $enrichResult['image_failures'],
                $enrichResult['skipped_manual'],
                $enrichResult['unmatched']
            );
            csrf_rotate();
        } elseif ($action === 'save_participant' || $action === 'upload_participant_image') {
            $pId = (int) ($_POST['participant_id'] ?? 0);
            if ($pId <= 0) {
                throw new InvalidArgumentException('Participant ID required.');
            }
            $name = trim((string) ($_POST['name'] ?? ''));
            $slug = trim((string) ($_POST['slug'] ?? ''));
            $hasName = $name !== '';

            if ($hasName) {
                $enabled = isset($_POST['enabled']) ? 1 : 0;
                $savedP = participant_save([
                    'id' => $pId,
                    'name' => $name,
                    'slug' => $slug,
                    'enabled' => $enabled,
                ]);
            } else {
                $savedP = participant_get($pId);
                if (!$savedP) {
                    throw new InvalidArgumentException('Participant not found.');
                }
            }

            $file = $_FILES['participant_image'] ?? null;
            if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $savedP = participant_save_manual_image($pId, $file);
                $notice = sprintf("Participant '%s' and logo updated successfully (Manual override).", $savedP['name']);
            } elseif ($hasName) {
                $notice = sprintf("Participant '%s' updated successfully.", $savedP['name']);
            } elseif ($action === 'upload_participant_image') {
                throw new InvalidArgumentException('Please select an image file to upload.');
            }
            csrf_rotate();
        } elseif ($action === 'remove_participant_image') {
            $pId = (int) ($_POST['participant_id'] ?? 0);
            if ($pId <= 0) {
                throw new InvalidArgumentException('Participant ID required.');
            }
            $p = participant_remove_image($pId);
            $notice = sprintf("Logo for '%s' removed.", $p['name']);
            csrf_rotate();
        } elseif ($action === 'toggle_participant') {
            $pId = (int) ($_POST['participant_id'] ?? 0);
            $p = participant_get($pId);
            if ($p) {
                participant_save([
                    'id' => $pId,
                    'enabled' => empty($p['enabled']) ? 1 : 0,
                ]);
                $notice = sprintf("Status updated for '%s'.", $p['name']);
                csrf_rotate();
            }
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        $error = 'Operation failed: ' . $e->getMessage();
    }
}

$safeEndpoint = '/v4/sports/' . rawurlencode($tournament['odds_sport_key']) . '/participants';
$lastSyncTime = !empty($tournament['last_sync_at']) ? date('M j, Y H:i:s', (int)$tournament['last_sync_at']) . ' UTC' : 'Never';
$lastSyncStatus = $tournament['last_sync_status'] ?: 'Not Synced';

$search = trim((string) ($_GET['q'] ?? ''));
$participants = participant_get_all([
    'tournament_id' => $id,
    'search' => $search !== '' ? $search : null,
]);

$title = 'Tournament — ' . $tournament['title'];
require __DIR__ . '/../../../partials/support-head.php';
?>
<style>
.diag-box { background: var(--surface-soft); border: 1px solid var(--border); border-radius: 8px; padding: 18px; margin-bottom: 24px; }
.diag-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
.diag-row:last-child { border-bottom: none; }
.diag-label { font-weight: 600; color: var(--text-muted); }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
.badge-success, .badge-on { background: #dcfce7; color: #166534; }
.badge-failed, .badge-off { background: #fee2e2; color: #991b1b; }
.badge-neutral { background: #f1f5f9; color: #475569; }
.badge-manual { background: #fef3c7; color: #92400e; }
.badge-api { background: #e0f2fe; color: #0369a1; }
.badge-odds { background: #ede9fe; color: #5b21b6; }
.team-thumb { width: 38px; height: 38px; border-radius: 6px; object-fit: contain; background: #1e293b; vertical-align: middle; border: 1px solid var(--border); padding: 2px; }
.team-thumb-empty { width: 38px; height: 38px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; background: var(--surface-soft); color: var(--text-muted); font-size: 0.72rem; border: 1px dashed var(--border); }
.flex-between { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
.panel-card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 24px; }
</style>

<div style="max-width: 1080px;">
  <p>
    <a href="/admin/plugin/odds-api/registry/">Sports Categories</a> &gt;
    <a href="/admin/plugin/odds-api/registry/registration.php?sport=<?= h($tournament['category_slug']) ?>"><?= h($tournament['category_name']) ?> Tournaments</a> &gt;
    <span><?= h($tournament['title']) ?></span>
  </p>

  <span style="font-size:1.35rem;font-weight:700;display:block;margin-bottom:14px;"><?= h($tournament['title']) ?> (<code><?= h($tournament['odds_sport_key']) ?></code>)</span>

  <?php if ($notice): ?><p role="status" style="padding:10px 14px;background:#e9f8ef;border:1px solid #9bd5af;color:#0d6630;border-radius:6px;"><?= h($notice) ?></p><?php endif; ?>
  <?php if ($error): ?><p role="alert" style="padding:10px 14px;background:#fff1f1;border:1px solid #e5aaaa;color:var(--danger);border-radius:6px;"><?= h($error) ?></p><?php endif; ?>

  <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:20px;margin-bottom:24px;">
    <!-- Diagnostic Information Panel -->
    <div class="diag-box" style="margin-bottom:0;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
        <span style="font-size:1.05rem;font-weight:700;">Diagnostic Information</span>
        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
          <form method="post" style="margin:0;">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="sync_participants">
            <button type="submit" class="btn btn-primary btn-sm">👥 Sync Participants</button>
          </form>
          <form method="post" style="margin:0;">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="enrich_logos">
            <button type="submit" class="btn btn-secondary btn-sm" <?= empty($tournament['enrich_api_url']) ? 'title="Configure Enrich API URL in tournament settings"' : 'title="Fetch logos via API-Sports"' ?>>🖼️ Enrich Logos</button>
          </form>
        </div>
      </div>

      <div class="diag-row">
        <span class="diag-label">Odds Sport Key:</span>
        <code><strong><?= h($tournament['odds_sport_key']) ?></strong></code>
      </div>
      <div class="diag-row">
        <span class="diag-label">Participants Endpoint:</span>
        <code><?= h($safeEndpoint) ?></code>
      </div>
      <div class="diag-row">
        <span class="diag-label">Enrich API URL:</span>
        <code><?= !empty($tournament['enrich_api_url']) ? h($tournament['enrich_api_url']) : '<em style="color:var(--text-muted);">(not configured)</em>' ?></code>
      </div>
      <div class="diag-row">
        <span class="diag-label">The Odds API Source Title:</span>
        <span><?= h($tournament['source_title'] ?: $tournament['title']) ?></span>
      </div>
      <div class="diag-row">
        <span class="diag-label">Last Sync:</span>
        <span><?= h($lastSyncTime) ?></span>
      </div>
      <div class="diag-row">
        <span class="diag-label">Last Sync Status:</span>
        <span>
          <?php if ($lastSyncStatus === 'Success'): ?>
            <span class="badge badge-success">Success</span>
          <?php elseif ($lastSyncStatus === 'Failed'): ?>
            <span class="badge badge-failed">Failed</span>
          <?php else: ?>
            <span class="badge badge-neutral"><?= h($lastSyncStatus) ?></span>
          <?php endif; ?>
          <?php if (!empty($tournament['last_sync_message'])): ?>
            <small style="color:var(--text-muted);margin-left:6px;">(<?= h($tournament['last_sync_message']) ?>)</small>
          <?php endif; ?>
        </span>
      </div>
      <div class="diag-row">
        <span class="diag-label">Participants Count:</span>
        <span><strong><?= count($participants) ?></strong> participant(s)</span>
      </div>
    </div>

    <!-- Edit Tournament Settings Card -->
    <div class="panel-card" style="margin-bottom:0;">
      <span style="font-size:1.05rem;font-weight:700;display:block;margin-bottom:12px;">Tournament Configuration</span>
      <form method="post" class="user-form" style="display:grid;gap:12px;">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="save">

        <div>
          <label>Display Title (Presentation Override)<br>
          <input name="title" required value="<?= h($tournament['title']) ?>" maxlength="120" style="width:100%;box-sizing:border-box;">
          </label>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div>
            <label>Sports Category<br>
            <select name="sports_category_id" required style="width:100%;box-sizing:border-box;">
              <?php foreach ($categories as $cat): ?>
                <option value="<?= (int)$cat['id'] ?>" <?= (int)$cat['id'] === (int)$tournament['sports_category_id'] ? 'selected' : '' ?>>
                  <?= h($cat['name']) ?> (<?= h($cat['slug']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
            </label>
          </div>
          <div>
            <label>Odds Sport Key<br>
            <input name="odds_sport_key" required value="<?= h($tournament['odds_sport_key']) ?>" maxlength="100" style="width:100%;box-sizing:border-box;">
            </label>
          </div>
        </div>

        <div>
          <label>Enrich Logos API URL (API-Sports)<br>
          <input name="enrich_api_url" value="<?= h($tournament['enrich_api_url'] ?? '') ?>" placeholder="https://v2.nba.api-sports.io/teams? or https://v3.football.api-sports.io/teams?league=39&season=2024" style="width:100%;box-sizing:border-box;">
          </label>
          <small style="color:var(--text-muted);">API-Sports URL for team badges (e.g. <code>https://v2.nba.api-sports.io/teams?</code> or <code>https://v1.basketball.api-sports.io/teams</code>).</small>
        </div>

        <div>
          <label>Description (optional)<br>
          <input name="description" value="<?= h($tournament['description']) ?>" maxlength="255" style="width:100%;box-sizing:border-box;">
          </label>
        </div>

        <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;">
          <label>
            <input type="checkbox" name="enabled" value="1" <?= $tournament['enabled'] ? 'checked' : '' ?>>
            <strong>Enabled</strong>
          </label>
          <label>
            <input type="checkbox" name="active" value="1" <?= $tournament['active'] ? 'checked' : '' ?>>
            Active in API
          </label>
          <label>
            <input type="checkbox" name="has_outrights" value="1" <?= $tournament['has_outrights'] ? 'checked' : '' ?>>
            Outrights
          </label>
        </div>

        <div style="display:flex;gap:8px;margin-top:8px;">
          <button type="submit" class="btn btn-primary btn-sm">Save Tournament Settings</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Tournament Participants List with Image Editor -->
  <div class="panel-card" id="participants">
    <div class="flex-between" style="margin-bottom:14px;">
      <div>
        <span style="font-size:1.15rem;font-weight:700;">Tournament Participants (<?= count($participants) ?>)</span>
        <div style="font-size:0.85rem;color:var(--text-muted);margin-top:2px;">
          Enriched logos from API-Sports and manual overrides are viewable below. Click any logo or "Edit Logo" to preview or upload a replacement.
        </div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <form method="get" style="margin:0;display:flex;gap:6px;align-items:center;">
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="search" name="q" value="<?= h($search) ?>" placeholder="Search participant..." style="padding:4px 8px;font-size:0.85rem;">
          <button type="submit" class="btn btn-secondary btn-sm">Search</button>
          <?php if ($search !== ''): ?>
            <a href="/admin/plugin/odds-api/registry/tournaments/<?= $id ?>/edit/#participants" class="btn btn-secondary btn-sm">Reset</a>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <div class="support-table">
      <table data-paginate data-pagination-label="Tournament Participants">
        <thead>
          <tr>
            <th style="width:50px;">Logo</th>
            <th>Participant Name</th>
            <th>Identifier</th>
            <th>Image Key</th>
            <th>Source</th>
            <th>Status</th>
            <th>Image Editor</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$participants): ?>
            <tr>
              <td colspan="7" style="text-align:center;padding:24px;color:var(--text-muted);">
                No participants registered for this tournament yet. Click "Sync Participants" above to import from The Odds API.
              </td>
            </tr>
          <?php endif; ?>
          <?php foreach ($participants as $p): ?>
            <?php
              $rawImg = !empty($p['image_path']) ? sports_registry_safe_image_url($p['image_path']) : '';
              $imgUrl = $rawImg !== '' ? $rawImg . ($p['updated_at'] ? '?t=' . $p['updated_at'] : '') : '';
              $pSource = strtoupper($p['image_source'] ?: 'NONE');
              $badgeClass = match ($p['image_source']) {
                  'manual' => 'badge-manual',
                  'api_sports' => 'badge-api',
                  'odds_api' => 'badge-odds',
                  default => 'badge-neutral',
              };
              $pJsonArgs = sprintf(
                  '%d, %s, %s, %s, %s, %s, %s, %d',
                  (int)$p['id'],
                  json_encode($p['name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
                  json_encode($p['slug'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
                  json_encode($imgUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
                  json_encode($pSource, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
                  json_encode($p['image_key'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
                  json_encode($p['image_path'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
                  (int)$p['enabled']
              );
            ?>
            <tr>
              <td>
                <?php if ($imgUrl !== ''): ?>
                  <a href="javascript:void(0)" onclick="openImageModal(<?= $pJsonArgs ?>)" title="Click to view/edit logo">
                    <img src="<?= h($imgUrl) ?>" alt="<?= h($p['name']) ?>" class="team-thumb">
                  </a>
                <?php else: ?>
                  <a href="javascript:void(0)" onclick="openImageModal(<?= $pJsonArgs ?>)" title="Click to upload logo">
                    <span class="team-thumb-empty"><?= $p['image_failure'] ? '⚠️' : 'No Logo' ?></span>
                  </a>
                <?php endif; ?>
              </td>
              <td>
                <strong>
                  <a href="javascript:void(0)" onclick="openImageModal(<?= $pJsonArgs ?>)">
                    <?= h($p['name']) ?>
                  </a>
                </strong>
              </td>
              <td><code><?= h($p['participant_id'] ?: $p['slug']) ?></code></td>
              <td><code><small><?= h($p['image_key']) ?></small></code></td>
              <td>
                <span class="badge <?= $badgeClass ?>"><?= $pSource ?></span>
              </td>
              <td>
                <form method="post" style="margin:0;display:inline;">
                  <?= csrf_input() ?>
                  <input type="hidden" name="action" value="toggle_participant">
                  <input type="hidden" name="participant_id" value="<?= (int) $p['id'] ?>">
                  <button type="submit" class="badge <?= $p['enabled'] ? 'badge-on' : 'badge-off' ?>" style="cursor:pointer;border:none;">
                    <?= $p['enabled'] ? 'On' : 'Off' ?>
                  </button>
                </form>
              </td>
              <td>
                <button type="button" class="btn btn-secondary btn-sm" onclick="openImageModal(<?= $pJsonArgs ?>)">
                  ✏️ Edit
                </button> ·
                <a class="btn btn-secondary btn-sm" href="/admin/plugin/odds-api/registry/team-edit.php?id=<?= (int)$p['id'] ?>">Details</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal: Participant Editor -->
<dialog id="image-modal" style="border:1px solid var(--border);border-radius:10px;padding:24px;background:var(--surface);color:inherit;max-width:580px;width:100%;">
  <div>
    <div class="flex-between" style="margin-bottom:14px;">
      <span style="font-weight:700;font-size:1.15rem;margin:0;" id="modal-title">Edit Participant & Logo</span>
      <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('image-modal').close()">✕</button>
    </div>

    <!-- Active Logo Preview Box -->
    <div style="background:#0f172a;border-radius:8px;padding:16px;text-align:center;border:1px solid var(--border);margin-bottom:16px;">
      <div id="modal-img-container" style="display:flex;align-items:center;justify-content:center;min-height:120px;">
        <img id="modal-preview-img" src="" alt="Participant Logo" style="max-height:110px;max-width:180px;object-fit:contain;display:none;">
        <div id="modal-preview-empty" style="color:#94a3b8;font-size:0.85rem;display:none;">
          <span>No logo currently assigned.</span><br>
          <small>Upload a custom logo below or run Enrich Logos.</small>
        </div>
      </div>
      <div style="margin-top:8px;font-size:0.8rem;color:#cbd5e1;">
        <span id="modal-source-badge" class="badge"></span>
        <code id="modal-path-display" style="margin-left:8px;word-break:break-all;color:#93c5fd;"></code>
      </div>
    </div>

    <!-- Edit Form -->
    <form method="post" enctype="multipart/form-data" style="margin-bottom:12px;">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="save_participant">
      <input type="hidden" name="participant_id" id="modal-pid-upload" value="">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
        <div>
          <label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:4px;">Participant Name</label>
          <input type="text" name="name" id="modal-pname-input" required maxlength="120" style="width:100%;box-sizing:border-box;padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--surface);">
        </div>
        <div>
          <label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:4px;">Slug</label>
          <input type="text" name="slug" id="modal-slug-input" required maxlength="120" style="width:100%;box-sizing:border-box;padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--surface);">
        </div>
      </div>

      <div style="background:var(--surface-soft);border:1px solid var(--border);border-radius:6px;padding:8px 12px;margin-bottom:12px;font-size:0.8rem;">
        <div><strong>Image Key:</strong> <code id="modal-key-display"></code></div>
        <div><strong>Tournament:</strong> <?= h($tournament['title']) ?> (<code><?= h($tournament['odds_sport_key']) ?></code>)</div>
      </div>

      <div style="margin-bottom:12px;">
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;cursor:pointer;">
          <input type="checkbox" name="enabled" id="modal-enabled-input" value="1">
          <span>Enabled in Odds / Layout</span>
        </label>
      </div>

      <div style="border:1px dashed var(--border);border-radius:8px;padding:12px;margin-bottom:14px;background:rgba(255,255,255,0.02);">
        <label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:4px;">
          Upload / Replace Logo (Optional)
        </label>
        <input type="file" name="participant_image" accept="image/jpeg,image/png,image/webp,image/avif" style="margin-top:2px;font-size:0.85rem;">
        <p style="font-size:0.75rem;color:var(--text-muted);margin:4px 0 0;">
          Manual uploads are saved locally and marked as <strong>MANUAL</strong> to prevent automated API enrichments from overwriting them.
        </p>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
        <button type="submit" class="btn btn-primary btn-sm">Save Participant & Logo</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('image-modal').close()">Cancel</button>
      </div>
    </form>

    <div style="display:flex;justify-content:flex-end;align-items:center;margin-top:8px;padding-top:10px;border-top:1px solid var(--border);">
      <form method="post" style="margin:0;" onsubmit="return confirm('Remove logo for this participant?');">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="remove_participant_image">
        <input type="hidden" name="participant_id" id="modal-pid-remove" value="">
        <button type="submit" class="btn btn-secondary btn-sm" id="modal-remove-btn" style="color:var(--danger);">Remove Logo</button>
      </form>
    </div>
  </div>
</dialog>

<script>
function openImageModal(id, name, slug, imgUrl, source, key, path, enabled) {
  document.getElementById('modal-pid-upload').value = id;
  document.getElementById('modal-pid-remove').value = id;
  document.getElementById('modal-pname-input').value = name;
  document.getElementById('modal-slug-input').value = slug;
  document.getElementById('modal-key-display').textContent = key;
  document.getElementById('modal-path-display').textContent = path || '';
  document.getElementById('modal-enabled-input').checked = (enabled == 1 || enabled === true);

  var sourceBadge = document.getElementById('modal-source-badge');
  sourceBadge.textContent = source || 'NONE';
  sourceBadge.className = 'badge ' + (source === 'MANUAL' ? 'badge-manual' : (source === 'API_SPORTS' ? 'badge-api' : 'badge-neutral'));

  var previewImg = document.getElementById('modal-preview-img');
  var previewEmpty = document.getElementById('modal-preview-empty');
  var removeBtn = document.getElementById('modal-remove-btn');

  if (imgUrl && imgUrl.trim() !== '') {
    previewImg.src = imgUrl;
    previewImg.style.display = 'block';
    previewEmpty.style.display = 'none';
    removeBtn.style.display = 'inline-block';
  } else {
    previewImg.src = '';
    previewImg.style.display = 'none';
    previewEmpty.style.display = 'block';
    removeBtn.style.display = 'none';
  }

  document.getElementById('image-modal').showModal();
}
</script>
</main></body></html>
