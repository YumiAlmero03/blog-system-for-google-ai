<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_admin();
require_once __DIR__ . '/../../includes/seo-settings.php';
require_once __DIR__ . '/../../includes/index-checker-client.php';
require_once __DIR__ . '/../../includes/indexnow.php';
$googleMessage = '';
$indexnowMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['indexnow_action'])) {
    require_capability('super_user'); require_post(); require_valid_csrf();
    try {
        $action = $_POST['indexnow_action'];
        if ($action === 'test') {
            $code = indexnow_test();
            $indexnowMessage = 'HTTP '.$code.' — '.match($code) { 200=>'Accepted; key verified.',202=>'Accepted; key verification pending.',403=>'Key is invalid or unverifiable.',422=>'Invalid key, URL, or location.',429=>'Rate limited; retry later.',default=>'Submission failed.' };
        } elseif (in_array($action,['save','generate'],true)) {
            indexnow_save(isset($_POST['indexnow_enabled']),trim((string)($_POST['indexnow_key'] ?? '')),$action==='generate');
            $indexnowMessage = 'IndexNow settings and public key file saved.';
        } else throw new InvalidArgumentException('Invalid IndexNow action.');
        csrf_rotate();
    } catch (InvalidArgumentException $error) { $indexnowMessage=$error->getMessage(); }
    catch (Throwable $error) { $indexnowMessage='IndexNow operation failed. Check the public key file and server configuration.'; }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['indexnow_action'])) {
    require_capability('super_user'); require_post(); require_valid_csrf();
    try {
        $action = $_POST['google_action'] ?? '';
        if ($action === 'test') {
            $status = google_test_connection();
            $googleMessage = $status === 'Connected' ? 'Google Search Console connected successfully.' : ($status === 'Property Access Denied' ? 'Authentication succeeded, but access to the configured property was denied.' : 'Google authentication failed. Check the configured service account.');
        } elseif ($action === 'save' || $action === 'remove') {
            $json = null;
            $file = $_FILES['service_account'] ?? null;
            if ($action === 'save' && $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ($file['error'] !== UPLOAD_ERR_OK || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'json'
                    || $file['size'] > 65536 || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Upload a .json service-account file no larger than 64 KB.');
                $json = file_get_contents($file['tmp_name']);
            }
            google_save_configuration(trim((string)($_POST['google_property'] ?? '')), $json, $action === 'remove');
            $googleMessage = $action === 'remove' ? 'Google credentials removed.' : 'Google configuration saved. Sitemap submission is queued for cron.';
        } elseif ($action === 'queue') {
            $state = google_state(); $original = $state; $state['submission_pending'] = true; google_write_state($state,$original);
            $googleMessage = 'Sitemap submission queued for cron.';
        } else throw new InvalidArgumentException('Invalid Google settings action.');
        csrf_rotate();
    } catch (InvalidArgumentException $error) { $googleMessage = $error->getMessage(); }
    catch (Throwable $error) { $googleMessage = 'Google configuration could not be saved. Check private storage permissions and configuration.'; }
}
try { $googleStatus = google_safe_status(); }
catch (Throwable $error) { $googleStatus = ['property'=>'','status'=>'Not Configured','email'=>'']; }
$settings = seo_settings();
$title = 'SEO Settings';
require __DIR__ . '/../../partials/support-head.php';
$fields = ['website_name'=>'Website Name','website_url'=>'Website URL','google_analytics_id'=>'Google Analytics ID','google_search_console_verification'=>'Google Search Console Verification','default_seo_title'=>'Default SEO Title','default_meta_description'=>'Default Meta Description','default_og_image'=>'Default OG Image'];
?>
<form id="seo-settings-form" class="user-form">
<?= csrf_input() ?>
<?php foreach ($fields as $key=>$label): ?>
<p><label for="<?= h($key) ?>"><?= h($label) ?></label><br>
<?php if ($key === 'default_meta_description'): ?>
<textarea id="<?= h($key) ?>" name="<?= h($key) ?>" maxlength="500" rows="3"><?= h($settings[$key]) ?></textarea>
<?php else: ?>
<input id="<?= h($key) ?>" name="<?= h($key) ?>" value="<?= h($settings[$key]) ?>" style="width:100%;max-width:720px" maxlength="<?= in_array($key,['website_url','default_og_image'],true) ? 2048 : ($key === 'google_search_console_verification' ? 512 : ($key === 'default_seo_title' ? 200 : ($key === 'google_analytics_id' ? 32 : 120))) ?>" <?= in_array($key,['website_name','website_url'],true) ? 'required' : '' ?> <?= $key === 'website_url' && env_value('SITE_BASE_URL') ? 'readonly' : '' ?>>
<?php endif; ?>
<?php if ($key === 'website_url' && env_value('SITE_BASE_URL')): ?><small>Controlled by SITE_BASE_URL for this environment.</small><?php endif; ?>
<?php if ($key === 'google_search_console_verification'): ?><small>Enter the verification token, without the HTML meta tag.</small><?php endif; ?>
<?php if ($key === 'default_og_image'): ?><br><label>Upload image <input type="file" id="seo-image" accept="image/jpeg,image/png,image/webp"></label><small>JPEG, PNG or WebP, up to 3 MB. You can also enter an image URL above.</small><?php endif; ?>
</p>
<?php endforeach; ?>
<button class="btn btn-primary btn-sm" id="seo-save">Save SEO Settings</button>
<p id="seo-result" role="status" aria-live="polite"></p>
</form>
<section class="user-form">
<h2>Google API / Search Console</h2>
<p>Connection Status (last test): <strong><?= h($googleStatus['status']) ?></strong></p>
<?php if ($googleStatus['email']): ?><p>Service account: <?= h($googleStatus['email']) ?></p><?php endif; ?>
<?php if ($googleMessage): ?><p role="status"><?= h($googleMessage) ?></p><?php endif; ?>
<?php if (($_SESSION['user']['role'] ?? '') === 'super_user'): ?>
<form method="post" enctype="multipart/form-data">
<?= csrf_input() ?>
<p><label>Search Console Property<br><input name="google_property" value="<?= h($googleStatus['property']) ?>" maxlength="2048" required style="width:100%;max-width:720px"></label></p>
<p><label>Service Account JSON<br><input type="file" name="service_account" accept=".json,application/json"></label></p>
<p><small>Add the displayed service-account email to this property's Search Console permissions. Uploaded keys are never displayed.</small></p>
<button class="btn btn-primary btn-sm" name="google_action" value="save">Save / Replace Credentials</button>
<button class="btn btn-secondary btn-sm" name="google_action" value="test" formnovalidate>Test Google Connection</button>
<button class="btn btn-secondary btn-sm" name="google_action" value="queue" formnovalidate>Queue Sitemap Submission</button>
<button class="btn btn-secondary btn-sm" name="google_action" value="remove">Remove Credentials</button>
</form>
<?php else: ?><p>Search Console Property: <?= h($googleStatus['property']) ?></p><?php endif; ?>
</section>
<?php $indexnow = indexnow_settings(); ?>
<section class="user-form">
<h2>IndexNow</h2>
<p><?= $indexnow['enabled'] ? 'Enabled' : 'Disabled' ?> — <?= h($indexnow['status']) ?></p>
<p>Key Location: <?= h(indexnow_key_location($indexnow['key'])) ?></p>
<?php if ($indexnowMessage): ?><p role="status"><?= h($indexnowMessage) ?></p><?php endif; ?>
<?php if (($_SESSION['user']['role'] ?? '') === 'super_user'): ?>
<form method="post">
<?= csrf_input() ?>
<p><label><input type="checkbox" name="indexnow_enabled" value="1" <?= $indexnow['enabled']?'checked':'' ?>> Enable IndexNow</label></p>
<p><label>IndexNow Key<br><input name="indexnow_key" value="<?= h($indexnow['key']) ?>" minlength="8" maxlength="128" pattern="[a-zA-Z0-9-]{8,128}" autocomplete="off"></label></p>
<button class="btn btn-primary btn-sm" name="indexnow_action" value="save">Save IndexNow</button>
<button class="btn btn-secondary btn-sm" name="indexnow_action" value="generate" formnovalidate>Generate and Save Key</button>
<button class="btn btn-secondary btn-sm" name="indexnow_action" value="test" formnovalidate>Test IndexNow</button>
<p><small>IndexNow notifies Bing, Yandex and participating engines. It does not report Google indexing status.</small></p>
</form>
<?php endif; ?>
</section>
<script>
(() => {
 const form = document.getElementById('seo-settings-form');
 const result = document.getElementById('seo-result');
 const save = document.getElementById('seo-save');
 const upload = document.getElementById('seo-image');
 const csrf = form.elements.csrf_token;
 async function send(url, data) {
  const response = await fetch(url,{method:'POST',body:data,headers:{Accept:'application/json'},credentials:'same-origin'});
  const payload = await response.json();
  if (payload.csrfToken) document.querySelectorAll('input[name=csrf_token]').forEach(input => input.value = payload.csrfToken);
  if (!response.ok || !payload.ok) throw new Error(payload.error || 'Unable to save settings.');
  return payload;
 }
 form.addEventListener('submit', async event => {
  event.preventDefault(); save.disabled = upload.disabled = true; result.textContent = 'Saving…';
  try { await send('/api/settings/seo.php',new FormData(form)); result.textContent = 'SEO settings saved.'; }
  catch (error) { result.textContent = error.message; }
  finally { save.disabled = upload.disabled = false; }
 });
 upload.addEventListener('change', async () => {
  if (!upload.files[0]) return;
  save.disabled = upload.disabled = true; result.textContent = 'Uploading…';
  const data = new FormData(); data.set('image',upload.files[0]); data.set('csrf_token',csrf.value);
  try { const payload = await send('/admin/upload-image.php',data); form.elements.default_og_image.value = payload.path; result.textContent = 'Image uploaded. Save SEO settings to apply it.'; }
  catch (error) { result.textContent = error.message; }
  finally { save.disabled = upload.disabled = false; upload.value = ''; }
 });
})();
</script>
</main></body></html>
