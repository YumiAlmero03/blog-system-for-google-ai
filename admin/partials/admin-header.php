<?php
declare(strict_types=1);
if (!function_exists('auth_can')) { http_response_code(403); exit; }

$adminCurrentPath = $_SERVER['SCRIPT_NAME'] ?? '';
$adminNavItems = [
    ['/admin/blogs.php', 'Blogs', str_ends_with($adminCurrentPath, '/admin/blogs.php')],
    ['/admin/blog-categories.php', 'Categories', str_ends_with($adminCurrentPath, '/admin/blog-categories.php')],
    ['/admin/blog-tags.php', 'Tags', str_ends_with($adminCurrentPath, '/admin/blog-tags.php')],
    ['/admin/slots.php', 'Slots', str_ends_with($adminCurrentPath, '/admin/slots.php')],
    ['/admin/seo-checker-v2.php', 'SEO Checker V2', str_ends_with($adminCurrentPath, '/admin/seo-checker-v2.php')],
    ['/admin/playnow-tracker.php', 'Play Now Tracker', str_ends_with($adminCurrentPath, '/admin/playnow-tracker.php')],
    ['/admin/contacts.php', 'Contacts', str_ends_with($adminCurrentPath, '/admin/contacts.php')],
    ['/admin/live-chat.php', 'Live Chat', str_ends_with($adminCurrentPath, '/admin/live-chat.php')],
    ['/admin/users.php', 'Users', str_ends_with($adminCurrentPath, '/admin/users.php')],
    ['/admin/index-checker/', 'Index Checker', str_starts_with($adminCurrentPath, '/admin/index-checker/')],
    ['/admin/settings.php', 'Settings', (str_ends_with($adminCurrentPath, '/admin/settings.php') || str_starts_with($adminCurrentPath, '/admin/settings/'))],
    ['/admin/blog-publish.php', 'Publish Post', str_ends_with($adminCurrentPath, '/admin/blog-publish.php')],
    ['/blog/', 'View Blog Hub', false],
];
if (($_SESSION['user']['role'] ?? '') === 'editor') {
    $adminNavItems = array_values(array_filter($adminNavItems,static fn(array $item): bool => in_array($item[1],['Blogs','Slots','Contacts','Live Chat'],true)));
    foreach ($adminNavItems as &$item) {
        if ($item[1] === 'Blogs' && in_array(auth_route_capability(),['blogs.view','blogs.edit','blogs.publish'],true)) $item[2] = true;
        if ($item[1] === 'Slots' && in_array(auth_route_capability(),['slots.view','slots.edit'],true)) $item[2] = true;
    }
    unset($item);
}
?>
<header class="site-header">
  <div class="header-inner">
    <a href="/admin/blogs.php" class="brand-logo">
      <span class="badge-tag" style="background-color: var(--brand); color:#fff;"><?= h(strtoupper(str_replace('_',' ',$_SESSION['user']['role'] ?? 'admin'))) ?></span>
    </a>
    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
      <?php foreach ($adminNavItems as [$href, $label, $isActive]): ?>
        <a href="<?= h($href) ?>" class="btn <?= $isActive ? 'btn-primary' : 'btn-secondary' ?> btn-sm"><?= h($label) ?></a>
      <?php endforeach; ?>
      <form action="/logout.php" method="post" style="margin:0;">
        <?= csrf_input() ?>
        <button type="submit" class="btn btn-secondary btn-sm">Logout</button>
      </form>
    </div>
  </div>
</header>
