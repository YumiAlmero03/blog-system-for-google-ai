<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_auth('admin');
require_once __DIR__ . '/includes/chat-auto-responses.php';
header('Cache-Control: no-store');
$error = ''; $rules = []; $edit = ['id'=>'','label'=>'','response'=>'','keywords'=>[],'enabled'=>true,'suggested'=>false];
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        require_valid_csrf();
        try {
            chat_auto_change(is_string($_POST['action'] ?? null) ? $_POST['action'] : '',$_POST);
            header('Location: /admin/chat-auto-responses.php?saved=1',true,303);
            exit;
        } catch (InvalidArgumentException $e) {
            http_response_code(422); $error = $e->getMessage();
            foreach (['id','label','response'] as $key) $edit[$key] = is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
            $edit['keywords'] = explode("\n",is_string($_POST['keywords'] ?? null) ? $_POST['keywords'] : '');
            $edit['enabled'] = isset($_POST['enabled']); $edit['suggested'] = isset($_POST['suggested']);
        }
    }
    $rules = chat_auto_rules();
    if (isset($_GET['edit'])) {
        foreach ($rules as $rule) if ($rule['id'] === $_GET['edit']) $edit = $rule;
    }
} catch (Throwable $e) { http_response_code(500); $error = 'Automatic responses are unavailable.'; }
$title = 'Automatic Chat Replies'; require __DIR__ . '/partials/support-head.php';
?>
<p><a href="/admin/live-chat.php">Back to Live Chat</a></p>
<p>Add unlimited matching rules. Choose up to 5 enabled suggestions for the chat support layout. Matching ignores letter case and extra spaces; the longest matching phrase wins, with the first saved rule winning ties.</p>
<p>Enabled replies also provide the AI with support information. Add accurate steps and links for registration, login, and other common questions. Matching phrases send the saved reply immediately; other questions can use this information in an AI response. For unresolved issues, the AI can ask for an email address or contact number to help proceed with the issue in Live Chat. Relevant public blog and sitemap pages can be shared as clickable links.</p>
<?php if ($error): ?><p role="alert" class="support-error"><?= h($error) ?></p><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><p role="status">Automatic replies saved.</p><?php endif; ?>
<h2><?= $edit['id'] !== '' ? 'Edit reply' : 'Add reply' ?></h2>
<form method="post" action="/admin/chat-auto-responses.php">
<input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= h($edit['id']) ?>">
<p><label>Suggestion label <input name="label" maxlength="120" placeholder="How to deposit" value="<?= h($edit['label']) ?>" required></label></p>
<p><label>Matching phrases (one per line)<textarea name="keywords" maxlength="10000" placeholder="how to deposit&#10;deposit money" required><?= h(implode("\n",$edit['keywords'])) ?></textarea></label></p>
<p>The label also matches player messages. For a bonus reply, you could add “claim bonus” and “how to get bonus”.</p>
<p><label>Automatic reply<textarea id="automatic-reply" name="response" maxlength="5000" required aria-describedby="reply-link-help"><?= h($edit['response']) ?></textarea></label></p>
<p id="reply-link-help">Paste a full website URL, or select text in your reply and use Insert link. Press Enter for a new line.</p>
<div class="support-actions">
<label>Link text <input id="reply-link-label" type="text" placeholder="Register here" maxlength="200"></label>
<label>Link URL <input id="reply-link-url" type="text" placeholder="https://example.com/register" maxlength="2048"></label>
<button id="reply-insert-link" class="btn btn-secondary btn-sm" type="button">Insert link</button>
</div>
<p id="reply-link-error" class="support-error" role="alert"></p>
<p>Reply preview</p><div id="reply-preview" class="support-message support-text"></div>
<div class="support-actions"><label><input type="checkbox" name="enabled" value="1" <?= $edit['enabled'] ? 'checked' : '' ?>> Enabled</label><label><input type="checkbox" name="suggested" value="1" <?= $edit['suggested'] ? 'checked' : '' ?>> Suggested chat</label></div>
<div class="support-actions"><button class="btn btn-primary btn-sm">Save reply</button><a class="btn btn-secondary btn-sm" href="/admin/chat-auto-responses.php">Clear form</a></div>
</form>
<div class="support-table"><table data-paginate data-pagination-label="Automatic replies"><thead><tr><th>Label</th><th>Matching phrases</th><th>Reply</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($rules as $rule): ?>
<tr><td><?= h($rule['label']) ?></td><td class="support-text"><?= h(implode("\n",$rule['keywords'])) ?></td><td class="support-text" data-reply-preview><?= h($rule['response']) ?></td><td><?= $rule['enabled'] ? 'Enabled' : 'Disabled' ?><?= $rule['suggested'] ? ' · Suggested' : '' ?></td><td><a href="?edit=<?= h($rule['id']) ?>">Edit</a><form method="post" action="/admin/chat-auto-responses.php"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= h($rule['id']) ?>"><button class="btn btn-secondary btn-sm">Delete</button></form></td></tr>
<?php endforeach; ?>
<?php if (!$rules): ?><tr><td colspan="5">No automatic replies yet. Add your deposit and bonus instructions above.</td></tr><?php endif; ?>
</tbody></table></div></main><script type="module" src="/admin/chat-auto-responses.js"></script></body></html>
