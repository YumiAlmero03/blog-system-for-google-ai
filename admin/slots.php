<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/blog-storage.php';

require_auth();

function admin_slots_get_string(string $key, int $maxLength = 120): string
{
    if (!isset($_GET[$key]) || is_array($_GET[$key])) {
        return '';
    }

    return substr(trim((string) $_GET[$key]), 0, $maxLength);
}

function admin_slots_get_int(string $key, int $default, int $min, int $max): int
{
    if (!isset($_GET[$key]) || is_array($_GET[$key]) || !preg_match('/^\d+$/', (string) $_GET[$key])) {
        return $default;
    }

    return max($min, min($max, (int) $_GET[$key]));
}

function admin_slots_page_url(int $page): string
{
    $params = $_GET;
    $params['page'] = (string) $page;
    return '/admin/slots.php?' . http_build_query($params);
}

$pdo = blogs_pdo();
$search = admin_slots_get_string('search');
$provider = admin_slots_get_string('provider');
$type = admin_slots_get_string('type');
$published = admin_slots_get_string('published', 16);
$page = admin_slots_get_int('page', 1, 1, 1000000);
$perPage = admin_slots_get_int('count', 25, 5, 100);
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(name LIKE :search OR slug LIKE :search OR provider LIKE :search OR type LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}
if ($provider !== '') {
    $where[] = 'provider_slug = :provider';
    $params[':provider'] = $provider;
}
if ($type !== '') {
    $where[] = 'type_slug = :type';
    $params[':type'] = $type;
}
if ($published === '1' || $published === '0') {
    $where[] = 'published = :published';
    $params[':published'] = (int) $published;
}
$whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM games {$whereSql}");
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$countStmt->execute();
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$stmt = $pdo->prepare(
    "SELECT id, api_id, name, slug, url, thumb, provider, provider_slug, type, type_slug,
            themes, rtp, volatility, featured, published, upcoming, release, min_bet,
            max_bet, max_win_per_spin, paylines, updated_at
     FROM games
     {$whereSql}
     ORDER BY updated_at DESC, id DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$slots = $stmt->fetchAll();

$providers = $pdo->query('SELECT provider, provider_slug, COUNT(*) AS total FROM games WHERE provider_slug <> "" GROUP BY provider_slug, provider ORDER BY provider ASC')->fetchAll();
$types = $pdo->query('SELECT type, type_slug, COUNT(*) AS total FROM games WHERE type_slug <> "" GROUP BY type_slug, type ORDER BY type ASC')->fetchAll();
$publishedCount = (int) $pdo->query('SELECT COUNT(*) FROM games WHERE published = 1')->fetchColumn();
$featuredCount = (int) $pdo->query('SELECT COUNT(*) FROM games WHERE featured = 1')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en-PH">
<head>
  <meta name="robots" content="noindex, nofollow">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Slots | GperyaPH Admin</title>
  <link rel="preload" href="/assets/css/styles.min.css" as="style"><link rel="stylesheet" href="/assets/css/styles.min.css">
  <link rel="icon" href="/assets/icons/favicon.ico">
  <style>
    .admin-container {
      max-width: 1180px;
      margin: 40px auto;
      padding: 24px;
      background: var(--surface);
      border: 1px solid var(--border-strong);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow);
    }
    .admin-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
      margin-bottom: 20px;
      padding-bottom: 16px;
      border-bottom: 2px solid var(--border);
    }
    .slot-metrics {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 12px;
      margin-bottom: 16px;
    }
    .slot-metric {
      padding: 14px;
      background: var(--surface-soft);
      border: 1px solid var(--border);
      border-radius: 8px;
    }
    .slot-metric strong {
      display: block;
      color: var(--brand-dark);
      font-size: 1.45rem;
      line-height: 1.1;
    }
    .slot-metric span {
      display: block;
      margin-top: 4px;
      color: var(--text-muted);
      font-size: 0.78rem;
      font-weight: 800;
    }
    .slot-filters {
      display: grid;
      grid-template-columns: minmax(180px, 1fr) 180px 160px 140px auto;
      gap: 8px;
      align-items: center;
      margin-bottom: 16px;
      padding: 12px;
      background: var(--surface-soft);
      border: 1px solid var(--border);
      border-radius: 8px;
    }
    .slot-input,
    .slot-select {
      width: 100%;
      min-height: 38px;
      padding: 8px 10px;
      border: 1px solid var(--border-strong);
      border-radius: var(--radius-sm);
      background: #fff;
      color: var(--text);
      font: inherit;
      font-size: 0.9rem;
    }
    .slot-card {
      display: grid;
      grid-template-columns: 86px minmax(0, 1fr) auto;
      gap: 14px;
      align-items: center;
      padding: 14px;
      margin-bottom: 10px;
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 8px;
    }
    .slot-thumb {
      width: 86px;
      height: 64px;
      object-fit: cover;
      border-radius: 6px;
      border: 1px solid var(--border);
      background: var(--surface-warm);
    }
    .slot-title-row {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin-bottom: 4px;
    }
    .slot-title {
      color: var(--brand-dark);
      font-size: 1rem;
      font-weight: 900;
      line-height: 1.25;
    }
    .slot-meta {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      color: var(--text-muted);
      font-size: 0.78rem;
    }
    .slot-badge {
      display: inline-flex;
      align-items: center;
      min-height: 22px;
      padding: 3px 8px;
      border-radius: 999px;
      background: var(--surface-soft);
      border: 1px solid var(--border);
      color: var(--brand-dark);
      font-size: 0.72rem;
      font-weight: 900;
      text-transform: uppercase;
    }
    .slot-badge.ok {
      background: #e9f8ef;
      border-color: #9bd5af;
      color: #008a20;
    }
    .slot-badge.warn {
      background: #fff8e5;
      border-color: #f0b849;
      color: #996800;
    }
    .slot-actions {
      display: flex;
      gap: 8px;
      justify-content: flex-end;
      flex-wrap: wrap;
    }
    .slot-empty {
      padding: 18px;
      border: 1px dashed var(--border-strong);
      border-radius: 8px;
      color: var(--text-muted);
      background: var(--surface-soft);
    }
    .slot-pagination {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 16px;
    }
    @media (max-width: 860px) {
      .admin-header,
      .slot-card {
        align-items: flex-start;
        grid-template-columns: 1fr;
      }
      .slot-filters {
        grid-template-columns: 1fr;
      }
      .slot-actions,
      .slot-pagination {
        justify-content: flex-start;
      }
    }
  </style>
