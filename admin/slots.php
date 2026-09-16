<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/blog-storage.php';
require_once __DIR__ . '/../includes/admin-date.php';

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

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_valid_csrf();

    $slotId = isset($_POST['slot_id']) && is_string($_POST['slot_id']) && ctype_digit($_POST['slot_id'])
        ? (int) $_POST['slot_id']
        : 0;
    $featuredValue = isset($_POST['featured']) && (string) $_POST['featured'] === '1' ? 1 : 0;

    if (($_POST['action'] ?? '') === 'visibility') {
        try {
            if ($slotId <= 0) throw new InvalidArgumentException('Invalid game.');
            $value = game_visibility_value($_POST['is_viewable'] ?? null);
            $stmt = $pdo->prepare('UPDATE games SET is_viewable=?,updated_at=? WHERE id=?');
            $stmt->execute([$value,time(),$slotId]);
            if (!$stmt->rowCount()) throw new InvalidArgumentException('Game not found.');
            game_visibility_refresh();
        } catch (InvalidArgumentException $error) {
            http_response_code(422); echo h($error->getMessage()); exit;
        } catch (Throwable $error) {
            http_response_code(500); echo h('Visibility could not be fully applied. Check the game setting and retry to refresh sitemaps.'); exit;
        }
    } elseif ($slotId > 0) {

        $stmt = $pdo->prepare('UPDATE games SET featured = :featured, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':featured' => $featuredValue,
            ':updated_at' => time(),
            ':id' => $slotId,
        ]);
    }

    csrf_rotate();
    $redirect = '/admin/slots.php';
    if (isset($_POST['return_to']) && is_string($_POST['return_to']) && str_starts_with($_POST['return_to'], '/admin/slots.php')) {
        $redirect = $_POST['return_to'];
    }
    header('Location: ' . $redirect, true, 303);
    exit;
}

