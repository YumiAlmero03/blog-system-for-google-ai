<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_auth();
require_once __DIR__ . '/../includes/support-storage.php';
require_once __DIR__ . '/../includes/admin-date.php';
header('Cache-Control: no-store');
$id = filter_var($_GET['id'] ?? 0,FILTER_VALIDATE_INT) ?: 0;
$page = max(1,min(100000,(int)(filter_var($_GET['page'] ?? 1,FILTER_VALIDATE_INT) ?: 1)));
$error = '';
$rows = [];
$contact = null;
$total = 0;
try {
    $pdo = support_pdo();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        require_valid_csrf();
        $postId = filter_var($_POST['id'] ?? 0,FILTER_VALIDATE_INT) ?: 0;
        if ($postId < 1) throw new InvalidArgumentException('Invalid submission.');
        if (($_POST['action'] ?? '') === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM contact_submissions WHERE id=?');
            $stmt->execute([$postId]);
            header('Location: /admin/contacts.php',true,303);
            exit;
        }
        support_contact_status($postId,support_text($_POST,'status',16));
        header('Location: /admin/contacts.php?id=' . $postId,true,303);
        exit;
    }
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM contact_submissions WHERE id=?');
        $stmt->execute([$id]);
        $contact = $stmt->fetch();
        if (!$contact) { http_response_code(404); $error = 'Submission not found.'; }
    } else {
        $total = (int)$pdo->query('SELECT COUNT(*) FROM contact_submissions')->fetchColumn();
        $stmt = $pdo->prepare('SELECT * FROM contact_submissions ORDER BY created_at DESC,id DESC LIMIT 25 OFFSET ?');
        $stmt->bindValue(1,($page-1)*25,PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Contacts are temporarily unavailable.';
    if (!($e instanceof InvalidArgumentException)) error_log('Admin contact operation failed.');
    http_response_code($e instanceof InvalidArgumentException ? 422 : 500);
}
$title = 'Contacts';
require __DIR__ . '/partials/support-head.php';
?>
<?php if ($error): ?><p role="alert" class="support-error"><?= h($error) ?></p><?php endif; ?>
<?php if ($contact): ?>
<p><a href="/admin/contacts.php">Back to Contacts</a></p>
<h2><?= h($contact['subject']) ?></h2>
<p><?= h($contact['name'] ?: 'Guest') ?> · <?= h($contact['email']) ?></p>
<p><?= h(admin_format_date($contact['created_at'])) ?> · <?= h(ucfirst($contact['status'])) ?></p>
<p class="support-text">Source: <?= h($contact['source_page'] ?: 'Not provided') ?></p>
<div class="support-text"><?= h($contact['message']) ?></div>
<form method="post" class="support-actions">
<?= csrf_input() ?><input type="hidden" name="id" value="<?= h($contact['id']) ?>">
<button class="btn btn-secondary btn-sm" name="status" value="new">Mark unread</button>
<button class="btn btn-secondary btn-sm" name="status" value="read">Mark read</button>
<button class="btn btn-primary btn-sm" name="status" value="resolved">Mark resolved</button>
</form>
<form method="post" onsubmit="return confirm('Permanently delete this contact submission?');">
<?= csrf_input() ?><input type="hidden" name="id" value="<?= h($contact['id']) ?>">
<button class="btn btn-secondary btn-sm" name="action" value="delete">Delete submission</button>
</form>
<?php elseif (!$error): ?>
<div class="support-table"><table><thead><tr><th>Name</th><th>Email</th><th>Subject / message</th><th>Status</th><th>Submitted</th><th>Source</th><th>Action</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?>
<tr><td><?= h($row['name'] ?: 'Guest') ?></td><td><?= h($row['email']) ?></td><td class="support-text"><strong><?= h($row['subject']) ?></strong><br><?= h(mb_substr($row['message'],0,120)) ?></td><td><?= h(ucfirst($row['status'])) ?></td><td><?= h(admin_format_date($row['created_at'])) ?></td><td class="support-text"><?= h($row['source_page']) ?></td><td><a class="btn btn-secondary btn-sm" href="/admin/contacts.php?id=<?= h($row['id']) ?>">View</a></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7">No contact submissions yet.</td></tr><?php endif; ?>
</tbody></table></div>
<nav class="support-actions" aria-label="Pagination">
<?php if ($page>1): ?><a class="btn btn-secondary btn-sm" href="?page=<?= $page-1 ?>">Previous</a><?php endif; ?>
<?php if ($page*25<$total): ?><a class="btn btn-secondary btn-sm" href="?page=<?= $page+1 ?>">Next</a><?php endif; ?>
</nav>
<?php endif; ?></main></body></html>
