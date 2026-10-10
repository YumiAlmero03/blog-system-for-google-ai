<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_auth();
require_once __DIR__ . '/../includes/sports-registry.php';

$id = (int) ($_GET['id'] ?? 0);
$participant = $id > 0 ? participant_get($id) : null;

if (!$participant) {
    http_response_code(404);
    exit('Participant not found.');
}

$cat = sports_category_get((int) $participant['sports_category_id']);
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = $_POST['action'] ?? 'save';

    try {
        if ($action === 'save') {
            $updated = participant_save([
                'id' => $id,
                'name' => $_POST['name'] ?? '',
                'slug' => $_POST['slug'] ?? '',
                'enabled' => isset($_POST['enabled']) ? 1 : 0,
            ]);

            $uploadFile = $_FILES['participant_image'] ?? ($_FILES['team_image'] ?? null);
            if ($uploadFile && ($uploadFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $updated = participant_save_manual_image($id, $uploadFile);
            }

            $participant = $updated;
            $notice = 'Participant updated successfully.';
            csrf_rotate();
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        $error = 'Update failed: ' . $e->getMessage();
    }
}

$title = 'Edit Participant — ' . $participant['name'];
require __DIR__ . '/../../../partials/support-head.php';
?>
<div style="max-width: 680px;">
  <p><a href="/admin/plugin/odds-api/registry/teams.php<?= $cat ? '?sport=' . h($cat['slug']) : '' ?>">← Back to Participants</a> · <a href="/admin/plugin/odds-api/registry/">Sports Registry</a></p>

  <?php if ($notice): ?><p role="status" style="padding:10px 14px;background:#e9f8ef;border:1px solid #9bd5af;color:#0d6630;border-radius:6px;"><?= h($notice) ?></p><?php endif; ?>
  <?php if ($error): ?><p role="alert" style="padding:10px 14px;background:#fff1f1;border:1px solid #e5aaaa;color:var(--danger);border-radius:6px;"><?= h($error) ?></p><?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="user-form" style="display:grid;gap:16px;">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="save">

    <p><label>Display Name<br><input name="name" required value="<?= h($participant['name']) ?>" maxlength="120" style="width:100%;box-sizing:border-box;"></label></p>
    <p><label>Slug<br><input name="slug" required value="<?= h($participant['slug']) ?>" maxlength="120" style="width:100%;box-sizing:border-box;"></label><br><small style="color:var(--text-muted);">Unique image key reference: <code><?= h($participant['image_key']) ?></code></small></p>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
      <p><label>The Odds API Identifier<br><input value="<?= h($participant['participant_id'] ?: $participant['slug']) ?>" readonly style="width:100%;box-sizing:border-box;background:var(--surface-soft);color:var(--text-muted);cursor:not-allowed;"></label></p>
      <p><label>Category<br><input value="<?= h($cat['name'] ?? '—') ?>" readonly style="width:100%;box-sizing:border-box;background:var(--surface-soft);color:var(--text-muted);cursor:not-allowed;"></label></p>
    </div>

    <p><label>Tournament(s)<br><input value="<?= h($participant['tournaments_titles'] ?: ($participant['odds_sport_key'] ?: '—')) ?>" readonly style="width:100%;box-sizing:border-box;background:var(--surface-soft);color:var(--text-muted);cursor:not-allowed;"></label></p>

    <p><label><input type="checkbox" name="enabled" value="1" <?= $participant['enabled'] ? 'checked' : '' ?>> Enabled</label></p>

    <!-- Participant Image Section -->
    <fieldset style="border:1px solid var(--border);border-radius:8px;padding:16px;">
      <legend style="padding:0 8px;font-weight:700;">Participant Logo (Source: <?= strtoupper($participant['image_source']) ?>)</legend>
      <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
        <div>
          <?php if (!empty($participant['image_path'])): ?>
            <?php $pImgUrl = sports_registry_safe_image_url($participant['image_path']) . ($participant['updated_at'] ? '?t=' . $participant['updated_at'] : ''); ?>
            <img src="<?= h($pImgUrl) ?>" alt="<?= h($participant['name']) ?>" style="max-width:100px;max-height:100px;object-fit:contain;background:#1e293b;border-radius:8px;padding:4px;border:1px solid var(--border);">
          <?php else: ?>
            <div style="width:80px;height:80px;border-radius:8px;background:var(--surface-soft);display:flex;align-items:center;justify-content:center;color:var(--text-muted);border:1px dashed var(--border);">No Image</div>
          <?php endif; ?>
        </div>
        <div>
          <label>Manual Upload Override<br><input type="file" name="participant_image" accept="image/jpeg,image/png,image/webp,image/avif"></label>
          <p style="font-size:0.8rem;color:var(--text-muted);margin:4px 0 0;">Uploading a manual image marks this participant as <strong>MANUAL</strong>, protecting it from being overwritten during future API syncs.</p>
          <?php if (!empty($participant['image_path'])): ?>
            <p style="font-size:0.75rem;color:var(--text-muted);margin:4px 0 0;word-break:break-all;">Local Path: <code><?= h($participant['image_path']) ?></code></p>
          <?php endif; ?>
        </div>
      </div>
    </fieldset>

    <div style="display:flex;gap:10px;margin-top:12px;">
      <button type="submit" class="btn btn-primary">Save Participant</button>
      <a href="/admin/plugin/odds-api/registry/teams.php<?= $cat ? '?sport=' . h($cat['slug']) : '' ?>" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
</main></body></html>
