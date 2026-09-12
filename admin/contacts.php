<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_auth();
require_once __DIR__ . '/../includes/admin-support-data.php';
require_once __DIR__ . '/../includes/admin-date.php';
header('Cache-Control: no-store');
$key = admin_support_query('id',64);
$search = admin_support_query('search');
$page = max(1,min(100000,(int)admin_support_query('page',6)));
$error = ''; $rows = []; $contact = null; $total = 0;
try {
    $all = admin_ticket_rows();
    if ($key !== '') {
        foreach ($all as $row) if (hash_equals($row['_key'],$key)) { $contact = $row; break; }
        if (!$contact) { http_response_code(404); $error = 'Ticket not found or no longer retained in the archive.'; }
    } else {
        $all = array_values(array_filter($all,static fn(array $row): bool => admin_support_matches($row,$search)));
        $total = count($all);
        $rows = array_slice($all,($page-1)*25,25);
    }
} catch (Throwable $e) { http_response_code(500); error_log('Admin ticket list failed.'); $error = 'Tickets are temporarily unavailable.'; }
$title = 'Contacts';
require __DIR__ . '/partials/support-head.php';
?>
<?php if ($error): ?><p role="alert" class="support-error"><?= h($error) ?></p><?php endif; ?>
<?php if ($contact): ?>
<p><a href="/admin/contacts.php">Back to Contacts</a></p>
<h2><?= h($contact['topic'] ?? 'Customer Ticket') ?></h2>
<p><?= h($contact['fullName'] ?? 'Guest') ?> · <?= h($contact['contact'] ?? '') ?></p>
<p>Email: <?= h($contact['email'] ?? 'Not recorded') ?></p>
<p>Created: <?= h(admin_format_date($contact['time'] ?? '')) ?> · Updated: <?= h(admin_format_date($contact['updatedAt'] ?? '') ?: 'Not recorded') ?></p>
<p>Status: <?= h($contact['status'] ?? 'Not recorded') ?></p>
<p class="support-text">Source: <?= h($contact['pageUrl'] ?? '') ?></p>
<div class="support-text"><?= h($contact['problem'] ?? '') ?></div>
<?php elseif (!$error): ?>
<form method="get" class="support-actions"><label>Search <input name="search" value="<?= h($search) ?>" maxlength="120"></label><button class="btn btn-secondary btn-sm">Search</button></form>
<p>Showing up to 500 retained submissions. Status changes and deletion are unavailable.</p>
<div class="support-table"><table><thead><tr><th>Customer</th><th>Contact / email</th><th>Topic / message</th><th>Status</th><th>Created / updated</th><th>Source</th><th>Action</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?>
<tr><td><?= h($row['fullName'] ?? 'Guest') ?></td><td><?= h($row['contact'] ?? '') ?><?php if (!empty($row['email'])): ?><br><?= h($row['email']) ?><?php endif; ?></td><td class="support-text"><strong><?= h($row['topic'] ?? '') ?></strong><br><?= h(substr($row['problem'] ?? '',0,120)) ?></td><td><?= h($row['status'] ?? 'Not recorded') ?></td><td><?= h(admin_format_date($row['time'] ?? '')) ?><br><?= h(admin_format_date($row['updatedAt'] ?? '') ?: 'No update recorded') ?></td><td class="support-text"><?= h($row['pageUrl'] ?? '') ?></td><td><a class="btn btn-secondary btn-sm" href="?id=<?= h($row['_key']) ?>">View</a></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7">No matching tickets.</td></tr><?php endif; ?>
</tbody></table></div>
<nav class="support-actions" aria-label="Pagination">
<?php if ($page>1): ?><a class="btn btn-secondary btn-sm" href="?<?= h(http_build_query(['search'=>$search,'page'=>$page-1])) ?>">Previous</a><?php endif; ?>
<?php if ($page*25<$total): ?><a class="btn btn-secondary btn-sm" href="?<?= h(http_build_query(['search'=>$search,'page'=>$page+1])) ?>">Next</a><?php endif; ?>
</nav>
<?php endif; ?></main></body></html>