$search = admin_slots_get_string('search');
$provider = admin_slots_get_string('provider');
$type = admin_slots_get_string('type');
$published = admin_slots_get_string('published', 16);
$featured = admin_slots_get_string('featured', 16);
$updatedDate = admin_slots_get_string('updated_date', 10);
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
if ($featured === '1' || $featured === '0') {
    $where[] = 'featured = :featured';
    $params[':featured'] = (int) $featured;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $updatedDate) === 1) {
  $date = DateTimeImmutable::createFromFormat('!Y-m-d', $updatedDate, admin_display_timezone());
  $dateErrors = DateTimeImmutable::getLastErrors();
  if ($date !== false && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))) {
    $where[] = 'updated_at >= :updated_from AND updated_at < :updated_to';
    $params[':updated_from'] = $date->getTimestamp();
    $params[':updated_to'] = $date->modify('+1 day')->getTimestamp();
  }
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
            themes, rtp, volatility, featured, is_viewable, published, upcoming, release, min_bet,
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
  <title>Slots | Admin</title>
  <link rel="preload" href="/admin/style.css" as="style"><link rel="stylesheet" href="/admin/style.css">
  <link rel="icon" href="/assets/favicon.svg">
  <style>
    body {
      background: #f6f7f9;
    }
    .admin-container {
      max-width: 1200px;
      margin: 24px auto;
      padding: 20px;
      background: #fff;
      border: 1px solid #e4e7ec;
      border-radius: 8px;
    }
    .admin-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      margin-bottom: 20px;
      padding-bottom: 14px;
      border-bottom: 1px solid #e4e7ec;
    }
    .slot-metrics {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 10px;
      margin-bottom: 16px;
    }
    .slot-metric {
      padding: 12px;
      background: #fafafa;
      border: 1px solid #e4e7ec;
      border-radius: 8px;
    }
    .slot-metric strong {
      display: block;
      color: #111827;
      font-size: 1.25rem;
      line-height: 1.1;
    }
    .slot-metric span {
      display: block;
      margin-top: 4px;
      color: #667085;
      font-size: 0.78rem;
      font-weight: 700;
    }
    .slot-filters {
      display: grid;
      grid-template-columns: minmax(180px, 1fr) 180px 160px 140px 140px 150px auto;
      gap: 8px;
      align-items: center;
      margin-bottom: 16px;
    }
    .slot-input,
    .slot-select {
      width: 100%;
      min-height: 36px;
      padding: 8px 10px;
      border: 1px solid #d0d5dd;
      border-radius: 6px;
      background: #fff;
      color: #101828;
      font: inherit;
      font-size: 0.88rem;
    }
    .slot-card {
      display: grid;
      grid-template-columns: 72px minmax(0, 1fr) auto;
      gap: 12px;
      align-items: center;
      padding: 12px;
      margin-bottom: 8px;
      background: #fff;
      border: 1px solid #e4e7ec;
      border-radius: 8px;
    }
    .slot-thumb {
      width: 72px;
      height: 54px;
      object-fit: cover;
      border-radius: 6px;
      border: 1px solid #e4e7ec;
      background: #f2f4f7;
    }
    .slot-title-row {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin-bottom: 4px;
    }
    .slot-title {
      color: #101828;
      font-size: 0.96rem;
      font-weight: 800;
      line-height: 1.25;
    }
    .slot-meta {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      color: #667085;
      font-size: 0.78rem;
    }
    .slot-badge {
      display: inline-flex;
      align-items: center;
      min-height: 20px;
      padding: 2px 7px;
      border-radius: 6px;
      background: #f2f4f7;
      border: 1px solid #e4e7ec;
      color: #344054;
      font-size: 0.7rem;
      font-weight: 800;
      text-transform: uppercase;
    }
    .slot-badge.ok {
      background: #ecfdf3;
      border-color: #abefc6;
      color: #067647;
    }
    .slot-badge.warn {
      background: #fffaeb;
      border-color: #fedf89;
      color: #b54708;
    }
    .slot-actions {
      display: flex;
      gap: 8px;
      justify-content: flex-end;
      flex-wrap: wrap;
    }
    .slot-featured-form {
      margin: 0;
    }
    .slot-featured-label {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      min-height: 32px;
      padding: 5px 9px;
      border: 1px solid #d0d5dd;
      border-radius: 6px;
      background: #fff;
      color: #344054;
      font-size: 0.78rem;
      font-weight: 800;
      cursor: pointer;
    }
    .slot-featured-label input {
      width: 16px;
      height: 16px;
      accent-color: #a62f3d;
      cursor: pointer;
    }
    .slot-empty {
      padding: 18px;
      border: 1px dashed #d0d5dd;
      border-radius: 8px;
      color: #667085;
      background: #fafafa;
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
          <select class="slot-select" name="featured">
            <option value="">Any featured</option>
            <option value="1" <?= $featured === '1' ? 'selected' : '' ?>>Featured</option>
            <option value="0" <?= $featured === '0' ? 'selected' : '' ?>>Not featured</option>
          </select>
          <input class="slot-input" type="date" name="updated_date" value="<?= h($updatedDate) ?>" aria-label="Updated At">
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
                  <span>Updated At: <time datetime="<?= h((string) $slot['updated_at']) ?>"><?= h(admin_format_date($slot['updated_at'])) ?></time></span>
                </div>
                <?php if ($themes !== []): ?>
                  <div class="slot-meta" style="margin-top:8px;">
                    <?php foreach ($themes as $theme): ?><span class="slot-badge"><?= h($theme) ?></span><?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
              <div class="slot-actions">
                <form class="slot-featured-form" method="post" action="/admin/slots.php">
                  <?= csrf_input() ?>
                  <input type="hidden" name="action" value="visibility">
                  <input type="hidden" name="slot_id" value="<?= (int)$slot['id'] ?>">
                  <input type="hidden" name="return_to" value="<?= h($_SERVER['REQUEST_URI'] ?? '/admin/slots.php') ?>">
                  <input type="hidden" name="is_viewable" value="0">
                  <label class="slot-featured-label"><input type="checkbox" name="is_viewable" value="1" <?= (int)$slot['is_viewable'] === 1 ? 'checked' : '' ?> onchange="this.form.submit()"> Viewable</label>
                </form>

                <form class="slot-featured-form" method="post" action="/admin/slots.php">
                  <?= csrf_input() ?>
                  <input type="hidden" name="slot_id" value="<?= (int) $slot['id'] ?>">
                  <input type="hidden" name="return_to" value="<?= h($_SERVER['REQUEST_URI'] ?? '/admin/slots.php') ?>">
                  <input type="hidden" name="featured" value="0">
                  <label class="slot-featured-label">
                    <input type="checkbox" name="featured" value="1" <?= (int) $slot['featured'] === 1 ? 'checked' : '' ?> onchange="this.form.submit()">
                    Featured
                  </label>
                </form>
                <a class="btn btn-secondary btn-sm" href="/admin/slot-edit.php?id=<?= h($slot['id']) ?>">Edit Content</a>
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
