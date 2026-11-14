<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (auth_is_authenticated()) {
    header('Location: /admin/blogs.php', true, 302);
    exit;
}

$loginError = isset($_GET['error']);
$loginLocked = isset($_GET['locked']);
$retryMinutes = isset($_GET['retry']) && ctype_digit((string) $_GET['retry']) ? max(1, min(60, (int) $_GET['retry'])) : 15;
?>
<!DOCTYPE html>
<html lang="en-PH">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login | Blog Manager</title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="preload" href="/admin/style.css" as="style"><link rel="stylesheet" href="/admin/style.css">
  <link rel="icon" href="/assets/favicon.ico">
  <style>
    .admin-login-box {
      max-width: 420px;
      margin: 80px auto;
      padding: 32px;
      background: var(--surface);
      border: 2px solid var(--brand);
      border-radius: var(--radius-md);
      text-align: center;
      box-shadow: var(--shadow);
    }
    .form-group { margin-bottom: 16px; text-align: left; }
    .form-group label {
      display: block;
      font-weight: 700;
      margin-bottom: 6px;
      font-size: 0.9rem;
      color: var(--brand-dark);
    }
    .form-control {
      width: 100%;
      padding: 10px 14px;
      border: 1px solid var(--border-strong);
      border-radius: var(--radius-sm);
      font-size: 0.95rem;
      font-family: inherit;
    }
    .auth-error {
      color: var(--danger);
      font-size: 0.85rem;
      margin-bottom: 12px;
      text-align: left;
    }
  </style>
</head>
<body>
  <div class="page-shell">
    <header class="site-header">
      <div class="header-inner">
        <?php
        $appVersion = function_exists('env_value') ? (env_value('APP_VERSION') ?: '') : '';
        if ($appVersion === '') {
            $gitHead = @file_get_contents(__DIR__ . '/../.git/HEAD');
            if (is_string($gitHead) && str_starts_with(trim($gitHead), 'ref: ')) {
                $refPath = __DIR__ . '/../.git/' . trim(substr(trim($gitHead), 5));
                $refHash = @file_get_contents($refPath);
                if (is_string($refHash) && strlen(trim($refHash)) >= 7) {
                    $appVersion = 'v2 (' . substr(trim($refHash), 0, 7) . ')';
                }
            }
            if ($appVersion === '') {
                $appVersion = 'v2';
            }
        }
        ?>
        <div style="display:flex; align-items:center; gap:8px;">
          <a href="/" class="brand-logo">
            <span class="badge-tag" style="background-color: var(--brand); color:#fff;">ADMIN</span>
          </a>
          <span class="badge-tag" style="font-size:0.75rem; opacity:0.8; background:var(--surface-alt, rgba(128,128,128,0.15)); color:var(--text-muted, inherit);" title="Application Version"><?= h($appVersion) ?></span>
        </div>
        <a href="<?= h(frontend_url('/blogs/')) ?>" class="btn btn-secondary btn-sm">View Blog Hub</a>
      </div>
    </header>

    <main id="main-content" style="padding: 20px 16px;">
      <div class="admin-login-box">
        <h1 style="font-size: 1.5rem; color: var(--brand-dark); margin-bottom: 8px;">Admin Portal</h1>
        <p style="font-size: 0.88rem; color: var(--text-muted); margin-bottom: 20px;">
          Sign in to manage blog posts.
        </p>

        <?php if ($loginLocked): ?>
          <div class="auth-error">Too many failed login attempts. Please try again in about <?= h($retryMinutes) ?> minute<?= $retryMinutes === 1 ? '' : 's' ?>.</div>
        <?php elseif ($loginError): ?>
          <div class="auth-error"><?= h(LOGIN_FAILURE_MESSAGE) ?></div>
        <?php endif; ?>

        <form action="/admin/login-handler.php" method="post" autocomplete="off">
          <?= csrf_input() ?>
          <div class="form-group">
            <label for="admin-username">Username</label>
            <input type="text" id="admin-username" name="username" class="form-control" autocomplete="username" maxlength="80" required>
          </div>
          <div class="form-group">
            <label for="admin-password">Password</label>
            <input type="password" id="admin-password" name="password" class="form-control" autocomplete="current-password" maxlength="256" required>
          </div>
          <button type="submit" class="btn btn-primary" style="width: 100%;">Sign In</button>
        </form>
      </div>
    </main>
  </div>
</body>
</html>
