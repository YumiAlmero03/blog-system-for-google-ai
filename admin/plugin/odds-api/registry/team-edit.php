<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_admin();
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
        if ($action === 'remove_image') {
            $participant = participant_remove_image($id);
            $notice = 'Team logo removed.';
            csrf_rotate();
        } elseif ($action === 'save') {
            $updated = participant_save([
                'id' => $id,
                'name' => $_POST['name'] ?? '',
                'slug' => $_POST['slug'] ?? '',
                'enabled' => isset($_POST['enabled']) ? 1 : 0,
            ]);

            $uploadFile = $_FILES['participant_image'] ?? ($_FILES['team_image'] ?? null);
            if ($uploadFile && ($uploadFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $updated = participant_save_manual_image($id, $uploadFile);
            } elseif (isset($_POST['logo_url']) && $_POST['logo_url'] !== $participant['image_path']) {
                $updated = $_POST['logo_url'] === '' ? participant_remove_image($id) : participant_save_manual_image_url($id, $_POST['logo_url']);
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

    <div id="logo">
    <?php
    $logoLabel = 'Team Logo'; $logoPath = $participant['image_path']; $logoSource = $participant['image_source'];
    $logoUploadName = 'participant_image'; $logoRemoveAction = 'remove_image';
    require __DIR__ . '/../includes/logo-fields.php';
    ?>
    </div>

    <div style="display:flex;gap:10px;margin-top:12px;">
      <button type="submit" class="btn btn-primary">Save Participant</button>
      <a href="/admin/plugin/odds-api/registry/teams.php<?= $cat ? '?sport=' . h($cat['slug']) : '' ?>" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<script src="/admin/plugin/odds-api/registry/logo-editor.js" defer></script>
</main></body></html>
