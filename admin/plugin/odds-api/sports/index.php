<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/sports-api.php';
$error='';
$defaults=['id'=>0,'title'=>'New layout','api_url'=>'https://api.the-odds-api.com/v4/sports/{sport}/odds/?apiKey={apiKey}&regions={regions}&markets={markets}&bookmakers={bookmakers}','sport'=>'basketball_nba','enabled'=>true,'root_path'=>'$','columns_per_row'=>1,'layout_config'=>[],'catalog_url'=>'https://api.the-odds-api.com/v4/sports/?apiKey={apiKey}','markets'=>'h2h'];
if (($_SERVER['REQUEST_METHOD']??'')==='POST') {
    require_valid_csrf();
    $preview=($_POST['action']??'')==='preview';
    try {
        if ($preview) {
            $config=sports_api_configure($_POST);
            $sport=$_POST['sport']??''; if(!is_string($sport))throw new InvalidArgumentException('Invalid sport.');
            $source=sports_api_source($config,$sport);
            $root=sports_api_root($source['data'],$config['root_path']);
            header('Content-Type: application/json');header('Cache-Control: no-store');
            echo json_encode(['ok'=>true,'fields'=>sports_api_discover($root),'raw_data'=>array_slice(is_array($root)?$root:[$root],0,10),'preview'=>sports_api_project($root,$config['layout_config'],$config['columns_per_row'])],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);exit;
        }
        if(($_POST['action']??'')==='duplicate'){
            $sourceId=filter_var($_POST['id']??'',FILTER_VALIDATE_INT);if($sourceId===false||$sourceId<1)throw new InvalidArgumentException('Invalid configuration ID.');
            $copyId=sports_api_duplicate($sourceId);csrf_rotate();header('Location: /admin/plugin/odds-api/sports/?id='.$copyId.'&duplicated=1',true,303);exit;
        }
        if(($_POST['action']??'')==='delete'){
            $deleteId=filter_var($_POST['id']??'',FILTER_VALIDATE_INT);if($deleteId===false||$deleteId<1)throw new InvalidArgumentException('Invalid configuration ID.');
            sports_api_delete($deleteId);csrf_rotate();header('Location: /admin/plugin/odds-api/sports/?deleted=1',true,303);exit;
        }
        if(($_POST['action']??'')!=='save')throw new InvalidArgumentException('Invalid action.');
        $id=sports_api_save($_POST);csrf_rotate();header('Location: /admin/plugin/odds-api/sports/?id='.$id.'&saved=1',true,303);exit;
    } catch(InvalidArgumentException $e){http_response_code(422);$error=$e->getMessage();}
    catch(OutOfBoundsException $e){http_response_code(404);$error='Configuration not found.';}
    catch(Throwable $e){http_response_code(503);$error='API or storage temporarily unavailable. Check the URL, root path and provider settings.';}
    if($preview){header('Content-Type: application/json');echo json_encode(['ok'=>false,'error'=>$error]);exit;}
}
try {
    $id=$_GET['id']??'1';if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,9}$/D',$id))throw new InvalidArgumentException();
    $config=isset($_GET['new'])?$defaults:sports_api_get((int)$id);
    if($error){foreach(['api_url','sport','root_path','columns_per_row','enabled','markets','id'] as $key)if(isset($_POST[$key])&&is_scalar($_POST[$key]))$config[$key]=$_POST[$key];$config['title']=is_string($_POST['name']??null)?$_POST['name']:$config['title'];try{$config['layout_config']=sports_api_validate_layout($_POST['layout_config']??[]);}catch(Throwable $ignored){}}
    $availableSports = sports_api_available_sports();
    if(empty($config['sport'])) { $config['sport'] = 'basketball_nba'; }
    $rows=sports_api_pdo()->query('SELECT id,title,enabled,columns_per_row FROM iframes ORDER BY id DESC')->fetchAll();
} catch(Throwable $e){http_response_code(404);exit('Configuration unavailable.');}
$title='Sport / Competition';require __DIR__ . '/../../../partials/support-head.php';
?>
<link rel="stylesheet" href="/admin/plugin/odds-api/sports/editor.css"><link rel="stylesheet" href="/admin/plugin/odds-api/public/layout.css">
<p><a href="/admin/plugin/odds-api/">Odds API</a> / Sport / Competition · <a href="?new=1">New layout</a> · <a href="/admin/plugin/odds-api/settings.php">Provider credentials</a></p>
<?php if($error):?><p role="alert"><?=h($error)?></p><?php endif ?>
<?php if(isset($_GET['saved'])):?><p role="status">Layout saved.</p><?php endif ?>
<?php if(isset($_GET['duplicated'])):?><p role="status">Layout duplicated. The copy is switched off until you turn it on and save.</p><?php endif ?>
<?php if(isset($_GET['deleted'])):?><p role="status">Layout deleted.</p><?php endif ?>
<form method="post" id="layout-form">
<?=csrf_input()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=(int)$config['id']?>">
<input type="hidden" name="layout_config" id="layout-json" value="<?=h(json_encode($config['layout_config'],JSON_THROW_ON_ERROR))?>">
<input type="hidden" name="item_limit" id="item-limit-input" value="<?=(int)($config['item_limit']??2)?>">
<div class="api-settings">
<label>Name<input name="name" required maxlength="200" value="<?=h($config['title'])?>"></label>
<label><span>Sport <span style="color:#ef4444">*</span></span>
  <select name="sport" id="sport-select" required>
    <option value="" disabled <?=empty($config['sport'])?'selected':''?>>Select a sport…</option>
    <?php
    $grouped = [];
    foreach ($availableSports as $s) {
        $grp = $s['group'] ?: 'Other';
        $grouped[$grp][] = $s;
    }
    foreach ($grouped as $grp => $items): ?>
      <optgroup label="<?=h($grp)?>">
        <?php foreach ($items as $s): ?>
          <option value="<?=h($s['key'])?>" <?=$config['sport']===$s['key']?'selected':''?>><?=h($s['title'])?> (<?=h($s['key'])?>)</option>
        <?php endforeach ?>
      </optgroup>
    <?php endforeach ?>
  </select>