</head>
<body>
  <div class="page-shell">
    <?php require __DIR__ . '/partials/admin-header.php'; ?>

    <main id="main-content" style="padding: 20px 16px;">
      <div class="admin-container">
        <div class="admin-header">
          <div>
            <h1 style="font-size:1.6rem; color:var(--brand-dark);">Slots</h1>
            <p style="font-size:0.88rem; color:var(--text-muted);">Browse imported SlotsLaunch games, providers, and publish status.</p>
          </div>
          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="/admin/slots.php" class="btn btn-secondary btn-sm">Reset</a>
          </div>
        </div>

        <div class="slot-metrics">
          <div class="slot-metric"><strong><?= number_format($total) ?></strong><span>Filtered Slots</span></div>
          <div class="slot-metric"><strong><?= number_format((int) $pdo->query('SELECT COUNT(*) FROM games')->fetchColumn()) ?></strong><span>Total Slots</span></div>
          <div class="slot-metric"><strong><?= number_format($publishedCount) ?></strong><span>Published</span></div>
          <div class="slot-metric"><strong><?= number_format($featuredCount) ?></strong><span>Featured</span></div>
        </div>

        <form class="slot-filters" method="get" action="/admin/slots.php">
          <input class="slot-input" type="search" name="search" value="<?= h($search) ?>" placeholder="Search name, slug, provider, type...">
          <select class="slot-select" name="provider">
            <option value="">All providers</option>
            <?php foreach ($providers as $row): ?>
              <option value="<?= h($row['provider_slug']) ?>" <?= $provider === (string) $row['provider_slug'] ? 'selected' : '' ?>>
                <?= h($row['provider'] ?: $row['provider_slug']) ?> (<?= (int) $row['total'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <select class="slot-select" name="type">
            <option value="">All types</option>
            <?php foreach ($types as $row): ?>
              <option value="<?= h($row['type_slug']) ?>" <?= $type === (string) $row['type_slug'] ? 'selected' : '' ?>>
                <?= h($row['type'] ?: $row['type_slug']) ?> (<?= (int) $row['total'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <select class="slot-select" name="published">
            <option value="">Any status</option>
            <option value="1" <?= $published === '1' ? 'selected' : '' ?>>Published</option>
            <option value="0" <?= $published === '0' ? 'selected' : '' ?>>Unpublished</option>
          </select>
          <button class="btn btn-primary btn-sm" type="submit">Filter</button>
        </form>

        <?php if ($slots === []): ?>
          <div class="slot-empty">No slots found. Import games with <code>php scripts/import-slotslaunch-games.php</code>.</div>
        <?php else: ?>
          <?php foreach ($slots as $slot): ?>
            <?php
              $themes = json_decode((string) ($slot['themes'] ?? '[]'), true);
              $themes = is_array($themes) ? array_slice($themes, 0, 4) : [];
            ?>
            <article class="slot-card">
              <img class="slot-thumb" src="<?= h($slot['thumb'] ?: '/uploads/blogs/default-featured.svg') ?>" alt="<?= h($slot['name']) ?>" loading="lazy" decoding="async">
              <div>
                <div class="slot-title-row">
                  <span class="slot-title"><?= h($slot['name']) ?></span>
                  <span class="slot-badge <?= (int) $slot['published'] === 1 ? 'ok' : 'warn' ?>"><?= (int) $slot['published'] === 1 ? 'Published' : 'Unpublished' ?></span>
                  <?php if ((int) $slot['featured'] === 1): ?><span class="slot-badge">Featured</span><?php endif; ?>
                  <?php if ((int) $slot['upcoming'] === 1): ?><span class="slot-badge warn">Upcoming</span><?php endif; ?>
                </div>
                <div class="slot-meta">
                  <span>ID: <?= (int) $slot['api_id'] ?></span>
                  <span>Slug: <?= h($slot['slug']) ?></span>
                  <span>Provider: <?= h($slot['provider'] ?: '-') ?></span>
                  <span>Type: <?= h($slot['type'] ?: '-') ?></span>
                  <?php if ($slot['rtp'] !== null && $slot['rtp'] !== ''): ?><span>RTP: <?= h($slot['rtp']) ?>%</span><?php endif; ?>
                  <?php if ($slot['volatility'] !== ''): ?><span>Volatility: <?= h($slot['volatility']) ?></span><?php endif; ?>
                  <?php if ($slot['paylines'] !== ''): ?><span>Paylines: <?= h($slot['paylines']) ?></span><?php endif; ?>
                  <?php if ($slot['release'] !== ''): ?><span>Release: <?= h($slot['release']) ?></span><?php endif; ?>
                </div>
                <?php if ($themes !== []): ?>
                  <div class="slot-meta" style="margin-top:8px;">
                    <?php foreach ($themes as $theme): ?><span class="slot-badge"><?= h($theme) ?></span><?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
              <div class="slot-actions">
                <a class="btn btn-primary btn-sm" href="/game/<?= h(rawurlencode((string) $slot['slug'])) ?>/" target="_blank" rel="noopener">View</a>
                <?php if ($slot['url'] !== ''): ?>
                  <a class="btn btn-secondary btn-sm" href="<?= h($slot['url']) ?>" target="_blank" rel="noopener nofollow">Open</a>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($totalPages > 1): ?>
          <nav class="slot-pagination" aria-label="Slots pagination">
            <?php if ($page > 1): ?><a class="btn btn-secondary btn-sm" href="<?= h(admin_slots_page_url(1)) ?>">First</a><?php endif; ?>
            <?php if ($page > 1): ?><a class="btn btn-secondary btn-sm" href="<?= h(admin_slots_page_url($page - 1)) ?>">Previous</a><?php endif; ?>
            <span style="color:var(--text-muted); font-size:0.86rem;">Page <?= number_format($page) ?> of <?= number_format($totalPages) ?></span>
            <?php if ($page < $totalPages): ?><a class="btn btn-secondary btn-sm" href="<?= h(admin_slots_page_url($page + 1)) ?>">Next</a><?php endif; ?>
            <?php if ($page < $totalPages): ?><a class="btn btn-secondary btn-sm" href="<?= h(admin_slots_page_url($totalPages)) ?>">Last</a><?php endif; ?>
          </nav>
        <?php endif; ?>
      </div>
    </main>
  </div>
</body>
</html>
