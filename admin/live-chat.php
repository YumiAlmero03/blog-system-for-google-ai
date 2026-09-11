<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_auth();
require_once __DIR__ . '/../includes/support-storage.php';
require_once __DIR__ . '/../includes/admin-date.php';
header('Cache-Control: no-store');
$id = $_GET['id'] ?? '';
$error = '';
$chat = null;
$rows = [];
$page = max(1,min(100000,(int)(filter_var($_GET['page'] ?? 1,FILTER_VALIDATE_INT) ?: 1)));
try {
    $pdo = support_pdo();
    if ($id !== '') {
        $chat = support_chat(support_chat_id($id));
        if (!$chat) { http_response_code(404); $error = 'Chat not found.'; }
    } else {
        // Indexed subqueries in one query: no query per conversation in PHP.
        $stmt = $pdo->prepare("SELECT s.id,s.customer_name,s.status,s.created_at,s.updated_at,
            (SELECT message FROM live_chat_messages m WHERE m.chat_id=s.id ORDER BY m.id DESC LIMIT 1) AS preview,
            (SELECT COUNT(*) FROM live_chat_messages m WHERE m.chat_id=s.id AND m.sender='customer' AND m.read_at IS NULL) AS unread
            FROM live_chat_sessions s ORDER BY s.status DESC,s.updated_at DESC,s.id DESC LIMIT 26 OFFSET ?");
        $stmt->bindValue(1,($page-1)*25,PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Live chat is temporarily unavailable.';
    if (!($e instanceof InvalidArgumentException)) error_log('Admin chat list failed.');
    http_response_code($e instanceof InvalidArgumentException ? 422 : 500);
}
$title = 'Live Chat';
require __DIR__ . '/partials/support-head.php';
?>
<?php if ($error): ?><p role="alert" class="support-error"><?= h($error) ?></p><?php endif; ?>
<?php if ($chat): ?>
<p><a href="/admin/live-chat.php">Back to Live Chat</a></p>
<h2><?= h($chat['customer_name'] ?: 'Guest') ?></h2>
<p>Status: <span id="chat-status"><?= h($chat['status']) ?></span> · Created <?= h(admin_format_date($chat['created_at'])) ?></p>
<div id="conversation" data-chat-id="<?= h($chat['id']) ?>" data-csrf="<?= h(csrf_token()) ?>">
<div id="chat-messages" role="log" aria-live="polite"></div>
<p id="chat-error" role="alert" class="support-error"></p>
<form id="chat-reply"><label for="reply-message">Reply</label><textarea id="reply-message" name="message" maxlength="4000" required></textarea><div class="support-actions"><button class="btn btn-primary btn-sm">Send reply</button></div></form>
<div class="support-actions"><button type="button" class="btn btn-secondary btn-sm" id="chat-read">Mark read</button><button type="button" class="btn btn-secondary btn-sm" id="chat-toggle">Close / reopen chat</button></div>
</div>
<script src="/admin/live-chat.js" defer></script>
<?php elseif (!$error): ?>
<p>Open chats appear first, ordered by latest activity. Refresh to check for new conversations.</p>
<div class="support-table"><table><thead><tr><th>Customer</th><th>Last message</th><th>Status</th><th>Unread</th><th>Last activity</th><th>Created</th><th>Action</th></tr></thead><tbody>
<?php foreach (array_slice($rows,0,25) as $row): ?>
<tr><td><?= h($row['customer_name'] ?: 'Guest') ?></td><td class="support-text"><?= h(mb_substr($row['preview'] ?? '',0,120)) ?></td><td><?= h($row['status']) ?></td><td><?= h($row['unread']) ?></td><td><?= h(admin_format_date($row['updated_at'])) ?></td><td><?= h(admin_format_date($row['created_at'])) ?></td><td><a class="btn btn-secondary btn-sm" href="?id=<?= h($row['id']) ?>">Open</a></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7">No chats yet.</td></tr><?php endif; ?>
</tbody></table></div>
<nav class="support-actions" aria-label="Pagination"><?php if ($page>1): ?><a class="btn btn-secondary btn-sm" href="?page=<?= $page-1 ?>">Previous</a><?php endif; ?><?php if (count($rows)>25): ?><a class="btn btn-secondary btn-sm" href="?page=<?= $page+1 ?>">Next</a><?php endif; ?></nav>
<?php endif; ?></main></body></html>
