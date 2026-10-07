<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/sports-images.php';
$options = getopt('', ['tournament:', 'limit:', 'dry-run', 'retry', 'help']);
if (isset($options['help'])) {
    echo "Usage: php admin/plugin/odds-api/scripts/enrich-sports-images.php [--tournament=ID_OR_ODDS_KEY] [--limit=5] [--dry-run] [--retry]\n";
    exit;
}
try {
    $id = null;
    if (isset($options['tournament'])) {
        $tournament = tournament_get((string)$options['tournament']);
        if (!$tournament) throw new InvalidArgumentException('Tournament not found.');
        $id = (int)$tournament['id'];
    }
    $stats = sports_images_enrich($id, ['limit'=>(int)($options['limit'] ?? 5),'dry_run'=>isset($options['dry-run']),'retry'=>isset($options['retry'])]);
    echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    exit($stats['stopped'] || $stats['image_failures'] ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