</label>
<label class="api-url-field">API URL<input name="api_url" required maxlength="4096" value="<?=h($config['api_url'])?>" autocomplete="off" spellcheck="false"></label>
<label>Root / data path<input name="root_path" value="<?=h($config['root_path'])?>" required></label>
<label>Status<select name="enabled"><option value="0">Off</option><option value="1" <?=$config['enabled']?'selected':''?>>On</option></select></label>
<label>Columns per row<select name="columns_per_row" id="columns-select"><?php for($i=1;$i<=4;$i++):?><option value="<?=$i?>" <?=$i==(int)$config['columns_per_row']?'selected':''?>><?=$i?></option><?php endfor ?></select></label>
<?php if($config['catalog_url']):?><label>Markets<input name="markets" value="<?=h($config['markets'])?>"></label><?php endif ?>
</div>
<div class="support-actions"><button type="button" id="load-api" class="btn btn-secondary">Test / Load API</button><button type="button" id="undo">Undo</button><button type="button" id="redo">Redo</button><button type="submit" class="btn btn-primary">Save layout</button></div>
<p id="builder-status" role="status" aria-live="polite">Load an API to discover fields and preview your layout.</p>

<!-- Wireframe Odds Layout Builder Window -->
<div class="wireframe-window">
  <div class="wireframe-titlebar">
    <h3>Window Title — Odds Layout Builder</h3>
    <div class="wireframe-controls">
      <span class="wireframe-dot wireframe-dot-red" title="Close"></span>
      <span class="wireframe-dot wireframe-dot-yellow" title="Minimize"></span>
      <span class="wireframe-dot wireframe-dot-green" title="Expand"></span>
    </div>
  </div>

  <div class="wireframe-layout">
    <!-- Left Sidebar -->
    <aside class="wireframe-sidebar">
      <!-- Components Toolbox -->
      <div class="wireframe-box">
        <div class="wireframe-box-title" style="display:flex;justify-content:space-between;align-items:center;">
          <span>Components</span>
          <span style="font-size:0.7rem;color:#fbbf24;text-transform:none;font-weight:normal;">Drag or click</span>
        </div>
        <div class="component-grid">
          <button type="button" class="component-btn" id="btn-add-text" draggable="true">📝 Text</button>
          <button type="button" class="component-btn" id="btn-add-button" draggable="true">🔘 Button</button>
          <button type="button" class="component-btn" id="btn-add-container" draggable="true">📦 Container</button>
          <button type="button" class="component-btn" id="btn-add-image" draggable="true">🖼 Image</button>
        </div>
      </div>

      <!-- Generated Paths -->
      <div class="wireframe-box">
        <div class="wireframe-box-title" style="display:flex;justify-content:space-between;align-items:center;">
          <span>Generated Paths</span>
          <span style="font-size:0.7rem;color:#fbbf24;text-transform:none;font-weight:normal;">Drag or click</span>
        </div>
        <input type="search" class="paths-search" id="field-search" aria-label="Search paths" placeholder="Search paths...">
        <div class="paths-list" id="available-fields"></div>
      </div>

      <!-- Loop Settings -->
      <div class="wireframe-box">
        <div class="wireframe-box-title">Loop Settings</div>
        <div class="settings-control-row">
          <div class="settings-control-item">
            <label for="wireframe-loop-columns">loop column:</label>
            <select id="wireframe-loop-columns">
              <?php for($i=1;$i<=4;$i++):?><option value="<?=$i?>" <?=$i==(int)$config['columns_per_row']?'selected':''?>><?=$i?></option><?php endfor ?>
            </select>
          </div>
          <div class="settings-control-item">
            <label for="wireframe-loop-limit">Limit:</label>
            <input type="number" id="wireframe-loop-limit" min="0" max="100" value="<?=(int)($config['item_limit']??2)?>">
          </div>
        </div>
      </div>
    </aside>

    <!-- Right Canvas -->
    <main class="wireframe-canvas">
      <!-- Title Section -->
      <section class="canvas-section">
        <div class="canvas-section-header">
          <h4 class="canvas-section-title">Title Section</h4>
          <span class="canvas-section-badge">Header / Static</span>
        </div>
        <div class="title-dropzone" id="title-section-canvas"></div>
      </section>

      <!-- Loop section -->
      <section class="canvas-section">
        <div class="canvas-section-header">
          <h4 class="canvas-section-title">Loop section</h4>
          <span class="canvas-section-badge">Event Card Template</span>
        </div>

        <div class="container-sample-box">
          <div class="container-sample-header">
            <span>Container sample</span>
            <button type="button" id="btn-add-sample-row" class="btn btn-secondary btn-sm" style="font-size:0.75rem;padding:3px 8px;">+ Add Container Row</button>
          </div>
          <div class="sample-rows-container" id="loop-section-canvas"></div>
        </div>
      </section>

      <!-- Inspector Panel for Selected Block -->
      <div class="wireframe-inspector" id="inspector-panel" hidden>
        <h4>Block Settings</h4>
        <div id="inspector-content"></div>
      </div>

      <!-- View section (Live Preview) -->
      <section class="canvas-section">
        <div class="canvas-section-header">
          <h4 class="canvas-section-title">View section</h4>
          <span class="canvas-section-badge">Live Preview Output</span>
        </div>
        <div class="view-section-canvas" id="layout-preview"></div>
      </section>
    </main>
  </div>
