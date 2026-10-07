<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404);exit; }
require_once __DIR__ . '/../includes/sports-images-daily.php';
$options=getopt('', ['tournament:','max-items:','max-seconds:','batch-size:','pause-ms:','help']);
if (isset($options['help'])) {
    echo "Usage: php admin/plugin/odds-api/scripts/daily-sports-images.php [--tournament=ID_OR_ODDS_KEY] [--max-items=1000] [--max-seconds=1800] [--batch-size=25] [--pause-ms=1000]\n";
    echo "Safe for daily cron or nohup. Only enabled categories with enabled, active tournaments are enriched; existing/manual images are preserved. HTTP 429 starts a persistent 30-minute cooldown. Lock and rotating log live in APP_STORAGE_DIR.\n";
    exit;
}
try {
    $args=[];
    foreach (['max-items','max-seconds','batch-size','pause-ms'] as $flag) {
        if (!isset($options[$flag])) continue;
        if (!is_string($options[$flag]) || !preg_match('/^[0-9]+$/D',$options[$flag])) throw new InvalidArgumentException('Invalid --'.$flag);
        $args[str_replace('-','_',$flag)]=(int)$options[$flag];
    }
    if (isset($options['tournament'])) {
        $t=tournament_get((string)$options['tournament']);
        if (!$t) throw new InvalidArgumentException('Tournament not found.');
        $args['tournament_id']=(int)$t['id'];
    }
    $summary=sports_images_daily($args);
    exit(in_array($summary['status'],['rate_limited','no_progress'],true) || $summary['image_failures']>0 ? 1 : 0);
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n");exit(1); }
