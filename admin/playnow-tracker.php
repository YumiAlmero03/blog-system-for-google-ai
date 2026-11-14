<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/blog-storage.php';
require_once __DIR__ . '/includes/admin-date.php';

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

function tracker_top(array $items): array
{
    arsort($items);
    return $items;
}

function tracker_recent(array $data): array
{
    $recent = isset($data['recent']) && is_array($data['recent']) ? $data['recent'] : [];
    return array_reverse($recent);
}

function tracker_period(string $period): array
{
    $tz = admin_display_timezone();
    $now = new DateTimeImmutable('now', $tz);
    return match ($period) {
        'today' => [$now->setTime(0, 0), $now->modify('+1 day')->setTime(0, 0)],
        'yesterday' => [$now->modify('-1 day')->setTime(0, 0), $now->setTime(0, 0)],
        'this-week' => [$now->modify('monday this week')->setTime(0, 0), $now->modify('+1 week')->modify('monday this week')->setTime(0, 0)],
        'this-month' => [$now->modify('first day of this month')->setTime(0, 0), $now->modify('first day of next month')->setTime(0, 0)],
        'last-month' => [$now->modify('first day of last month')->setTime(0, 0), $now->modify('first day of this month')->setTime(0, 0)],
        default => [null, null],
    };
}

function tracker_event_date(array $event): ?DateTimeImmutable
{
    try {
        $value = $event['time'] ?? $event['timestamp'] ?? null;
        if ($value === null || $value === '') return null;
        return is_numeric($value) ? (new DateTimeImmutable('@' . $value))->setTimezone(admin_display_timezone()) : (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->setTimezone(admin_display_timezone());
    } catch (Exception) { return null; }
}

function tracker_filtered(array $data, string $period): array
{
    if ($period === 'all') return tracker_recent($data);
    [$from, $to] = tracker_period($period);
    $events = [];
    foreach (tracker_recent($data) as $event) {
        $date = tracker_event_date($event);
        if ($date !== null && $from !== null && $date >= $from && $date < $to) $events[] = $event;
    }
    return $events;
}

function tracker_aggregate(array $events): array
{
    $result = ['byPage' => [], 'byButton' => [], 'byIp' => [], 'byTarget' => []];
    foreach ($events as $event) {
        foreach (['byPage' => ($event['pagePath'] ?? ($event['pageUrl'] ?? '')), 'byButton' => ($event['buttonKey'] ?? ($event['buttonText'] ?? '')), 'byIp' => ($event['ip'] ?? ''), 'byTarget' => ($event['targetUrl'] ?? '')] as $bucket => $value) {
            $result[$bucket][$value] = (int) ($result[$bucket][$value] ?? 0) + 1;
        }
    }
    return $result;
}

$data = tracker_data();
$period = (string) ($_GET['period'] ?? 'all');
$periods = ['today' => 'Today', 'yesterday' => 'Yesterday', 'this-week' => 'This week', 'this-month' => 'This month', 'last-month' => 'Last month', 'all' => 'All'];
if (!isset($periods[$period])) $period = 'all';
$filteredEvents = tracker_filtered($data, $period);
$filtered = tracker_aggregate($filteredEvents);
$totalClicks = $period === 'all' ? (int) ($data['totalClicks'] ?? 0) : count($filteredEvents);
$updatedAt = (string) ($data['updatedAt'] ?? '');
$byPage = $period === 'all' && is_array($data['byPage'] ?? null) ? $data['byPage'] : $filtered['byPage'];
$byButton = $period === 'all' && is_array($data['byButton'] ?? null) ? $data['byButton'] : $filtered['byButton'];
$byIp = $period === 'all' && is_array($data['byIp'] ?? null) ? $data['byIp'] : $filtered['byIp'];
$byTarget = $period === 'all' && is_array($data['byTarget'] ?? null) ? $data['byTarget'] : $filtered['byTarget'];
$recent = $filteredEvents;
$notice = (string) ($_SESSION['tracker_notice'] ?? '');
unset($_SESSION['tracker_notice']);
?>
<!DOCTYPE html>
<html lang="en-PH">
<head>
  <meta name="robots" content="noindex, nofollow">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Play Now Tracker | Admin</title>
  <link rel="preload" href="/admin/style.css" as="style"><link rel="stylesheet" href="/admin/style.css">
  <link rel="icon" href="/assets/favicon.svg">
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
    .promo-form input,
    .promo-form textarea {
      width: 100%;
      box-sizing: border-box;
      padding: 9px;
      border: 1px solid var(--border);
      border-radius: 6px;
      font: inherit;
    }
    .promo-form textarea { min-height: 72px; resize: vertical; }
    .promo-form label { display: block; margin-top: 10px; font-size: .82rem; font-weight: 800; }
    .promo-form button { margin-top: 12px; }
    .promo-image-preview { display:block; max-width:180px; max-height:100px; margin-top:8px; border:1px solid var(--border); border-radius:6px; object-fit:cover; }
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

        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px;">
          <?php foreach ($periods as $key => $label): ?><a class="btn <?= $period === $key ? 'btn-primary' : 'btn-secondary' ?> btn-sm" href="?period=<?= h($key) ?>"><?= h($label) ?></a><?php endforeach; ?>
          <a class="btn btn-secondary btn-sm" href="/admin/playnow-promos.php">Manage promo links</a>
        </div>
        <p style="font-size:0.82rem; color:var(--text-muted); margin:0 0 18px;">Showing: <?= h($periods[$period]) ?><?= $period !== 'all' ? ' (based on recorded click events)' : '' ?></p>

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
            <table class="tracker-table" data-paginate>
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
            <table class="tracker-table" data-paginate>
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
            <table class="tracker-table" data-paginate>
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
            <table class="tracker-table" data-paginate>
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
            <p style="font-size:0.82rem; color:var(--text-muted);">Last updated: <?= h($updatedAt !== '' ? (admin_format_date($updatedAt) ?: $updatedAt) : 'No clicks yet') ?></p>
            <table class="tracker-table" data-paginate>
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
                    <td><time datetime="<?= h($event['time'] ?? '') ?>"><?= h(admin_format_date($event['time'] ?? '') ?: ($event['time'] ?? '')) ?></time></td>
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
  <script>
    document.querySelectorAll('.promo-image-upload').forEach((input) => {
      input.addEventListener('change', async () => {
        const file = input.files && input.files[0];
        const form = input.closest('form');
        const status = form?.querySelector('.promo-image-status');
        const preview = form?.querySelector('.promo-image-preview');
        const hidden = form?.querySelector('input[name="og_image"]');
        const csrf = form?.querySelector('input[name="csrf_token"]');
        if (!file || !form || !status || !preview || !hidden || !csrf) return;
        status.textContent = 'Uploading image...';
        const data = new FormData();
        data.append('image', file);
        data.append('csrf_token', csrf.value);
        try {
          const response = await fetch('/admin/upload-image.php', { method: 'POST', body: data, credentials: 'same-origin' });
          const result = await response.json();
          if (!response.ok || !result.ok || !result.path) throw new Error(result.error || 'Image upload failed.');
          hidden.value = result.path;
          preview.src = result.path;
          preview.hidden = false;
          status.textContent = 'Image uploaded.';
          if (result.csrfToken) {
            document.querySelectorAll('input[name="csrf_token"]').forEach((field) => { field.value = result.csrfToken; });
          }
        } catch (error) {
          status.textContent = error.message || 'Image upload failed.';
        } finally {
          input.value = '';
        }
      });
    });
  </script>
</body>
</html>
