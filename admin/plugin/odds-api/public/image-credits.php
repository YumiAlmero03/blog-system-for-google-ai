<?php
declare(strict_types=1);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; object-src 'none'; base-uri 'none'");
require_once __DIR__ . '/../includes/sports-registry.php';
$page = max(1, min(100000, (int)($_GET['credits_page'] ?? 1)));
$pdo = sports_registry_pdo();
$count = (int)$pdo->query("SELECT COUNT(*) FROM sports_image_assets WHERE status='saved'")->fetchColumn();
$stmt = $pdo->prepare("SELECT name,source,image_path,metadata FROM sports_image_assets WHERE status='saved' ORDER BY name LIMIT 50 OFFSET ?");
$stmt->bindValue(1, ($page-1)*50, PDO::PARAM_INT);
$stmt->execute();
function sports_credit_escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Sports image credits</title><link rel="stylesheet" href="/admin/plugin/odds-api/public/style.css"></head><body><main>
<h1>Sports image credits</h1>
<p>Logos identify their respective teams and competitions. Trademarks belong to their owners. Wikimedia thumbnails are supplied by Commons; downloaded images are otherwise unchanged.</p>
<?php foreach ($stmt->fetchAll() as $row): $meta=json_decode($row['metadata'],true) ?: []; ?>
<section><h2><?= sports_credit_escape($row['name']) ?></h2>
<img src="<?= sports_credit_escape($row['image_path']) ?>" alt="<?= sports_credit_escape($row['name']) ?> logo" width="64" loading="lazy">
<p><?= sports_credit_escape($row['source']) ?> — <?= sports_credit_escape($meta['license'] ?? '') ?></p>
<?php foreach (['artist','credit'] as $key): if (!empty($meta[$key])): ?><p><?= sports_credit_escape($meta[$key]) ?></p><?php endif; endforeach; ?>
<?php foreach (['source_page'=>'Source and file details','license_url'=>'License terms'] as $key=>$label): if (str_starts_with($meta[$key] ?? '', 'https://')): ?>
<p><a href="<?= sports_credit_escape($meta[$key]) ?>" target="_blank" rel="noopener noreferrer"><?= $label ?></a></p>
<?php endif; endforeach; ?>
</section>
<?php endforeach; ?>
<nav aria-label="Image credits pages">
<?php if ($page>1): ?><a href="?credits=1&amp;credits_page=<?= $page-1 ?>">Previous</a><?php endif; ?>
<?php if ($page*50<$count): ?><a href="?credits=1&amp;credits_page=<?= $page+1 ?>">Next</a><?php endif; ?>
</nav></main></body></html>
