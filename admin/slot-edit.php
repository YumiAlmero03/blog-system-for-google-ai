<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_capability('slots.edit');
require_once __DIR__ . '/../includes/slot-content.php';
$id = filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT);
try {
    $stmt = blogs_pdo()->prepare('SELECT id,is_viewable,name,short_description,long_description,rtp,volatility FROM games WHERE id=?');
    $stmt->execute([$id ?: 0]); $game = $stmt->fetch();
    if (!$game) { http_response_code(404); echo 'Game not found.'; exit; }
} catch (Throwable $e) { http_response_code(500); echo 'Game unavailable.'; exit; }
$title = 'Edit Slot Content'; require __DIR__ . '/partials/support-head.php';
$long = (string)$game['long_description'];
// Plain-text imports remain readable; existing HTML is safely loaded as HTML.
$editorHtml = preg_match('/<\/?[a-z][^>]*>/i',$long) ? slot_content_html($long) : '<p>' . nl2br(h($long)) . '</p>';
?>
<link rel="stylesheet" href="/admin/content-editor.css">
<p><a href="/admin/slots.php">Back to Slots</a></p>
<form id="slot-content-form">
<?= csrf_input() ?><input type="hidden" name="id" value="<?= h($game['id']) ?>">
<div class="slot-fields">
<input type="hidden" name="is_viewable" value="0">
<label><input type="checkbox" name="is_viewable" value="1" <?= (int)$game['is_viewable'] === 1 ? 'checked' : '' ?>> Viewable</label>

<label>Game name<input name="name" maxlength="200" required value="<?= h($game['name']) ?>"></label>
<label>RTP (%)<input name="rtp" type="number" min="0" max="100" step="any" value="<?= h($game['rtp']) ?>"></label>
<label>Volatility<input name="volatility" maxlength="120" value="<?= h($game['volatility']) ?>"></label>
<label style="grid-column:1/-1">Short description<textarea name="short_description" maxlength="4000" rows="3"><?= h($game['short_description']) ?></textarea></label>
</div>
<h2>Long description</h2>
<div class="wysiwyg-toolbar" id="slot-toolbar" aria-label="Content formatting">
<?php foreach (['h2'=>'H2','h3'=>'H3','bold'=>'B','italic'=>'I','ul'=>'Bullets','ol'=>'Numbered list','link'=>'Link','clear'=>'Clear formatting'] as $command=>$label): ?><button type="button" class="wysiwyg-btn" data-command="<?= h($command) ?>" title="<?= h($command) ?>"><?= h($label) ?></button><?php endforeach; ?>
</div>
<div id="slot-editor" class="slot-content-editor" role="textbox" aria-label="Long description" aria-multiline="true" contenteditable="true"><?= $editorHtml ?></div>
<p id="slot-feedback" role="status"></p><button class="btn btn-primary" type="submit">Save content</button>
</form>
<script src="/admin/content-editor.js" defer></script><script src="/admin/slot-editor.js" defer></script>
</main></body></html>
