<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/auth.php';
require_admin();
require_once __DIR__ . '/../../../includes/blog-storage.php';
$message = ''; $failed = false;
$pdo = blogs_pdo();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_valid_csrf();
    try {
        $key = $_POST['provider_key'] ?? null;
        if (!is_string($key)) throw new InvalidArgumentException('Invalid provider.');
        game_provider_approval_save($pdo,$key,$_POST['approved'] ?? null);
        $message = 'Approval saved.';
        try { game_visibility_refresh(); }
        catch (Throwable $error) {
            error_log('Provider sitemap refresh failed: ' . $error->getMessage());
            $message = 'Approval saved, but sitemap refresh failed. Save again to retry.'; $failed = true;
        }
        csrf_rotate();
    } catch (InvalidArgumentException $error) { http_response_code(422); $message=$error->getMessage(); $failed=true; }
}
$providers = game_provider_settings_rows($pdo);
$title = 'Providers Settings'; require __DIR__ . '/../../partials/support-head.php';
?>
<p><a href="/admin/settings.php">Back to Settings</a></p>
<?php if ($message !== ''): ?><p role="status" class="<?= $failed ? 'support-error' : '' ?>"><?= h($message) ?></p><?php endif; ?>
<p>Approved providers may appear publicly when each game also meets visibility, processing, publication, and PH restriction rules.</p>
<div class="support-actions"><label>Search providers <input id="provider-search" type="search" autocomplete="off"></label></div>
<div class="support-table"><table><thead><tr><th>Provider</th><th>Games</th><th>Approved</th><th>Save state</th></tr></thead><tbody>
<?php foreach ($providers as $index=>$provider): ?>
<tr data-provider="<?= h(strtolower($provider['name'] ?: $provider['slug'])) ?>">
<td><?= h($provider['name'] ?: ($provider['slug'] ?: 'Unspecified provider')) ?></td><td><?= (int)$provider['game_count'] ?></td>
<td><form id="provider-<?= $index ?>" method="post"><?= csrf_input() ?><input type="hidden" name="provider_key" value="<?= h($provider['provider_key']) ?>"><input type="hidden" name="approved" value="0"><label><input type="checkbox" name="approved" value="1" <?= (int)$provider['approved'] === 1 ? 'checked' : '' ?>> Approved</label></form></td>
<td><button type="submit" form="provider-<?= $index ?>" class="btn btn-secondary btn-sm">Save</button> <span data-state>Saved</span></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<script>
document.getElementById('provider-search').addEventListener('input', event => {
  const term = event.target.value.trim().toLowerCase();
  document.querySelectorAll('[data-provider]').forEach(row => { row.hidden = !row.dataset.provider.includes(term); });
});
document.querySelectorAll('input[type=checkbox]').forEach(input => input.addEventListener('change', () => { input.closest('tr').querySelector('[data-state]').textContent = 'Unsaved'; }));
</script>
</main></body></html>
