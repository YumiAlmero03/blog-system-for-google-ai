<?php
declare(strict_types=1);
if (!function_exists('auth_can') || !auth_can('admin')) { http_response_code(403); exit; }
$logoPath = (string) ($logoPath ?? '');
?>
<fieldset class="registry-logo-editor" style="border:1px solid var(--border);border-radius:8px;padding:16px;">
  <legend style="padding:0 8px;font-weight:700;"><?= h($logoLabel) ?> (<?= h($logoSource ?: 'No logo') ?>)</legend>
  <div style="margin-bottom:12px;">
    <img class="registry-logo-preview" src="<?= h($logoPath ?: '/assets/favicon.svg') ?>" alt="<?= h($logoLabel) ?> preview" <?= $logoPath === '' ? 'hidden' : '' ?> style="max-width:120px;max-height:120px;object-fit:contain;">
    <span class="registry-logo-empty" <?= $logoPath !== '' ? 'hidden' : '' ?>>No logo assigned.</span>
  </div>
  <p><label>Upload / Replace Logo<br><input class="registry-logo-file" type="file" name="<?= h($logoUploadName) ?>" accept="image/jpeg,image/png,image/webp,image/avif"></label><br><small>JPEG, PNG, WebP or AVIF, up to 10 MB. An uploaded file takes priority over the URL.</small></p>
  <p><label>Logo URL or uploaded image path<br><input class="registry-logo-url" type="text" name="logo_url" value="<?= h($logoPath) ?>" maxlength="2048" placeholder="https://example.com/logo.png or /uploads/sports/logo.png" style="width:100%;box-sizing:border-box;"></label></p>
  <p style="font-size:.85rem;color:var(--text-muted);">Save to apply your logo. Manual logos are preserved during automatic sync and enrichment.</p>
  <?php if ($logoPath !== ''): ?>
  <button type="submit" name="action" value="<?= h($logoRemoveAction) ?>" class="btn btn-secondary btn-sm" formnovalidate>Remove Logo</button>
  <?php endif; ?>
</fieldset>
