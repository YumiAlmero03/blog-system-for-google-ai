<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/sports-registry.php';

$id = (int) ($_GET['id'] ?? 0);
$slug = (string) ($_GET['sport'] ?? '');
if ($id <= 0 && $slug === '' && preg_match('#/(?:registry|sports)/([A-Za-z0-9_-]+)/edit#', (string) ($_SERVER['REQUEST_URI'] ?? ''), $uriMatches)) {
    $slug = $uriMatches[1];
}
$sport = $id > 0 ? sports_registry_get($id) : ($slug !== '' ? sports_registry_get($slug) : null);

if (!$sport) {
    http_response_code(404);
    exit('Sport not found.');
}

$id = (int) $sport['id'];
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = $_POST['action'] ?? 'save';

    try {
        if ($action === 'remove_image') {
            sports_registry_remove_sport_image($id);
            $sport = sports_registry_get($id);
            $notice = 'Sport image removed.';
            csrf_rotate();
        } elseif ($action === 'save') {
            $updated = sports_registry_save([
                'id' => $id,
                'name' => $_POST['name'] ?? '',
                'slug' => $_POST['slug'] ?? '',
                'api_sport_id' => $_POST['api_sport_id'] ?? '',
                'api_base_url' => $_POST['api_base_url'] ?? '',
                'enabled' => isset($_POST['enabled']) ? 1 : 0,
            ]);

            if (isset($_FILES['sport_image']) && ($_FILES['sport_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $updated = sports_registry_save_sport_image($id, $_FILES['sport_image']);
            }

            $sport = $updated;
            $notice = 'Sport updated successfully.';
            csrf_rotate();
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        $error = 'Update failed: ' . $e->getMessage();
    }
}

$title = 'Edit ' . $sport['name'];
require __DIR__ . '/../../../partials/support-head.php';
?>
<div style="max-width: 680px;">
  <p><a href="/admin/plugin/odds-api/registry/">← Back to Sports Categories</a> · <a href="/admin/plugin/odds-api/registry/registration.php?sport=<?= h($sport['slug']) ?>">Tournament List</a></p>

  <span style="font-size:1.25rem;font-weight:700;display:block;margin-bottom:14px;">Edit Sports Category — <?= h($sport['name']) ?></span>

  <?php if ($notice): ?><p role="status" style="padding:10px 14px;background:#e9f8ef;border:1px solid #9bd5af;color:#0d6630;border-radius:6px;"><?= h($notice) ?></p><?php endif; ?>
  <?php if ($error): ?><p role="alert" style="padding:10px 14px;background:#fff1f1;border:1px solid #e5aaaa;color:var(--danger);border-radius:6px;"><?= h($error) ?></p><?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="user-form" style="display:grid;gap:16px;">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="save">

    <p><label>Category Name<br><input name="name" required value="<?= h($sport['name']) ?>" maxlength="100" style="width:100%;box-sizing:border-box;"></label></p>
    <p><label>Slug<br><input name="slug" required value="<?= h($sport['slug']) ?>" maxlength="100" style="width:100%;box-sizing:border-box;"></label><br><small style="color:var(--text-muted);">Stable unique image key: <code><?= h($sport['image_key']) ?></code></small></p>
    <p><label>API-Sports Identifier<br><input name="api_sport_id" value="<?= h($sport['api_sport_id']) ?>" maxlength="64" style="width:100%;box-sizing:border-box;"></label></p>
    <p><label>API Base URL<br><input name="api_base_url" value="<?= h($sport['api_base_url']) ?>" maxlength="255" style="width:100%;box-sizing:border-box;"></label></p>
    <p><label><input type="checkbox" name="enabled" value="1" <?= $sport['enabled'] ? 'checked' : '' ?>> Enabled</label></p>

    <!-- Sport Image Section -->
    <fieldset style="border:1px solid var(--border);border-radius:8px;padding:16px;">
      <legend style="padding:0 8px;font-weight:700;">Sport Image</legend>
      <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
        <div>
          <?php if (!empty($sport['image_path'])): ?>
            <img src="<?= h($sport['image_path']) ?>" alt="<?= h($sport['name']) ?>" style="max-width:120px;max-height:120px;object-fit:contain;background:#1e293b;border-radius:8px;padding:4px;border:1px solid var(--border);">
          <?php else: ?>
            <div style="width:100px;height:100px;border-radius:8px;background:var(--surface-soft);display:flex;align-items:center;justify-content:center;color:var(--text-muted);border:1px dashed var(--border);">No Image</div>
          <?php endif; ?>
        </div>
        <div>
          <label>Upload / Replace Image<br><input type="file" name="sport_image" accept="image/jpeg,image/png,image/webp,image/avif"></label>
          <p style="font-size:0.8rem;color:var(--text-muted);margin:4px 0 0;">JPEG, PNG, WebP or AVIF (max 10MB). Image key reference: <code><?= h($sport['image_key']) ?></code></p>
          <?php if (!empty($sport['image_path'])): ?>
            <p style="font-size:0.8rem;color:var(--text-muted);margin:4px 0 0;">Path: <code><?= h($sport['image_path']) ?></code></p>
          <?php endif; ?>
        </div>
      </div>
      <?php if (!empty($sport['image_path'])): ?>
        <button type="submit" name="action" value="remove_image" class="btn btn-secondary btn-sm" onclick="return confirm('Remove current sport image?');">Remove Image</button>
      <?php endif; ?>
    </fieldset>

    <div style="display:flex;gap:10px;margin-top:12px;">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <a href="/admin/plugin/odds-api/registry/" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
</main></body></html>

