<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/blog-storage.php';
$message = ''; $failed = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_valid_csrf();
    try {
        $enabled = game_visibility_value($_POST['enabled'] ?? null);
        $pdo = games_pdo();
        require_once __DIR__ . '/../includes/indexing-queue.php';
        indexing_content_changed($pdo, 'game');
        $pdo->prepare('UPDATE game_module_settings SET enabled=? WHERE id=1')->execute([$enabled]);
        game_visibility_refresh();
        csrf_rotate();
        $message = $enabled ? 'Games enabled.' : 'Games disabled. Public games are hidden and new import/enrichment work is paused.';
    } catch (Throwable $error) {
        error_log('Game module settings: ' . $error->getMessage());
        http_response_code(500); $failed = true;
        $message = 'Settings could not be fully applied. Check the current switch and save again to refresh public data.';
    }
}
$enabled = games_enabled();
$title = 'Games Settings'; require __DIR__ . '/../partials/support-head.php';
?>
<?php if ($message !== ''): ?><p role="status" class="<?= $failed ? 'support-error' : '' ?>"><?= h($message) ?></p><?php endif; ?>
<form method="post">
<?= csrf_input() ?>
<input type="hidden" name="enabled" value="0">
<label><input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>> Enable slots and games</label>
<p>When disabled, public game lists, details, blog game demos, and game sitemaps are hidden. Imports and description generation pause. You can still manage games and providers here.</p>
<button class="btn btn-primary" type="submit">Save settings</button>
</form>
</main></body></html>
