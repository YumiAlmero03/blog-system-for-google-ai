<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_auth();
require_once __DIR__ . '/includes/admin-support-data.php';
require_once __DIR__ . '/includes/admin-date.php';
header('Cache-Control: no-store');
$id = admin_support_query('id'); $search = admin_support_query('search');
$error = ''; $chat = null; $rows = []; $total = 0;
$page = max(1,min(100000,(int)admin_support_query('page',6)));
try {
    if ($id !== '') {
        $chat = chat_read_session($id);
        if (!$chat) { http_response_code(404); $error = 'Chat session not found.'; }
    } else {
        $all = admin_chat_rows($search); $total = count($all); $rows = array_slice($all,($page-1)*25,25);
    }
} catch (Throwable $e) { http_response_code(500); error_log('Admin chat list failed.'); $error = 'Chats are temporarily unavailable.'; }
$title = 'Live Chat'; require __DIR__ . '/partials/support-head.php';
?>
<?php if ($error): ?><p role="alert" class="support-error"><?= h($error) ?></p><?php endif; ?>
<?php if ($chat): ?>
<p><a href="/admin/live-chat.php">Back to Live Chat</a></p>
<h2><?= h(($chat['name'] ?? '') ?: 'Guest') ?></h2>
<p>Created <?= h(admin_format_date($chat['startedAt'] ?? '')) ?> · Updated <?= h(admin_format_date($chat['updatedAt'] ?? '')) ?></p>
<p>Status: <?= h($chat['status'] ?? 'Not recorded') ?></p>
<div id="conversation" data-chat-id="<?= h($id) ?>" data-csrf="<?= h(csrf_token()) ?>">
<div id="chat-messages" role="log" aria-live="polite"></div><p id="chat-error" role="alert" class="support-error"></p>
<form id="chat-reply"><label for="reply-message">Reply</label><textarea id="reply-message" name="message" maxlength="5000" required></textarea><div class="support-actions"><button class="btn btn-primary btn-sm">Save reply</button></div></form>
<p>Replies are saved in the session history. Delivery to customer browsers is not available.</p>
</div><script src="/admin/live-chat.js" defer></script>
<?php elseif (!$error): ?>
<form method="get" class="support-actions"><label>Search <input name="search" value="<?= h($search) ?>" maxlength="120"></label><button class="btn btn-secondary btn-sm">Search</button></form>
<p>Latest activity first. Refresh for new sessions. Status and unread counts are shown when recorded.</p>
<div class="support-table"><table><thead><tr><th>Customer</th><th>Last message</th><th>Status</th><th>Unread</th><th>Last activity</th><th>Created</th><th>Action</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?>
<tr><td><?= h($row['name'] ?: 'Guest') ?></td><td class="support-text"><?= h(substr($row['preview'],0,120)) ?></td><td><?= h($row['status'] ?: 'Not recorded') ?></td><td><?= h($row['unreadCount'] ?? 'Not recorded') ?></td><td><?= h(admin_format_date($row['updatedAt'])) ?></td><td><?= h(admin_format_date($row['startedAt'])) ?></td><td><a class="btn btn-secondary btn-sm" href="?id=<?= h($row['sessionId']) ?>">Open</a></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7">No matching sessions.</td></tr><?php endif; ?>
</tbody></table></div>
<nav class="support-actions" aria-label="Pagination"><?php if ($page>1): ?><a class="btn btn-secondary btn-sm" href="?<?= h(http_build_query(['search'=>$search,'page'=>$page-1])) ?>">Previous</a><?php endif; ?><?php if ($page*25<$total): ?><a class="btn btn-secondary btn-sm" href="?<?= h(http_build_query(['search'=>$search,'page'=>$page+1])) ?>">Next</a><?php endif; ?></nav>
<?php endif; ?></main></body></html>