</div>

<!-- Fallback hidden tree container to maintain compatibility -->
<div id="layout-tree" style="display:none;"></div>
<div id="inspector" style="display:none;"></div>
</form>
<?php if($config['id']):?><h2>Embed</h2><p><a href="/odds-api/?iframe=<?=(int)$config['id']?>" target="_blank" rel="noopener">Open saved iframe</a>. Use your full site address in the embed URL on another website.</p><textarea readonly aria-label="Embed code"><?=h('<iframe src="/odds-api/?iframe='.(int)$config['id'].'" title="'.h($config['title']).'" width="100%" height="650" style="border:0" loading="lazy"></iframe>')?></textarea><?php endif ?>
<h2>Saved layouts</h2><div class="support-table"><table data-paginate data-pagination-label="Saved layouts"><thead><tr><th>Name</th><th>Status</th><th>Columns</th><th>Iframe</th><th>Action</th></tr></thead><tbody><?php foreach($rows as $row):?><tr><td><?=h($row['title'])?></td><td><?=$row['enabled']?'On':'Off'?></td><td><?=(int)$row['columns_per_row']?></td><td>
<div class="saved-layout-embed" data-iframe-id="<?=(int)$row['id']?>" data-iframe-title="<?=h($row['title'])?>">
<input type="text" readonly aria-label="Iframe code for <?=h($row['title'])?>" value="<?=h('<iframe src="/odds-api/?iframe='.(int)$row['id'].'" title="'.h($row['title']).'" width="100%" height="650" style="border:0" loading="lazy"></iframe>')?>">
<button type="button" class="btn btn-secondary btn-sm" data-copy-iframe>Copy iframe</button>
<span class="saved-layout-copy-status" role="status" aria-live="polite"></span>
</div>
</td><td><a href="?id=<?=(int)$row['id']?>">Edit</a> · <form method="post" style="display:inline"><?=csrf_input()?><input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="<?=(int)$row['id']?>"><button type="submit" class="btn btn-secondary btn-sm">Duplicate</button></form><?php if((int)$row['id']!==1):?> · <form method="post" style="display:inline" data-confirm="Delete layout “<?=h($row['title'])?>”? Any iframe embedding it will stop working. This cannot be undone."><?=csrf_input()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$row['id']?>"><button type="submit" class="btn btn-danger btn-sm">Delete</button></form><?php endif ?></td></tr><?php endforeach ?></tbody></table></div>
<script src="/admin/plugin/odds-api/sports/saved-layouts.js" defer></script>
<script type="module" src="/admin/plugin/odds-api/sports/editor.js"></script></main></body></html>
