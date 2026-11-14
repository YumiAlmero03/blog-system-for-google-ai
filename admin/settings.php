<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/blog-storage.php';
require_once __DIR__ . '/includes/chat-realtime.php';
require_once __DIR__ . '/includes/default-image.php';

require_auth();

require_once __DIR__ . '/includes/settings-replace.php';

function settings_post_value(string $key, int $maxLength): string
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) {
        return '';
    }

    $value = trim((string) $_POST[$key]);
    return strlen($value) > $maxLength ? substr($value, 0, $maxLength) : $value;
}

$find = '';
$replace = '';
$database = 'blogs';
$result = null;
$notice = '';
$noticeType = '';
$websiteTitle = blog_website_title();
$chatAiName = chat_ai_name();
$ignoredEngagementIps = blog_ignored_engagement_ips();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_valid_csrf();
    $action = settings_post_value('action', 64);

    if ($action === 'save_default_image' || $action === 'clear_default_image') {
        require_admin();
        try {
            if ($action === 'clear_default_image') blog_setting_set('default_image', '');
            else site_default_image_upload(is_array($_FILES['default_image'] ?? null) ? $_FILES['default_image'] : []);
            $notice = $action === 'clear_default_image' ? 'Default image removed.' : 'Default image saved.';
            $noticeType = 'ok';
            csrf_rotate();
        } catch (InvalidArgumentException $exception) {
            $notice = $exception->getMessage();
            $noticeType = 'error';
        } catch (Throwable $exception) {
            error_log('Default image upload failed: '.$exception->getMessage());
            $notice = 'Default image could not be saved.';
            $noticeType = 'error';
        }
    } elseif ($action === 'save_chat_name') {
        $chatAiName = chat_clean_string(settings_post_value('chat_ai_name', 80), 80);
        if ($chatAiName === '') {
            $notice = 'Chat name is required.';
            $noticeType = 'error';
        } else {
            try {
                blog_setting_set('chat_ai_name', $chatAiName, 80);
                $chatAiName = chat_ai_name();
                $notice = 'Chat name saved.';
                $noticeType = 'ok';
                csrf_rotate();
            } catch (Throwable $exception) {
                error_log('Settings chat name error: ' . $exception->getMessage());
                $notice = 'Chat name could not be saved.';
                $noticeType = 'error';
            }
        }
    } elseif ($action === 'save_website_title') {
        $websiteTitle = settings_post_value('website_title', 120);
        if ($websiteTitle === '') {
            $notice = 'Website title is required.';
            $noticeType = 'error';
        } else {
            try {
                blog_setting_set('website_title', $websiteTitle, 120);
                $websiteTitle = blog_website_title();
                $notice = 'Website title saved.';
                $noticeType = 'ok';
                csrf_rotate();
            } catch (Throwable $exception) {
                error_log('Settings website title error: ' . $exception->getMessage());
                $notice = 'Website title could not be saved.';
                $noticeType = 'error';
            }
        }
    } elseif ($action === 'save_ignored_engagement_ips') {
        $ignoredEngagementIps = settings_post_value('ignored_engagement_ips', 4000);
        try {
            blog_setting_set_multiline('ignored_engagement_ips', $ignoredEngagementIps, 4000);
            $ignoredEngagementIps = blog_ignored_engagement_ips();
            $notice = 'Ignored IP addresses saved.';
            $noticeType = 'ok';
            csrf_rotate();
        } catch (Throwable $exception) {
            error_log('Settings ignored IP error: ' . $exception->getMessage());
            $notice = 'Ignored IP addresses could not be saved.';
            $noticeType = 'error';
        }
    } elseif ($action === 'preview' || $action === 'replace') {
        $find = settings_post_value('find', 2000);
        $replace = settings_post_value('replace', 2000);
        $database = settings_post_value('database', 40) ?: 'blogs';

        if ($find === '') {
            $notice = 'Find text is required.';
            $noticeType = 'error';
        } else {
            try {
                if ($action === 'replace') {
                    if (($_POST['confirm_replace'] ?? '') !== '1') {
                        $notice = 'Please confirm before replacing database text.';
                        $noticeType = 'error';
                    } else {
                        $result = settings_apply_replace($find, $replace, $database);
                        $notice = 'Replace complete. Updated ' . (int) $result['totalMatches'] . ' match' . ((int) $result['totalMatches'] === 1 ? '' : 'es') . ' across ' . (int) $result['totalRows'] . ' row' . ((int) $result['totalRows'] === 1 ? '' : 's') . '.';
                        $noticeType = 'ok';
                        csrf_rotate();
                    }
                } else {
                    $result = settings_find_matches($find, $database);
                    $notice = 'Preview found ' . (int) $result['totalMatches'] . ' match' . ((int) $result['totalMatches'] === 1 ? '' : 'es') . ' across ' . (int) $result['totalRows'] . ' row' . ((int) $result['totalRows'] === 1 ? '' : 's') . '.';
                    $noticeType = 'ok';
                }
            } catch (Throwable $exception) {
                error_log('Settings find/replace error: ' . $exception->getMessage());
                $notice = 'Database find/replace is unavailable.';
                $noticeType = 'error';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en-PH">
<head>
  <meta name="robots" content="noindex, nofollow">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Settings | Admin</title>
  <link rel="preload" href="/admin/style.css" as="style"><link rel="stylesheet" href="/admin/style.css">
  <link rel="icon" href="/assets/favicon.svg">
  <style>
    .admin-container {
      max-width: 980px;
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
    .settings-grid {
      display: grid;
      gap: 16px;
    }
    .settings-card {
      border: 1px solid var(--border);
      border-radius: 8px;
      background: var(--surface-soft);
      padding: 16px;
    }
    .form-row {
      display: grid;
      gap: 8px;
      margin-bottom: 14px;
    }
    .form-row label {
      font-weight: 900;
      color: var(--brand-dark);
    }
    .form-row select,
    .form-row textarea,
    .form-row input[type="text"],
    .form-row input[type="password"] {
      width: 100%;
      border: 1px solid var(--border-strong);
      border-radius: var(--radius-sm);
      padding: 10px 12px;
      font: inherit;
      min-height: 44px;
    }
    .form-row textarea {
      min-height: 92px;
      resize: vertical;
    }
    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
      justify-content: flex-end;
    }
    .notice {
      display: none;
      padding: 10px 12px;
      border-radius: var(--radius-sm);
      margin-bottom: 14px;
      font-size: 0.9rem;
    }
    .notice.ok { display: block; background: #e9f8ef; color: #0d6630; border: 1px solid #9bd5af; }
    .notice.error { display: block; background: #fff1f1; color: var(--danger); border: 1px solid #e5aaaa; }
    .summary-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 12px;
      font-size: 0.88rem;
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 8px;
      overflow: hidden;
    }
    .summary-table th,
    .summary-table td {
      padding: 10px;
      border-bottom: 1px solid var(--border);
      text-align: left;
    }
    .summary-table th {
      color: var(--brand-dark);
      background: #fff8ea;
      font-weight: 900;
    }
    .matched-list {
      display: grid;
      gap: 8px;
      margin-top: 12px;
      padding: 0;
      list-style: none;
    }
    .matched-list li {
      padding: 10px;
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 6px;
      font-size: 0.84rem;
    }
    @media (max-width: 760px) {
      .admin-header {
        flex-direction: column;
      }
      .actions {
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
            <h1 style="font-size:1.6rem; color:var(--brand-dark);">Settings</h1>
            <p style="font-size:0.88rem; color:var(--text-muted);">Find and replace text across blog, games, sports, odds, and livescore databases.</p>
          </div>
          <a href="/admin/settings/seo/" class="btn btn-secondary btn-sm">SEO Settings</a>
          <a href="/admin/blogs.php" class="btn btn-secondary btn-sm">Back to Blogs</a>
        </div>

        <?php if ($notice !== ''): ?>
          <div class="notice <?= h($noticeType) ?>" role="status"><?= h($notice) ?></div>
        <?php endif; ?>

        <div class="settings-grid">
          <form method="post" enctype="multipart/form-data" class="settings-card">
            <?= csrf_input() ?>
            <div class="form-row">
              <label for="default-image">Default fallback image</label>
              <p>Used when a blog featured image or sports logo is missing. Existing images stay unchanged.</p>
              <?php if (site_default_image() !== ''): ?>
                <img src="<?= h(site_default_image()) ?>" alt="Current default image" width="160" style="max-height:120px;object-fit:contain;">
              <?php endif; ?>
              <input type="file" id="default-image" name="default_image" accept="image/png,image/jpeg,image/webp,image/avif">
              <small>PNG, JPEG, WebP or AVIF. Maximum 10 MB.</small>
            </div>
            <div class="actions">
              <button type="submit" name="action" value="save_default_image" class="btn btn-primary btn-sm">Upload Default Image</button>
              <?php if (site_default_image() !== ''): ?><button type="submit" name="action" value="clear_default_image" class="btn btn-secondary btn-sm">Remove Default</button><?php endif; ?>
            </div>
          </form>


          <form method="post" class="settings-card">
            <?= csrf_input() ?>
            <div class="form-row">
              <label for="chat-ai-name">AI Chat Name</label>
              <input type="text" id="chat-ai-name" name="chat_ai_name" maxlength="80" value="<?= h($chatAiName) ?>">
              <p style="font-size:0.82rem; color:var(--text-muted); margin:0;">Support waits three minutes before replying automatically. Follow-up replies have no added delay until a human agent takes over.</p>
            </div>
            <div class="actions">
              <button type="submit" name="action" value="save_chat_name" class="btn btn-primary btn-sm">Save Chat Name</button>
            </div>
          </form>
          <form method="post" class="settings-card">
            <?= csrf_input() ?>
            <div class="form-row">
              <label for="website-title">Website Title</label>
              <input type="text" id="website-title" name="website_title" maxlength="120" value="<?= h($websiteTitle) ?>">
            </div>
            <div class="actions">
              <button type="submit" name="action" value="save_website_title" class="btn btn-primary btn-sm">Save Website Title</button>
            </div>
          </form>

          <form method="post" class="settings-card">
            <?= csrf_input() ?>
            <div class="form-row">
              <label for="ignored-engagement-ips">Ignored IP Addresses</label>
              <textarea id="ignored-engagement-ips" name="ignored_engagement_ips" maxlength="4000" placeholder="127.0.0.1&#10;203.0.113.24"><?= h($ignoredEngagementIps) ?></textarea>
              <p style="font-size:0.82rem; color:var(--text-muted); margin:0;">One IP per line, or separate with commas. CIDR ranges such as 203.0.113.0/24 are also supported.</p>
            </div>
            <div class="actions">
              <button type="submit" name="action" value="save_ignored_engagement_ips" class="btn btn-primary btn-sm">Save Ignored IPs</button>
            </div>
          </form>

          <form method="post" class="settings-card">
            <?= csrf_input() ?>
            <div class="form-row">
              <label for="database">Content Database</label>
              <select id="database" name="database">
                <option value="all" <?= $database === 'all' ? 'selected' : '' ?>>All content databases</option>
                <?php foreach (settings_replace_sources() as $key => $source): ?>
                  <option value="<?= h($key) ?>" <?= $database === $key ? 'selected' : '' ?>><?= h($source['label']) ?></option>
                <?php endforeach; ?>
              </select>
              <p style="font-size:0.82rem; color:var(--text-muted); margin:0;">Case-sensitive text matching. Covers content names, titles, descriptions, image/URL fields, and sports iframe button/link URLs (Livescore / Odds iframes). IDs, slugs, credentials, other JSON/API data, and caches are excluded. Only existing databases and tables are searched.</p>
            </div>
            <div class="form-row">
              <label for="find">Find</label>
              <textarea id="find" name="find" maxlength="2000"><?= h($find) ?></textarea>
            </div>
            <div class="form-row">
              <label for="replace">Replace With</label>
              <textarea id="replace" name="replace" maxlength="2000"><?= h($replace) ?></textarea>
            </div>
            <p style="font-size:0.82rem; margin-bottom:14px;"><a href="/admin/plugin/odds-api/sports/index.php">Edit sports iframe layouts and button URLs</a></p>
            <label style="display:flex; gap:8px; align-items:flex-start; font-size:0.86rem; color:var(--text-muted); margin-bottom:14px;">
              <input type="checkbox" name="confirm_replace" value="1">
              <span>I understand this will update matching content fields in the selected databases. IDs and slugs are not changed.</span>
            </label>
            <div class="actions">
              <button type="submit" name="action" value="preview" class="btn btn-secondary btn-sm">Preview Matches</button>
              <button type="submit" name="action" value="replace" class="btn btn-primary btn-sm">Replace</button>
            </div>
          </form>

          <?php if (is_array($result)): ?>
            <section class="settings-card" aria-label="Find and replace results">
              <h2 style="font-size:1.15rem; color:var(--brand-dark);">Results</h2>
              <table class="summary-table" data-paginate data-pagination-label="Settings summary">
                <thead>
                  <tr>
                    <th>Field</th>
                    <th>Matches</th>
                    <th>Rows</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($result['summary'] as $item): ?>
                    <tr>
                      <td><?= h($item['label']) ?></td>
                      <td><?= (int) $item['matches'] ?></td>
                      <td><?= (int) $item['rows'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>

              <?php if ($result['matchedRows'] !== []): ?>
                <h3 style="font-size:1rem; color:var(--brand-dark); margin-top:16px;">Matched Rows</h3>
                <ul class="matched-list">
                  <?php foreach (array_slice($result['matchedRows'], 0, 50) as $row): ?>
                    <li>
                      <strong><?= h($row['title'] !== '' ? $row['title'] : $row['id']) ?></strong>
                      <span style="color:var(--text-muted);"><?= h($row['source']) ?> · #<?= h($row['id']) ?> · <?= (int) $row['matches'] ?> match<?= (int) $row['matches'] === 1 ? '' : 'es' ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
                <?php if ($result['totalRows'] > 50): ?>
                  <p style="font-size:0.84rem; color:var(--text-muted); margin-top:10px;">Showing first 50 matched rows.</p>
                <?php endif; ?>
              <?php endif; ?>
            </section>
          <?php endif; ?>
        </div>
      </div>
    </main>
  </div>
</body>
</html>
