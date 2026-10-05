<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/includes/service.php';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_valid_csrf();
    try {
        if (($_POST['action'] ?? '') !== 'save_iframe') throw new InvalidArgumentException('Invalid action.');
        $savedId = odds_api_save_iframe($_POST);
        csrf_rotate();
        header('Location: /admin/odds-api/?id=' . $savedId . '&saved=1#iframe-editor', true, 303); exit;
    } catch (InvalidArgumentException $e) { http_response_code(422); $error = $e->getMessage(); }
    catch (OutOfBoundsException $e) { http_response_code(404); $error = 'Iframe not found.'; }
    catch (Throwable $e) { http_response_code(500); $error = 'The iframe could not be saved.'; }
}
$config = odds_api_config();
$new = isset($_GET['new']);
$id = $_GET['id'] ?? '1';
if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,9}$/D', $id)) { http_response_code(422); exit('Invalid iframe ID.'); }
try { $layout = $new ? odds_api_default_iframe() : odds_api_iframe((int)$id); }
catch (OutOfBoundsException $e) { http_response_code(404); exit('Iframe not found.'); }
$iframes = odds_api_iframe_pdo()->query('SELECT id,title,markets,updated_at FROM iframes ORDER BY id DESC')->fetchAll();
$frameUrl = '/odds-api/?iframe=' . ($layout['id'] ?: 1);
$title = 'Odds API'; require __DIR__ . '/../partials/support-head.php';
?>
<link rel="stylesheet" href="/admin/odds-api/editor.css">
<p>Status: <strong><?= (int)$config['enabled'] ? 'On' : 'Off' ?></strong> · API key: <?= $config['api_key'] !== '' ? 'Saved' : 'Not configured' ?></p>
<p><a class="btn btn-secondary btn-sm" href="/admin/settings.php#odds-api">Odds API Settings</a> <a class="btn btn-primary btn-sm" href="?new=1#iframe-editor">New iframe</a></p>
<?php if ($error !== ''): ?><p role="alert" class="support-error"><?= h($error) ?></p><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><p role="status">Iframe saved.</p><?php endif; ?>
<h2 id="iframe-editor"><?= $new ? 'New iframe' : 'Edit iframe #' . (int)$layout['id'] ?></h2>
<form method="post" id="iframe-form">
<?= csrf_input() ?><input type="hidden" name="action" value="save_iframe"><input type="hidden" name="id" value="<?= (int)$layout['id'] ?>">
<input type="hidden" name="blocks" id="iframe-blocks" value="<?= h(json_encode($layout['blocks'], JSON_THROW_ON_ERROR)) ?>">
<div class="block-toolbar">
<button type="button" class="btn btn-primary btn-sm" id="toggle-inserter" aria-expanded="true" aria-controls="block-inserter">＋ Add block</button>
<button type="button" class="btn btn-secondary btn-sm" id="block-undo" disabled>Undo</button>
<button type="button" class="btn btn-secondary btn-sm" id="block-redo" disabled>Redo</button>
<span class="toolbar-spacer"></span><button class="btn btn-primary btn-sm" id="save-iframe" type="submit">Save iframe</button>
</div>
<div class="block-editor">
<aside id="block-inserter" class="editor-panel"><h3>Add data block</h3><p>Remove a block from the layout, then add it back here whenever you need it.</p><div id="available-blocks"></div></aside>
<div class="editor-canvas">
<label class="canvas-title">Iframe title<input name="title" id="iframe-name" value="<?= h($layout['title']) ?>" maxlength="200" required></label>
<p class="canvas-hint">Select a block to change its label and display role. Drag to reorder, or use the arrow buttons.</p>
<?php foreach (['event_fields'=>'Event details','outcome_fields'=>'Bookmaker / outcome columns'] as $group=>$heading): ?>
<section><h3><?= h($heading) ?></h3><ol class="iframe-field-list" data-field-group="<?= h($group) ?>" aria-label="<?= h($heading) ?>"></ol><p class="empty-blocks" data-empty-group="<?= h($group) ?>" hidden>No blocks. Use Add block to include data.</p></section>
<?php endforeach; ?>
</div>
<aside class="editor-panel block-inspector">
<h3>Block settings</h3>
<p id="no-block-selected">Select a block in the canvas.</p>
<div id="block-settings" hidden>
<p><code id="selected-source"></code></p>
<label>Display label<input type="text" id="block-label" maxlength="120"></label>
<label>Display as<select id="block-role"><option value="data">Data</option><option value="title">Title</option><option value="description">Description</option></select></label>
<p>Title makes the value a heading. Description displays it as paragraph text.</p>
<button type="button" class="btn btn-secondary btn-sm" id="remove-block">Remove block</button>
</div>
<hr><h3>Iframe settings</h3>
<label>Markets<input name="markets" value="<?= h($layout['markets']) ?>" maxlength="1000" required></label>
<p>h2h, spreads, totals, outrights. Separate multiple markets with commas.</p>
<p>Save to publish this iframe's layout and markets.</p>
</aside>
</div>
<p id="iframe-order-status" role="status" aria-live="polite"></p>
</form>
<h2>Preview</h2>
<p>Block changes preview immediately. Save to publish them. This layout changes only iframe <?= $layout['id'] ? '#' . (int)$layout['id'] : 'you are creating' ?>.</p>
<iframe id="iframe-preview" src="<?= h($frameUrl) ?>" title="Odds API preview" width="100%" height="650" style="border:0" loading="lazy"></iframe>
<h2>Embed</h2>
<p>For another website, put this site's full HTTPS address before <code>/odds-api/</code>. Append <code>&amp;sport=basketball_nba</code> to select an initial sport.</p>
<textarea id="iframe-embed" readonly aria-label="Iframe embed code"><?= $new ? 'Save the iframe to generate its embed code.' : h('<iframe src="' . $frameUrl . '" title="' . h($layout['title']) . '" width="100%" height="650" style="border:0" loading="lazy"></iframe>') ?></textarea>
<h2>Saved iframes</h2>
<div class="support-table"><table data-paginate data-pagination-label="Saved iframes"><thead><tr><th>ID</th><th>Title</th><th>Markets</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($iframes as $iframe): ?>
<tr><td><?= (int)$iframe['id'] ?></td><td><?= h($iframe['title']) ?></td><td><?= h($iframe['markets']) ?></td><td><a href="?id=<?= (int)$iframe['id'] ?>#iframe-editor">Edit</a> · <a href="/odds-api/?iframe=<?= (int)$iframe['id'] ?>" target="_blank" rel="noopener">View</a></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<script type="application/json" id="iframe-editor-data"><?= json_encode(['layout'=>$layout,'catalog'=>odds_api_fields()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<script type="module" src="/admin/odds-api/editor.js"></script>
</main></body></html>
