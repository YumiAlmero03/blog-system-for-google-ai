<?php
declare(strict_types=1);
if (!function_exists('auth_can')) { http_response_code(403); exit; }

$adminCurrentPath = $_SERVER['SCRIPT_NAME'] ?? '';
$isSportsRegistry = str_starts_with($adminCurrentPath, '/admin/settings/sports/');
$adminNavItems = [
    ['/admin/blogs.php', 'Blogs', str_ends_with($adminCurrentPath, '/admin/blogs.php')],
    ['/admin/blog-categories.php', 'Categories', str_ends_with($adminCurrentPath, '/admin/blog-categories.php')],
    ['/admin/blog-tags.php', 'Tags', str_ends_with($adminCurrentPath, '/admin/blog-tags.php')],
    ['/admin/seo-checker-v2.php', 'SEO Checker V2', str_ends_with($adminCurrentPath, '/admin/seo-checker-v2.php')],
    ['/admin/playnow-tracker.php', 'Play Now Tracker', str_ends_with($adminCurrentPath, '/admin/playnow-tracker.php')],
    ['/admin/contacts.php', 'Contacts', str_ends_with($adminCurrentPath, '/admin/contacts.php')],
    ['/admin/live-chat.php', 'Live Chat', str_ends_with($adminCurrentPath, '/admin/live-chat.php')],
    ['/admin/users.php', 'Users', str_ends_with($adminCurrentPath, '/admin/users.php')],
    ['/admin/index-checker/', 'Index Checker', str_starts_with($adminCurrentPath, '/admin/index-checker/')],
    ['/admin/settings.php', 'Settings', (str_ends_with($adminCurrentPath, '/admin/settings.php') || (str_starts_with($adminCurrentPath, '/admin/settings/') && !$isSportsRegistry && !str_starts_with($adminCurrentPath, '/admin/plugin/odds-api/sports/')))],
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
<script src="/admin/table-pagination.js" defer></script>
<span id="admin-session-config" hidden data-csrf="<?= h(csrf_token()) ?>" data-idle-timeout="<?= AUTH_INACTIVITY_TIMEOUT ?>"></span>
<script src="/admin/session-renew.js" defer></script>
<style>
details.games-nav summary, details.odds-nav summary { list-style: none; user-select: none; }
details.games-nav summary::-webkit-details-marker, details.odds-nav summary::-webkit-details-marker { display: none; }
</style>
<header class="site-header">
  <div class="header-inner">
    <a href="/admin/blogs.php" class="brand-logo">
      <span class="badge-tag" style="background-color: var(--brand); color:#fff;"><?= h(strtoupper(str_replace('_',' ',$_SESSION['user']['role'] ?? 'admin'))) ?></span>
    </a>
    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
      <?php if (is_dir(__DIR__ . '/../plugin/games') && auth_can('slots.view')): ?>
      <details class="games-nav" style="position:relative;">
        <summary class="btn <?= str_starts_with($adminCurrentPath, '/admin/plugin/games/') ? 'btn-primary' : 'btn-secondary' ?> btn-sm">Games ▾</summary>
        <div style="position:absolute;z-index:100;top:calc(100% + 4px);left:0;min-width:180px;padding:8px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);box-shadow:0 4px 14px rgba(0,0,0,0.15);display:grid;gap:6px;">
          <a class="btn btn-secondary btn-sm" href="/admin/plugin/games/">All Games</a>
          <?php if (auth_can('admin')): ?>
          <a class="btn btn-secondary btn-sm" href="/admin/plugin/games/providers.php">Providers</a>
          <a class="btn btn-secondary btn-sm" href="/admin/plugin/games/settings.php">Games Settings</a>
          <?php endif; ?>
        </div>
      </details>
      <?php endif; ?>
      <?php if (is_dir(__DIR__ . '/../plugin/odds-api') && auth_can('admin')): ?>
      <details class="odds-nav" style="position:relative;">
        <summary class="btn <?= str_starts_with($adminCurrentPath, '/admin/plugin/odds-api/') ? 'btn-primary' : 'btn-secondary' ?> btn-sm">Odds API ▾</summary>
        <div style="position:absolute;z-index:100;top:calc(100% + 4px);left:0;min-width:180px;padding:8px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);box-shadow:0 4px 14px rgba(0,0,0,0.15);display:grid;gap:6px;">
          <a class="btn btn-secondary btn-sm" href="/admin/plugin/odds-api/sports/">Sport / Layouts</a>
          <a class="btn btn-secondary btn-sm" href="/admin/plugin/odds-api/registry/">Sports Registry</a>
          <a class="btn btn-secondary btn-sm" href="/admin/plugin/odds-api/settings.php">Odds Settings</a>
        </div>
      </details>
      <?php endif; ?>
      <?php foreach ($adminNavItems as [$href, $label, $isActive]): ?>
        <a href="<?= h($href) ?>" class="btn <?= $isActive ? 'btn-primary' : 'btn-secondary' ?> btn-sm"><?= h($label) ?></a>
      <?php endforeach; ?>
      <form action="/admin/logout.php" method="post" style="margin:0;">
        <?= csrf_input() ?>
        <button type="submit" class="btn btn-secondary btn-sm">Logout</button>
      </form>
    </div>
  </div>
</header>
<script>
document.addEventListener('click', function(e) {
  document.querySelectorAll('details.games-nav, details.odds-nav').forEach(function(d) {
    if (!d.contains(e.target)) d.removeAttribute('open');
  });
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('details.games-nav, details.odds-nav').forEach(function(d) {
      d.removeAttribute('open');
    });
  }
});
</script>
