<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/blog-storage.php';

require_auth();

function tracker_path(): string
{
    return blog_storage_dir() . '/playnow-clicks.json';
}

function tracker_data(): array
{
    $path = tracker_path();
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    $data = json_decode($raw !== false ? $raw : '', true);
    return is_array($data) ? $data : [];
}

function tracker_top(array $items, int $limit = 12): array
{
    arsort($items);
    return array_slice($items, 0, $limit, true);
}

function tracker_recent(array $data, int $limit = 80): array
{
    $recent = isset($data['recent']) && is_array($data['recent']) ? $data['recent'] : [];
    return array_reverse(array_slice($recent, -$limit));
}

$data = tracker_data();
$totalClicks = (int) ($data['totalClicks'] ?? 0);
$updatedAt = (string) ($data['updatedAt'] ?? '');
$byPage = isset($data['byPage']) && is_array($data['byPage']) ? $data['byPage'] : [];
$byButton = isset($data['byButton']) && is_array($data['byButton']) ? $data['byButton'] : [];
$byIp = isset($data['byIp']) && is_array($data['byIp']) ? $data['byIp'] : [];
$byTarget = isset($data['byTarget']) && is_array($data['byTarget']) ? $data['byTarget'] : [];
$recent = tracker_recent($data);
?>
<!DOCTYPE html>
<html lang="en-PH">
<head>
  <meta name="robots" content="noindex, nofollow">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Play Now Tracker | GperyaPH Admin</title>
  <link rel="preload" href="/admin/style.css" as="style"><link rel="stylesheet" href="/admin/style.css">
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
      margin-bottom: 24px;
      padding-bottom: 16px;
      border-bottom: 2px solid var(--border);
    }
    .metric-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 12px;
      margin-bottom: 18px;
    }
    .metric-card,
    .tracker-card {
      background: var(--surface-soft);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 16px;
    }
    .metric-card strong {
      display: block;
      font-size: 1.8rem;
      color: var(--brand-dark);
      line-height: 1.1;
    }
    .metric-card span {
      display: block;
      color: var(--text-muted);
      font-size: 0.82rem;
      margin-top: 4px;
    }
    .tracker-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 16px;
    }
    .tracker-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 10px;
      background: #fff;
      border: 1px solid var(--border);
      font-size: 0.84rem;
    }
    .tracker-table th,
    .tracker-table td {
      border-bottom: 1px solid var(--border);
      padding: 9px;
      text-align: left;
      vertical-align: top;
    }
    .tracker-table th {
      background: #fff8ea;
      color: var(--brand-dark);
      font-weight: 900;
    }
    .value-cell {
      overflow-wrap: anywhere;
      word-break: break-word;
    }
    .recent-card {
      grid-column: 1 / -1;
    }
    @media (max-width: 860px) {
      .admin-header,
      .tracker-grid {
        grid-template-columns: 1fr;
      }
      .admin-header {
        flex-direction: column;
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
            <h1 style="font-size:1.6rem; color:var(--brand-dark);">Play Now Tracker</h1>
            <p style="font-size:0.88rem; color:var(--text-muted);">Track every Play Now click, button label, source page, target URL, and user IP.</p>
          </div>
          <a href="/admin/blogs.php" class="btn btn-secondary btn-sm">Back to Blogs</a>
        </div>

        <div class="metric-grid">
          <div class="metric-card">
            <strong><?= h(number_format($totalClicks)) ?></strong>
            <span>Total tracked clicks</span>
          </div>
          <div class="metric-card">
            <strong><?= h(number_format(count($byPage))) ?></strong>
            <span>Source pages</span>
          </div>
          <div class="metric-card">
            <strong><?= h(number_format(count($byButton))) ?></strong>
            <span>Button labels</span>
          </div>
          <div class="metric-card">
            <strong><?= h(number_format(count($byIp))) ?></strong>
            <span>User IPs</span>
          </div>
        </div>

        <div class="tracker-grid">
          <section class="tracker-card">
            <h2 style="font-size:1.05rem; color:var(--brand-dark);">Top Pages</h2>
            <table class="tracker-table">
              <thead><tr><th>Page</th><th>Clicks</th></tr></thead>
              <tbody>
                <?php foreach (tracker_top($byPage) as $value => $count): ?>
                  <tr><td class="value-cell"><?= h($value) ?></td><td><?= (int) $count ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </section>

          <section class="tracker-card">
            <h2 style="font-size:1.05rem; color:var(--brand-dark);">Top Buttons</h2>
            <table class="tracker-table">
              <thead><tr><th>Button</th><th>Clicks</th></tr></thead>
              <tbody>
                <?php foreach (tracker_top($byButton) as $value => $count): ?>
                  <tr><td class="value-cell"><?= h($value) ?></td><td><?= (int) $count ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </section>

          <section class="tracker-card">
            <h2 style="font-size:1.05rem; color:var(--brand-dark);">Top Targets</h2>
            <table class="tracker-table">
              <thead><tr><th>Target URL</th><th>Clicks</th></tr></thead>
              <tbody>
                <?php foreach (tracker_top($byTarget) as $value => $count): ?>
                  <tr><td class="value-cell"><?= h($value) ?></td><td><?= (int) $count ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </section>

          <section class="tracker-card">
            <h2 style="font-size:1.05rem; color:var(--brand-dark);">Top IPs</h2>
            <table class="tracker-table">
              <thead><tr><th>IP</th><th>Clicks</th></tr></thead>
              <tbody>
                <?php foreach (tracker_top($byIp) as $value => $count): ?>
                  <tr><td class="value-cell"><?= h($value) ?></td><td><?= (int) $count ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </section>

          <section class="tracker-card recent-card">
            <h2 style="font-size:1.05rem; color:var(--brand-dark);">Recent Clicks</h2>
            <p style="font-size:0.82rem; color:var(--text-muted);">Last updated: <?= h($updatedAt !== '' ? $updatedAt : 'No clicks yet') ?></p>
            <table class="tracker-table">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>Page</th>
                  <th>Button</th>
                  <th>Section</th>
                  <th>IP</th>
                  <th>Target</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recent as $event): ?>
                  <tr>
                    <td><?= h($event['time'] ?? '') ?></td>
                    <td class="value-cell"><?= h($event['pagePath'] ?? ($event['pageUrl'] ?? '')) ?></td>
                    <td class="value-cell"><?= h($event['buttonText'] ?? '') ?></td>
                    <td class="value-cell"><?= h($event['section'] ?? ($event['location'] ?? '')) ?></td>
                    <td><?= h($event['ip'] ?? '') ?></td>
                    <td class="value-cell"><?= h($event['targetUrl'] ?? '') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </section>
        </div>
      </div>
    </main>
  </div>
</body>
</html>
