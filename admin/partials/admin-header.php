<?php
declare(strict_types=1);

$adminCurrentPath = $_SERVER['SCRIPT_NAME'] ?? '';
$adminNavItems = [
    ['/admin/blogs.php', 'Blogs', str_ends_with($adminCurrentPath, '/admin/blogs.php')],
    ['/admin/blog-categories.php', 'Categories', str_ends_with($adminCurrentPath, '/admin/blog-categories.php')],
    ['/admin/slots.php', 'Slots', str_ends_with($adminCurrentPath, '/admin/slots.php')],
    ['/admin/playnow-tracker.php', 'Play Now Tracker', str_ends_with($adminCurrentPath, '/admin/playnow-tracker.php')],
    ['/admin/settings.php', 'Settings', str_ends_with($adminCurrentPath, '/admin/settings.php')],
    ['/admin/blog-publish.php', 'Publish Post', str_ends_with($adminCurrentPath, '/admin/blog-publish.php')],
    ['/blog/', 'View Blog Hub', false],
];
?>
<header class="site-header">
  <div class="header-inner">
    <a href="/admin/blogs.php" class="brand-logo">
      GperyaPH <span class="badge-tag" style="background-color: var(--brand); color:#fff;">ADMIN</span>
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
