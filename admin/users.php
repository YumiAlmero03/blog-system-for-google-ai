<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_admin();
require_once __DIR__ . '/includes/admin-date.php';
$error = ''; $editor = null; $rows = [];
$id = filter_var($_GET['id'] ?? 0,FILTER_VALIDATE_INT) ?: 0;
$page = max(1,(int)(filter_var($_GET['page'] ?? 1,FILTER_VALIDATE_INT) ?: 1));
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        require_valid_csrf();
        $saved = ($_POST['action'] ?? '') === 'writer' ? writer_profile_save($_POST) : admin_user_save($_POST);
        header('Location: /admin/users.php?id=' . $saved . '&saved=1',true,303); exit;
    }
    if ($id) {
        $editor = admin_user_find($id);
        if (!$editor) { http_response_code(404); $error = 'Editor not found.'; }
    }
    $stmt = admin_users_pdo()->prepare('SELECT id,username,display_name,role,active,created_at,updated_at,last_login_at FROM admin_users ORDER BY id DESC LIMIT 26 OFFSET ?');
    $stmt->bindValue(1,($page-1)*25,PDO::PARAM_INT); $stmt->execute(); $rows = $stmt->fetchAll();
} catch (DomainException $e) { http_response_code(403); $error = $e->getMessage(); }
catch (InvalidArgumentException $e) { http_response_code(422); $error = $e->getMessage(); }
catch (Throwable $e) { http_response_code(500); error_log('Editor management failed.'); $error = 'Unable to manage editors right now.'; }
$canManage = !$editor || (($editor['auth_source'] ?? 'local') === 'local' && (auth_can('super_user') || $editor['role'] === 'editor'));
$socials = $editor ? writer_socials(admin_users_pdo(),(int)$editor['id']) : [];
// MageCDN's social icon catalog, plus a generic website option.
$socialPlatforms = ['Behance'=>'behance','Discord'=>'discord','Dribbble'=>'dribbble','Facebook'=>'facebook','GitHub'=>'github','Goodreads'=>'goodreads','Hacker News'=>'hackernews','Instagram'=>'instagram','LinkedIn'=>'linkedin','E-Mail'=>'mail','Mastodon'=>'mastodon','Medium'=>'medium','Pinterest'=>'pinterest','Product Hunt'=>'producthunt','Reddit'=>'reddit','Stack Overflow'=>'stackoverflow','Strava'=>'strava','Substack'=>'substack','Tumblr'=>'tumblr','Twitter'=>'twitter','TikTok'=>'tiktok','WordPress'=>'wordpress','X'=>'x','YouTube'=>'youtube','Website'=>''];
$title = 'Users'; require __DIR__ . '/partials/support-head.php';
?>
<?php if ($error): ?><p class="support-error" role="alert"><?= h($error) ?></p><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><p role="status">User settings saved.</p><?php endif; ?>
<?php if ($canManage): ?>
<h2><?= $editor ? 'Edit user' : 'Create user' ?></h2>
<form method="post" class="user-form">
<?= csrf_input() ?><input type="hidden" name="id" value="<?= h($editor['id'] ?? 0) ?>">
<p><label>Username <input name="username" minlength="3" maxlength="80" pattern="[a-zA-Z0-9][a-zA-Z0-9._-]{2,79}" required autocomplete="off" value="<?= h($editor['username'] ?? '') ?>"></label></p>
<p><label>Display name <input name="display_name" maxlength="120" required value="<?= h($editor['display_name'] ?? '') ?>"></label></p>
<p><label><?= $editor ? 'New password (leave blank to keep current)' : 'Password' ?> <input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" <?= $editor ? '' : 'required' ?>></label></p>
<p><label>Account status <select name="active"><option value="1" <?= !$editor || $editor['active'] ? 'selected' : '' ?>>Enabled</option><option value="0" <?= $editor && !$editor['active'] ? 'selected' : '' ?>>Disabled</option></select></label></p>
<p><label>Role <select name="role">
<?php foreach (auth_can('super_user') ? ['editor','admin','super_user'] : ['editor'] as $role): ?><option value="<?= h($role) ?>" <?= ($editor['role'] ?? 'editor') === $role ? 'selected' : '' ?>><?= h(ucwords(str_replace('_',' ',$role))) ?></option><?php endforeach; ?>
</select></label></p><button class="btn btn-primary btn-sm">Save user</button> <a class="btn btn-secondary btn-sm" href="/admin/users.php">New user</a>
</form>
<?php endif; ?>
<?php if ($editor): ?>
<h2>Writer information</h2>
<form method="post" class="user-form" id="writer-profile-form">
<?= csrf_input() ?><input type="hidden" name="id" value="<?= h($editor['id']) ?>"><input type="hidden" name="action" value="writer">
<p><label>Profile Picture <input type="file" id="writer-picture-upload" accept="image/jpeg,image/png,image/webp"></label></p>
<input type="hidden" name="profile_image" value="<?= h($editor['profile_image']) ?>">
<img id="writer-picture-preview" <?php if ($editor['profile_image'] !== ''): ?>src="<?= h(writer_image_url($editor['profile_image'])) ?>"<?php endif; ?> alt="<?= h(writer_public_name($editor)) ?>" width="96" height="96" style="max-width:100%;object-fit:cover;aspect-ratio:1" <?= $editor['profile_image'] === '' ? 'hidden' : '' ?>>
<button type="button" id="remove-writer-picture" class="btn btn-secondary btn-sm">Remove picture</button>
<p id="writer-picture-status" role="status" aria-live="polite">JPEG, PNG or WebP, up to 3 MB; minimum 120 × 80 pixels. Save writer information after changing the picture.</p>
<p><label>Written Name <input name="written_name" maxlength="120" value="<?= h($editor['written_name']) ?>"></label></p>
<p><label>Role Name <input name="role_name" maxlength="120" value="<?= h($editor['role_name']) ?>"></label><br><small>Public title or position only; does not change account permissions.</small></p>
<p><label>Bio <textarea name="bio" maxlength="3000" rows="4"><?= h($editor['bio']) ?></textarea></label></p>
<style>
.social-row { display:flex; flex-wrap:wrap; align-items:end; gap:12px; margin-bottom:12px; }
.social-row > label { flex:1 1 180px; min-width:0; }
.social-row input, .social-row select { width:100%; min-width:0; }
.social-platform-control { display:flex; align-items:center; gap:8px; }
.social-platform-control img { flex:0 0 24px; object-fit:contain; }
</style>
<p>Social Accounts</p><div id="writer-socials">
<?php foreach ($socials as $i=>$social): ?>
<div class="social-row"><label>Platform <span class="social-platform-control"><img data-platform-icon alt="" width="24" height="24" hidden referrerpolicy="no-referrer"><select name="socials[<?= $i ?>][platform]" data-social-platform required>
<option value="">Choose platform</option>
<?php if (!array_key_exists($social['platform'], $socialPlatforms)): ?><option value="<?= h($social['platform']) ?>" selected><?= h($social['platform']) ?></option><?php endif; ?>
<?php foreach ($socialPlatforms as $platform=>$icon): ?><option value="<?= h($platform) ?>" <?= $social['platform']===$platform ? 'selected' : '' ?>><?= h($platform) ?></option><?php endforeach; ?>
</select></span></label> <label>URL <input type="url" name="socials[<?= $i ?>][url]" maxlength="2048" value="<?= h($social['url']) ?>" required></label> <label>Label <input name="socials[<?= $i ?>][label]" maxlength="120" value="<?= h($social['label']) ?>"></label> <button type="button" data-remove-social>Remove</button></div>
<?php endforeach; ?></div>
<p><button type="button" id="add-social" class="btn btn-secondary btn-sm">Add social link</button></p>
<button class="btn btn-primary btn-sm">Save writer information</button>
</form>
<script>
(() => {
 const form = document.getElementById('writer-profile-form');
 const upload = document.getElementById('writer-picture-upload');
 const preview = document.getElementById('writer-picture-preview');
 const status = document.getElementById('writer-picture-status');
 const remove = document.getElementById('remove-writer-picture');
 let uploading = false;
 form.addEventListener('submit', event => { if (uploading) event.preventDefault(); });
 remove.addEventListener('click', () => {
  form.elements.profile_image.value = ''; preview.hidden = true; preview.removeAttribute('src');
  status.textContent = 'Picture removed from this profile. Save writer information to apply.';
 });
 upload.addEventListener('change', async () => {
  if (!upload.files[0]) return;
  uploading = true; upload.disabled = remove.disabled = true; status.textContent = 'Uploading…';
  const data = new FormData(); data.set('image',upload.files[0]); data.set('csrf_token',form.elements.csrf_token.value);
  try {
   const response = await fetch('/admin/upload-image.php',{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}});
   const payload = await response.json();
   if (payload.csrfToken) document.querySelectorAll('input[name="csrf_token"]').forEach(input => { input.value = payload.csrfToken; });
   if (!response.ok || !payload.ok) throw new Error(payload.error || 'Image upload failed.');
   form.elements.profile_image.value = payload.path; preview.src = payload.path; preview.hidden = false;
   status.textContent = 'Picture uploaded. Save writer information to apply.';
  } catch (error) { status.textContent = error.message; }
  finally { uploading = false; upload.disabled = remove.disabled = false; upload.value = ''; }
 });
 const platforms = <?= json_encode($socialPlatforms, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
 function updatePlatform(row) {
  const select = row.querySelector('[data-social-platform]');
  const image = row.querySelector('[data-platform-icon]');
  const key = platforms[select.value];
  image.hidden = !key;
  image.onerror = () => { image.hidden = true; };
  if (key) image.src = 'https://s.magecdn.com/social/tc-' + key + '.svg';
  else image.removeAttribute('src');
 }
 const list = document.getElementById('writer-socials'); let next = <?= count($socials) ?>;
 document.getElementById('add-social').addEventListener('click', () => {
  if (list.children.length >= 20) return;
  const row = document.createElement('div'); row.className = 'social-row'; const i = next++;
  row.innerHTML = `<label>Platform <span class="social-platform-control"><img data-platform-icon alt="" width="24" height="24" hidden referrerpolicy="no-referrer"><select name="socials[${i}][platform]" data-social-platform required><option value="">Choose platform</option></select></span></label> <label>URL <input type="url" name="socials[${i}][url]" maxlength="2048" required></label> <label>Label <input name="socials[${i}][label]" maxlength="120"></label> <button type="button" data-remove-social>Remove</button>`;
  const select = row.querySelector('[data-social-platform]');
  for (const name of Object.keys(platforms)) select.add(new Option(name, name));
  list.append(row);
  updatePlatform(row);
 });
 list.querySelectorAll('.social-row').forEach(updatePlatform);
 list.addEventListener('change', event => { if (event.target.matches('[data-social-platform]')) updatePlatform(event.target.closest('.social-row')); });
 list.addEventListener('click', event => { if (event.target.closest('[data-remove-social]')) event.target.closest('.social-row').remove(); });
})();
</script>
<?php endif; ?>
<h2 style="margin-top:24px">Users</h2>
<div class="support-table"><table><thead><tr><th>Username</th><th>Display name</th><th>Role</th><th>Status</th><th>Created</th><th>Updated</th><th>Last login</th><th>Action</th></tr></thead><tbody>
<?php foreach (array_slice($rows,0,25) as $row): ?><tr><td><?= h($row['username']) ?></td><td><?= h($row['display_name']) ?></td><td><?= h($row['role']) ?></td><td><?= $row['active'] ? 'Enabled' : 'Disabled' ?></td><td><?= h(admin_format_date($row['created_at'])) ?></td><td><?= h(admin_format_date($row['updated_at'])) ?></td><td><?= h(admin_format_date($row['last_login_at']) ?: 'Never') ?></td><td><a class="btn btn-secondary btn-sm" href="?id=<?= h($row['id']) ?>">Edit user / writer</a></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="8">No users yet.</td></tr><?php endif; ?></tbody></table></div>
<nav class="support-actions"><?php if ($page>1): ?><a href="?page=<?= $page-1 ?>">Previous</a><?php endif; ?><?php if (count($rows)>25): ?><a href="?page=<?= $page+1 ?>">Next</a><?php endif; ?></nav>
</main></body></html>
