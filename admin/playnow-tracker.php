<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/blog-storage.php';
require_once __DIR__ . '/../includes/playnow-promos.php';
require_once __DIR__ . '/../includes/admin-date.php';

require_auth();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && csrf_validate(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
  $promoCode = isset($_POST['promo_code']) && is_string($_POST['promo_code']) ? trim($_POST['promo_code']) : '';
  $newPromoCode = isset($_POST['new_promo_code']) && is_string($_POST['new_promo_code']) ? trim($_POST['new_promo_code']) : '';
  $customPromoCode = isset($_POST['custom_promo_code']) && is_string($_POST['custom_promo_code']) ? trim($_POST['custom_promo_code']) : '';
  try {
  if (strlen($promoCode) > 64 || strlen($newPromoCode) > 64 || strlen($customPromoCode) > 64) {
    throw new InvalidArgumentException('Promo code must be 8-64 characters using only letters, numbers, hyphens, or underscores.');
  }
  $targetUrl = request_string('target_url', 500) ?? '';
  $ogTitle = request_string('og_title', 180) ?? '';
  $ogDescription = request_string('og_description', 300) ?? '';
  $ogImage = request_string('og_image', 500) ?? '';
    if ($promoCode !== '') {
      $saved = playnow_promo_update($promoCode, $targetUrl, $ogTitle, $ogDescription, $ogImage, $newPromoCode);
      $_SESSION['tracker_notice'] = $saved ? 'Promo link updated.' : 'Promo link not found.';
    } else {
      $created = playnow_promo_create($targetUrl, $ogTitle, $ogDescription, $ogImage, $customPromoCode);
      $_SESSION['tracker_notice'] = 'Promo link created: /promo-code/' . $created['code'];
    }
  } catch (Throwable $error) {
    $_SESSION['tracker_notice'] = $error->getMessage();
  }
  header('Location: /admin/playnow-tracker.php');
  exit;
}

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
$promos = isset($data['promos']) && is_array($data['promos']) ? $data['promos'] : [];
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

        <?php if ($notice !== ''): ?><p class="notice ok"><?= h($notice) ?></p><?php endif; ?>

        <section class="tracker-card" style="margin-bottom:16px;">
          <h2 style="font-size:1.05rem; color:var(--brand-dark);">Promo links</h2>
          <form method="post" class="promo-form">
            <?= csrf_input() ?>
            <input type="hidden" name="promo_code" value="">
            <label>Custom promo code<input name="custom_promo_code" type="text" maxlength="64" placeholder="Optional, 8-64 URL-safe characters"></label>
            <label>Destination URL<input name="target_url" type="text" value="/playnow" required maxlength="500"></label>
            <label>OG Title<input name="og_title" type="text" maxlength="180" placeholder="Play Now"></label>
            <label>OG Description<textarea name="og_description" maxlength="300" placeholder="Open Play Now"></textarea></label>
            <label>OG Image<input class="promo-image-upload" type="file" accept="image/jpeg,image/png,image/webp"><input name="og_image" type="hidden" value=""><span class="promo-image-status" role="status"></span><img class="promo-image-preview" alt="OG image preview" hidden></label>
            <button class="btn btn-primary btn-sm" type="submit">Generate promo link</button>
          </form>
          <?php if ($promos !== []): ?>
            <table class="tracker-table">
              <thead><tr><th>Promo URL</th><th>Destination</th><th>Clicks</th><th>Last click</th><th>Edit</th></tr></thead>
              <tbody>
                <?php foreach ($promos as $promo): ?>
                  <?php if (!is_array($promo)) continue; ?>
                  <tr>
                    <td class="value-cell"><a href="/promo-code/<?= h($promo['code'] ?? '') ?>?preview=1" target="_blank" rel="noopener">/promo-code/<?= h($promo['code'] ?? '') ?></a></td>
                    <td class="value-cell"><?= h($promo['targetUrl'] ?? '') ?></td>
                    <td><?= (int) ($promo['clicks'] ?? 0) ?></td>
                    <td><time datetime="<?= h($promo['lastClickAt'] ?? '') ?>"><?= h(admin_format_date($promo['lastClickAt'] ?? '') ?: ($promo['lastClickAt'] ?? '')) ?></time></td>
                    <td><details><summary>Edit</summary><form method="post" class="promo-form">
                      <?= csrf_input() ?><input type="hidden" name="promo_code" value="<?= h($promo['code'] ?? '') ?>">
                      <label>Promo code<input name="new_promo_code" value="<?= h($promo['code'] ?? '') ?>" required maxlength="64"></label>
                      <label>Destination URL<input name="target_url" value="<?= h($promo['targetUrl'] ?? '/playnow') ?>" required maxlength="500"></label>
                      <label>OG Title<input name="og_title" value="<?= h($promo['ogTitle'] ?? '') ?>" maxlength="180"></label>
                      <label>OG Description<textarea name="og_description" maxlength="300"><?= h($promo['ogDescription'] ?? '') ?></textarea></label>
                      <label>OG Image<input class="promo-image-upload" type="file" accept="image/jpeg,image/png,image/webp"><input name="og_image" type="hidden" value="<?= h($promo['ogImage'] ?? '') ?>"><span class="promo-image-status" role="status"></span><?php if (($promo['ogImage'] ?? '') !== ''): ?><img class="promo-image-preview" src="<?= h($promo['ogImage']) ?>" alt="OG image preview"><?php else: ?><img class="promo-image-preview" alt="OG image preview" hidden><?php endif; ?></label>
                      <button class="btn btn-secondary btn-sm" type="submit">Save</button>
                    </form></details></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </section>

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
            <p style="font-size:0.82rem; color:var(--text-muted);">Last updated: <?= h($updatedAt !== '' ? (admin_format_date($updatedAt) ?: $updatedAt) : 'No clicks yet') ?></p>
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
