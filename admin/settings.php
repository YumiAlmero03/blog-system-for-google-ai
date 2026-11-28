<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/blog-storage.php';
require_once __DIR__ . '/includes/chat-realtime.php';
require_once __DIR__ . '/includes/default-image.php';

require_auth();

require_once __DIR__ . '/includes/settings-replace.php';
require_once __DIR__ . '/includes/repo-update.php';

if (isset($_GET['action']) && $_GET['action'] === 'check_repo_status') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    $force = isset($_GET['force']) && ($_GET['force'] === '1' || $_GET['force'] === 'true');
    echo json_encode(repo_current_status($force));
    exit;
}

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
$updateOutput = '';
$websiteTitle = blog_website_title();
$chatAiName = chat_ai_name();
$ignoredEngagementIps = blog_ignored_engagement_ips();
$repoStatus = repo_current_status(false);

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
            require_once __DIR__ . '/includes/playnow-promos.php';
            playnow_tracker_sync_ignored();
            $notice = 'Ignored IP addresses saved.';
            $noticeType = 'ok';
            csrf_rotate();
        } catch (Throwable $exception) {
            error_log('Settings ignored IP error: ' . $exception->getMessage());
            $notice = 'Ignored IP addresses could not be saved.';
            $noticeType = 'error';
        }
    } elseif ($action === 'pull_repo_update') {
        require_admin();
        try {
            $updateResult = repo_run_update();
            if ($updateResult['ok']) {
                $notice = 'Update complete! ' . ($updateResult['message'] ?? 'Pulled latest updates.');
                if (!empty($updateResult['backup'])) {
                    $notice .= ' Backup created at: ' . $updateResult['backup'];
                }
                $noticeType = 'ok';
            } else {
                $notice = 'Update failed: ' . ($updateResult['error'] ?? 'Unknown error.');
                if (!empty($updateResult['backup'])) {
                    $notice .= ' (Backup preserved at: ' . $updateResult['backup'] . ')';
                }
                $noticeType = 'error';
            }
            $updateOutput = $updateResult['output'] ?? '';
            $repoStatus = repo_current_status(true);
            csrf_rotate();
        } catch (Throwable $exception) {
            error_log('Settings repo update error: ' . $exception->getMessage());
            $notice = 'Update failed: ' . $exception->getMessage();
            $noticeType = 'error';
        }
    } elseif ($action === 'check_repo_update') {
        require_admin();
        try {
            $repoStatus = repo_current_status(true);
            if (!empty($repoStatus['error'])) {
                $notice = 'Update check: ' . $repoStatus['error'];
                $noticeType = 'error';
            } elseif (!empty($repoStatus['has_update'])) {
                $notice = 'New update available! ' . (int) $repoStatus['behind_count'] . ' new commit(s) ready on ' . ($repoStatus['remote_branch'] ?? 'v2') . '.';
                $noticeType = 'ok';
            } else {
                $notice = 'System is up to date with ' . ($repoStatus['remote_branch'] ?? 'v2') . '.';
                $noticeType = 'ok';
            }
            csrf_rotate();
        } catch (Throwable $exception) {
            error_log('Settings repo check error: ' . $exception->getMessage());
            $notice = 'Update check failed: ' . $exception->getMessage();
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
    @keyframes updatePulse {
      0% { box-shadow: 0 0 0 0 rgba(217, 119, 6, 0.4); }
      70% { box-shadow: 0 0 0 8px rgba(217, 119, 6, 0); }
      100% { box-shadow: 0 0 0 0 rgba(217, 119, 6, 0); }
    }
    .pulse-button {
      animation: updatePulse 2s infinite;
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

          <form method="post" class="settings-card" id="repo-update-card">
            <?= csrf_input() ?>
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:12px;">
              <div>
                <h2 style="font-size:1.15rem; color:var(--brand-dark); margin:0 0 4px;">System Update</h2>
                <p style="font-size:0.85rem; color:var(--text-muted); margin:0;">Check for updates from the Git repository and pull the latest release.</p>
              </div>
              <div id="repo-badge-container">
                <?php if (empty($repoStatus['is_git'])): ?>
                  <span class="badge" style="display:inline-block; padding:4px 10px; border-radius:4px; font-size:0.8rem; font-weight:700; background:#f0f2f5; color:#5e6c84;">Git Not Detected</span>
                <?php elseif (!empty($repoStatus['has_update'])): ?>
                  <span class="badge" style="display:inline-block; padding:4px 10px; border-radius:4px; font-size:0.8rem; font-weight:700; background:#fff4e5; color:#b76e00; border:1px solid #ffcc80;">Update Available (<?= (int) $repoStatus['behind_count'] ?>)</span>
                <?php else: ?>
                  <span class="badge" style="display:inline-block; padding:4px 10px; border-radius:4px; font-size:0.8rem; font-weight:700; background:#e9f8ef; color:#0d6630; border:1px solid #9bd5af;">Up to Date</span>
                <?php endif; ?>
              </div>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin:14px 0; padding:12px; background:#fff; border:1px solid var(--border); border-radius:6px; font-size:0.85rem;">
              <div>
                <strong style="display:block; color:var(--text-muted); font-size:0.75rem; text-transform:uppercase; margin-bottom:2px;">Current Branch</strong>
                <span id="repo-branch" style="font-weight:700; color:var(--brand-dark);"><?= h($repoStatus['branch'] ?? 'v2') ?></span>
              </div>
              <div>
                <strong style="display:block; color:var(--text-muted); font-size:0.75rem; text-transform:uppercase; margin-bottom:2px;">Current Commit</strong>
                <span id="repo-commit" style="font-family:monospace; font-weight:700; color:var(--brand-dark);"><?= h($repoStatus['head']['short'] ?? 'Unknown') ?></span>
                <span id="repo-commit-msg" style="display:block; font-size:0.78rem; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:240px;"><?= h($repoStatus['head']['message'] ?? '') ?></span>
              </div>
              <div>
                <strong style="display:block; color:var(--text-muted); font-size:0.75rem; text-transform:uppercase; margin-bottom:2px;">Remote Tracking</strong>
                <span id="repo-upstream" style="font-weight:700; color:var(--brand-dark);"><?= h($repoStatus['upstream'] ?? 'origin/v2') ?></span>
              </div>
              <div>
                <strong style="display:block; color:var(--text-muted); font-size:0.75rem; text-transform:uppercase; margin-bottom:2px;">Status / Last Checked</strong>
                <span id="repo-status-text" style="font-weight:700; color:var(--brand-dark);"><?= h($repoStatus['checked_at'] ?? 'Checking...') ?></span>
              </div>
            </div>

            <div id="repo-message-area">
              <?php if (!empty($repoStatus['has_uncommitted'])): ?>
                <div style="padding:10px 14px; background:#fff8e6; border:1px solid #ffd591; border-radius:6px; font-size:0.85rem; color:#873800; margin-bottom:12px;">
                  <strong>Notice:</strong> Uncommitted local code changes detected (<?= count($repoStatus['uncommitted_files'] ?? []) ?> files). Updating requires a clean working tree.
                </div>
              <?php elseif (!empty($repoStatus['has_update'])): ?>
                <div style="padding:10px 14px; background:#e6f7ff; border:1px solid #91d5ff; border-radius:6px; font-size:0.85rem; color:#0050b3; margin-bottom:12px;">
                  <strong>New version available:</strong> There are <?= (int) $repoStatus['behind_count'] ?> new commit(s) ready to pull from <?= h($repoStatus['remote_branch'] ?? 'v2') ?>.
                </div>
              <?php endif; ?>
            </div>

            <div id="repo-commits-container" style="<?= empty($repoStatus['new_commits']) ? 'display:none;' : '' ?> margin:14px 0;">
              <strong style="display:block; font-size:0.85rem; margin-bottom:6px; color:var(--brand-dark);">New Commits in Update:</strong>
              <ul id="repo-commits-list" style="margin:0; padding:0; list-style:none; display:grid; gap:6px;">
                <?php foreach ($repoStatus['new_commits'] ?? [] as $commit): ?>
                  <li style="padding:8px 10px; background:#fff; border:1px solid var(--border); border-radius:4px; font-size:0.82rem; display:flex; justify-content:space-between; gap:8px;">
                    <div>
                      <code style="font-weight:700; color:var(--brand);"><?= h($commit['short']) ?></code>
                      <span><?= h($commit['message']) ?></span>
                    </div>
                    <span style="color:var(--text-muted); font-size:0.78rem; white-space:nowrap;"><?= h($commit['time'] ?: $commit['author']) ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>

            <?php if ($updateOutput !== ''): ?>
              <div style="margin:14px 0;">
                <label style="font-weight:700; font-size:0.84rem; color:var(--brand-dark);">Update Log:</label>
                <pre style="background:#1e1e1e; color:#d4d4d4; padding:12px; border-radius:6px; font-size:0.82rem; overflow-x:auto; margin-top:4px; max-height:200px;"><?= h($updateOutput) ?></pre>
              </div>
            <?php endif; ?>

            <div class="actions" style="margin-top:14px;">
              <button type="button" id="btn-check-updates" class="btn btn-secondary btn-sm">Check for Updates</button>
              <button type="submit" name="action" value="pull_repo_update" id="btn-pull-update" class="btn btn-primary btn-sm <?= !empty($repoStatus['has_update']) ? 'pulse-button' : '' ?>" <?= empty($repoStatus['is_git']) ? 'disabled' : '' ?>>Pull Latest Update</button>
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
  <script>
  (function() {
    const card = document.getElementById('repo-update-card');
    if (!card) return;

    const btnCheck = document.getElementById('btn-check-updates');
    const btnPull = document.getElementById('btn-pull-update');
    const badgeContainer = document.getElementById('repo-badge-container');
    const branchEl = document.getElementById('repo-branch');
    const commitEl = document.getElementById('repo-commit');
    const commitMsgEl = document.getElementById('repo-commit-msg');
    const upstreamEl = document.getElementById('repo-upstream');
    const statusTextEl = document.getElementById('repo-status-text');
    const msgArea = document.getElementById('repo-message-area');
    const commitsContainer = document.getElementById('repo-commits-container');
    const commitsList = document.getElementById('repo-commits-list');

    function escapeHtml(str) {
      if (!str) return '';
      return String(str).replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
      });
    }

    function renderStatus(data) {
      if (!data || !data.ok) {
        if (statusTextEl) statusTextEl.textContent = 'Check failed';
        return;
      }

      if (!data.is_git) {
        badgeContainer.innerHTML = '<span class="badge" style="display:inline-block; padding:4px 10px; border-radius:4px; font-size:0.8rem; font-weight:700; background:#f0f2f5; color:#5e6c84;">Git Not Detected</span>';
        if (btnPull) btnPull.disabled = true;
        msgArea.innerHTML = '<div style="padding:10px 14px; background:#fff1f1; border:1px solid #ffa39e; border-radius:6px; font-size:0.85rem; color:#cf1322;">Git repository not detected (.git directory is missing). Standard update package installation applies.</div>';
        return;
      }

      if (branchEl) branchEl.textContent = data.branch || 'v2';
      if (commitEl) commitEl.textContent = data.head?.short || 'Unknown';
      if (commitMsgEl) commitMsgEl.textContent = data.head?.message || '';
      if (upstreamEl) upstreamEl.textContent = data.upstream || 'origin/v2';
      if (statusTextEl) statusTextEl.textContent = data.checked_at ? data.checked_at : 'Just now';

      if (data.has_uncommitted) {
        badgeContainer.innerHTML = '<span class="badge" style="display:inline-block; padding:4px 10px; border-radius:4px; font-size:0.8rem; font-weight:700; background:#fff2e8; color:#d4380d; border:1px solid #ffbb96;">Uncommitted Changes</span>';
        msgArea.innerHTML = '<div style="padding:10px 14px; background:#fff8e6; border:1px solid #ffd591; border-radius:6px; font-size:0.85rem; color:#873800; margin-bottom:12px;"><strong>Notice:</strong> Uncommitted local code changes detected (' + (data.uncommitted_files ? data.uncommitted_files.length : 0) + ' files). Commit or discard them first to update.</div>';
        if (btnPull) btnPull.disabled = true;
        commitsContainer.style.display = 'none';
      } else if (data.has_update) {
        badgeContainer.innerHTML = '<span class="badge" style="display:inline-block; padding:4px 10px; border-radius:4px; font-size:0.8rem; font-weight:700; background:#fff4e5; color:#b76e00; border:1px solid #ffcc80;">Update Available (' + data.behind_count + ')</span>';
        msgArea.innerHTML = '<div style="padding:10px 14px; background:#e6f7ff; border:1px solid #91d5ff; border-radius:6px; font-size:0.85rem; color:#0050b3; margin-bottom:12px;"><strong>New version available:</strong> There ' + (data.behind_count === 1 ? 'is 1 new commit' : 'are ' + data.behind_count + ' new commits') + ' ready to pull from ' + escapeHtml(data.remote_branch || 'v2') + '.</div>';
        if (btnPull) {
          btnPull.disabled = false;
          btnPull.classList.add('pulse-button');
        }

        if (data.new_commits && data.new_commits.length > 0) {
          commitsContainer.style.display = 'block';
          commitsList.innerHTML = data.new_commits.map(function(c) {
            return '<li style="padding:8px 10px; background:#fff; border:1px solid var(--border); border-radius:4px; font-size:0.82rem; display:flex; justify-content:space-between; gap:8px;">' +
              '<div><code style="font-weight:700; color:var(--brand);">' + escapeHtml(c.short) + '</code> <span>' + escapeHtml(c.message) + '</span></div>' +
              '<span style="color:var(--text-muted); font-size:0.78rem; white-space:nowrap;">' + escapeHtml(c.time || c.author) + '</span>' +
            '</li>';
          }).join('');
        } else {
          commitsContainer.style.display = 'none';
        }
      } else {
        badgeContainer.innerHTML = '<span class="badge" style="display:inline-block; padding:4px 10px; border-radius:4px; font-size:0.8rem; font-weight:700; background:#e9f8ef; color:#0d6630; border:1px solid #9bd5af;">Up to Date</span>';
        let msg = 'You are running the latest version from ' + escapeHtml(data.remote_branch || 'v2') + '.';
        if (data.ahead_count > 0) {
          msg += ' (Local branch is ahead by ' + data.ahead_count + ' commit' + (data.ahead_count === 1 ? '' : 's') + '.)';
        }
        msgArea.innerHTML = '<div style="padding:10px 14px; background:#f6ffed; border:1px solid #b7eb8f; border-radius:6px; font-size:0.85rem; color:#389e0d; margin-bottom:12px;">' + escapeHtml(msg) + '</div>';
        if (btnPull) {
          btnPull.disabled = false;
          btnPull.classList.remove('pulse-button');
        }
        commitsContainer.style.display = 'none';
      }
    }

    async function checkUpdates(force) {
      if (btnCheck) {
        btnCheck.disabled = true;
        btnCheck.textContent = 'Checking...';
      }
      if (statusTextEl) statusTextEl.textContent = 'Checking repository...';
      try {
        const res = await fetch('/admin/settings.php?action=check_repo_status' + (force ? '&force=1' : ''), {
          headers: { 'Accept': 'application/json' },
          cache: 'no-store'
        });
        if (res.ok) {
          const data = await res.json();
          renderStatus(data);
        } else {
          if (statusTextEl) statusTextEl.textContent = 'Check failed (HTTP ' + res.status + ')';
        }
      } catch (err) {
        if (statusTextEl) statusTextEl.textContent = 'Network error while checking';
      } finally {
        if (btnCheck) {
          btnCheck.disabled = false;
          btnCheck.textContent = 'Check for Updates';
        }
      }
    }

    if (btnCheck) {
      btnCheck.addEventListener('click', function() { checkUpdates(true); });
    }

    card.addEventListener('submit', function(e) {
      const clicked = document.activeElement;
      if (clicked && clicked.name === 'action' && clicked.value === 'pull_repo_update') {
        const confirmed = confirm("Are you sure you want to pull the latest update from the repository?\n\nA full backup of your databases, uploads, and files will be automatically created before updating.");
        if (!confirmed) {
          e.preventDefault();
          return;
        }
        btnPull.disabled = true;
        btnPull.textContent = 'Updating & creating backup...';
      }
    });

    // Always check when opening Settings page
    checkUpdates(true);

    // Periodic re-check every 3 minutes if tab is visible
    setInterval(function() {
      if (document.visibilityState === 'visible') {
        checkUpdates(true);
      }
    }, 180000);
  })();
  </script>
</body>
</html>
