<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/auth.php';
require_admin();
require_once __DIR__ . '/../../../includes/seo-settings.php';
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
  if (payload.csrfToken) csrf.value = payload.csrfToken;
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
