<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/blog-storage.php';

require_auth();

const SETTINGS_REPLACE_COLUMNS = [
    'title' => 'Title',
    'seo_title' => 'SEO title',
    'category' => 'Category',
    'author' => 'Author',
    'excerpt' => 'Short excerpt',
    'content' => 'Content',
    'featured_image' => 'Featured image path',
    'focus_keyphrase' => 'Focus keyphrase',
    'date_label' => 'Date label',
];

function settings_post_value(string $key, int $maxLength): string
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) {
        return '';
    }

    $value = trim((string) $_POST[$key]);
    return strlen($value) > $maxLength ? substr($value, 0, $maxLength) : $value;
}

function settings_count_occurrences(string $haystack, string $needle): int
{
    if ($needle === '') {
        return 0;
    }

    return substr_count($haystack, $needle);
}

function settings_find_matches(string $find): array
{
    $pdo = blogs_pdo();
    $columnsSql = implode(', ', array_keys(SETTINGS_REPLACE_COLUMNS));
    $stmt = $pdo->query('SELECT id, slug, ' . $columnsSql . ' FROM blog_posts ORDER BY updated_at DESC, created_at DESC');
    $rows = $stmt->fetchAll();
    $summary = [];
    $totalMatches = 0;
    $matchedRows = [];

    foreach (SETTINGS_REPLACE_COLUMNS as $column => $label) {
        $summary[$column] = [
            'label' => $label,
            'matches' => 0,
            'rows' => 0,
        ];
    }

    foreach ($rows as $row) {
        $rowMatches = 0;
        foreach (SETTINGS_REPLACE_COLUMNS as $column => $label) {
            $matches = settings_count_occurrences((string) ($row[$column] ?? ''), $find);
            if ($matches <= 0) {
                continue;
            }

            $summary[$column]['matches'] += $matches;
            $summary[$column]['rows']++;
            $rowMatches += $matches;
        }

        if ($rowMatches > 0) {
            $matchedRows[] = [
                'id' => (string) ($row['id'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'matches' => $rowMatches,
            ];
            $totalMatches += $rowMatches;
        }
    }

    return [
        'summary' => $summary,
        'matchedRows' => $matchedRows,
        'totalMatches' => $totalMatches,
        'totalRows' => count($matchedRows),
    ];
}

function settings_clear_blog_api_cache(): void
{
    $dir = blog_storage_dir() . '/api-cache';
    if (!is_dir($dir)) {
        return;
    }

    foreach (glob($dir . '/*.json') ?: [] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function settings_apply_replace(string $find, string $replace): array
{
    $before = settings_find_matches($find);
    if ($before['totalMatches'] <= 0) {
        return $before + ['changedColumns' => 0];
    }

    $pdo = blogs_pdo();
    $pdo->beginTransaction();
    try {
        foreach (array_keys(SETTINGS_REPLACE_COLUMNS) as $column) {
            $stmt = $pdo->prepare(
                'UPDATE blog_posts
                 SET ' . $column . ' = REPLACE(' . $column . ', :find_replace, :replace),
                     updated_at = :updated_at
                 WHERE INSTR(' . $column . ', :find_where) > 0'
            );
            $stmt->execute([
                ':find_replace' => $find,
                ':find_where' => $find,
                ':replace' => $replace,
                ':updated_at' => time(),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    settings_clear_blog_api_cache();
    return $before + ['changedColumns' => count(array_filter($before['summary'], static fn (array $item): bool => $item['matches'] > 0))];
}

$find = '';
$replace = '';
$result = null;
$notice = '';
$noticeType = '';
$websiteTitle = blog_website_title();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_valid_csrf();
    $action = settings_post_value('action', 20);

    if ($action === 'save_website_title') {
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
    } else {
        $find = settings_post_value('find', 2000);
        $replace = settings_post_value('replace', 2000);

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
                        $result = settings_apply_replace($find, $replace);
                        $notice = 'Replace complete. Updated ' . (int) $result['totalMatches'] . ' match' . ((int) $result['totalMatches'] === 1 ? '' : 'es') . ' across ' . (int) $result['totalRows'] . ' post' . ((int) $result['totalRows'] === 1 ? '' : 's') . '.';
                        $noticeType = 'ok';
                        csrf_rotate();
                    }
                } else {
                    $result = settings_find_matches($find);
                    $notice = 'Preview found ' . (int) $result['totalMatches'] . ' match' . ((int) $result['totalMatches'] === 1 ? '' : 'es') . ' across ' . (int) $result['totalRows'] . ' post' . ((int) $result['totalRows'] === 1 ? '' : 's') . '.';
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
  <title>Settings | GperyaPH Admin</title>
  <link rel="preload" href="/admin/style.css" as="style"><link rel="stylesheet" href="/admin/style.css">
  <link rel="icon" href="/assets/icons/favicon.ico">
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
    .form-row textarea,
    .form-row input[type="text"] {
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
            <p style="font-size:0.88rem; color:var(--text-muted);">Find and replace text across blog database fields.</p>
          </div>
          <a href="/admin/blogs.php" class="btn btn-secondary btn-sm">Back to Blogs</a>
        </div>

        <?php if ($notice !== ''): ?>
          <div class="notice <?= h($noticeType) ?>" role="status"><?= h($notice) ?></div>
        <?php endif; ?>

        <div class="settings-grid">
          <form method="post" class="settings-card">
            <?= csrf_input() ?>
            <div class="form-row">
              <label for="website-title">Website Title</label>
              <input type="text" id="website-title" name="website_title" maxlength="120" required value="<?= h($websiteTitle) ?>">
            </div>
            <div class="actions">
              <button type="submit" name="action" value="save_website_title" class="btn btn-primary btn-sm">Save Website Title</button>
            </div>
          </form>

          <form method="post" class="settings-card">
            <?= csrf_input() ?>
            <div class="form-row">
              <label for="find">Find</label>
              <textarea id="find" name="find" maxlength="2000" required><?= h($find) ?></textarea>
            </div>
            <div class="form-row">
              <label for="replace">Replace With</label>
              <textarea id="replace" name="replace" maxlength="2000"><?= h($replace) ?></textarea>
            </div>
            <label style="display:flex; gap:8px; align-items:flex-start; font-size:0.86rem; color:var(--text-muted); margin-bottom:14px;">
              <input type="checkbox" name="confirm_replace" value="1">
              <span>I understand this will update matching blog database fields. Blog IDs and slugs are not changed.</span>
            </label>
            <div class="actions">
              <button type="submit" name="action" value="preview" class="btn btn-secondary btn-sm">Preview Matches</button>
              <button type="submit" name="action" value="replace" class="btn btn-primary btn-sm">Replace</button>
            </div>
          </form>

          <?php if (is_array($result)): ?>
            <section class="settings-card" aria-label="Find and replace results">
              <h2 style="font-size:1.15rem; color:var(--brand-dark);">Results</h2>
              <table class="summary-table">
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
                <h3 style="font-size:1rem; color:var(--brand-dark); margin-top:16px;">Matched Posts</h3>
                <ul class="matched-list">
                  <?php foreach (array_slice($result['matchedRows'], 0, 50) as $row): ?>
                    <li>
                      <strong><?= h($row['title'] !== '' ? $row['title'] : $row['id']) ?></strong>
                      <span style="color:var(--text-muted);">/blog/<?= h($row['slug']) ?>/ · <?= (int) $row['matches'] ?> match<?= (int) $row['matches'] === 1 ? '' : 'es' ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
                <?php if (count($result['matchedRows']) > 50): ?>
                  <p style="font-size:0.84rem; color:var(--text-muted); margin-top:10px;">Showing first 50 matched posts.</p>
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
