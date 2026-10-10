<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_admin();
require_once __DIR__ . '/includes/service.php';
$error = ''; $saved = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_valid_csrf();
    try { odds_api_save($_POST); csrf_rotate(); $saved = true; }
    catch (InvalidArgumentException $e) { http_response_code(422); $error = $e->getMessage(); }
    catch (Throwable $e) { http_response_code(503); $error = 'Odds API settings could not be saved.'; }
}
$oddsConfig = odds_api_config();
$title = 'Odds API Settings'; require __DIR__ . '/../../partials/support-head.php';
?>
<link rel="stylesheet" href="/admin/plugin/odds-api/sports/editor.css">
<p><a href="/admin/plugin/odds-api/">Sports iframe editor</a></p>
<?php if ($error): ?><p role="alert"><?= h($error) ?></p><?php endif ?>
<?php if ($saved): ?><p role="status">Odds API settings saved.</p><?php endif ?>
          <form method="post" class="settings-card" id="odds-api">
            <?= csrf_input() ?>
            <h2>Odds API</h2>
            <input type="hidden" name="enabled" value="0">
            <label><input type="checkbox" name="enabled" value="1" <?= (int)$oddsConfig['enabled'] ? 'checked' : '' ?>> Enable Odds API</label>
            <p>Turning this off hides public odds and stops provider requests.</p>
            <div class="form-row">
              <label for="odds-api-key">apiKey</label>
              <input type="password" id="odds-api-key" name="apiKey" maxlength="256" autocomplete="new-password" placeholder="<?= $oddsConfig['api_key'] !== '' ? 'Key saved — leave blank to keep it' : 'Enter The Odds API key' ?>">
              <label><input type="checkbox" name="clear_api_key" value="1"> Remove saved key (disable Odds API first)</label>
            </div>
            <div class="form-row"><label for="odds-regions">regions</label><input id="odds-regions" name="regions" type="text" value="<?= h($oddsConfig['regions']) ?>" maxlength="1000" required><p>Comma-separated: us, us2, uk, au, eu.</p></div>
            <p>Choose markets, bookmakers, and arrange display fields in the <a href="/admin/plugin/odds-api/sports/">iframe editor</a>.</p>
            <div class="actions"><button type="submit" name="action" value="save_odds_api" class="btn btn-primary btn-sm">Save Odds API Settings</button><a class="btn btn-secondary btn-sm" href="/admin/plugin/odds-api/sports/">Sport / Competition</a></div>
          </form>
</main></body></html>
