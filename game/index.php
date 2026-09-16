<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/public-detail.php';
require_once __DIR__ . '/../includes/public-head.php';
require_once __DIR__ . '/../includes/slot-content.php';
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
try {
    $slug = public_detail_slug($_GET['slug'] ?? null);
    $game = $slug !== null ? public_game_find($slug) : null;
    if (!$game) { http_response_code(404); echo 'Game not found.'; exit; }
    $public = public_game_payload($game);
} catch (Throwable $error) { http_response_code(500); echo 'Game unavailable.'; exit; }
?>
<!doctype html><html lang="en-PH"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<?= public_head_html(['title'=>$game['name'],'description'=>$game['short_description'],'image'=>$public['thumbnail'],'canonical'=>$public['gameUrl']]) ?>
</head><body><main><h1><?= htmlspecialchars($game['name'],ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8') ?></h1>
<p><?= htmlspecialchars($game['short_description'],ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8') ?></p>
<?php if (preg_match('~^https?://~i',$public['iframeUrl'])): ?><iframe src="<?= htmlspecialchars($public['iframeUrl'],ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8') ?>" title="<?= htmlspecialchars($game['name'],ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8') ?>" style="width:100%;min-height:600px;border:0" allowfullscreen></iframe><?php endif; ?>
<div><?= slot_content_html($game['long_description']) ?></div>
</main></body></html>
