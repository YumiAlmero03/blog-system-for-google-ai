<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "This script can only be run via CLI.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/sports-registry.php';

echo "=== The Odds API: Bulk Participant Synchronization ===\n\n";

$options = getopt('', ['sport:', 'category:', 'key:']);
$targetSport = $options['sport'] ?? null;
$targetCategory = $options['category'] ?? null;
$overrideKey = $options['key'] ?? null;

$syncOptions = [];
if ($overrideKey) {
    $syncOptions['api_key'] = $overrideKey;
}

$tournaments = [];
if ($targetSport) {
    $t = tournament_get($targetSport);
    if (!$t) {
        fwrite(STDERR, "Error: Tournament '{$targetSport}' not found in registry.\n");
        exit(1);
    }
    $tournaments = [$t];
} elseif ($targetCategory) {
    $cat = sports_category_get($targetCategory);
    if (!$cat) {
        fwrite(STDERR, "Error: Category '{$targetCategory}' not found in registry.\n");
        exit(1);
    }
    $tournaments = tournament_get_all((int) $cat['id'], true);
} else {
    $tournaments = tournament_get_all(null, true);
}

if (!$tournaments) {
    echo "No enabled tournaments found to synchronize.\n";
    exit(0);
}

echo "Found " . count($tournaments) . " tournament(s) to process.\n";
echo str_repeat('-', 60) . "\n";

$totalTournaments = count($tournaments);
$processed = 0;
$failed = 0;
$totalFound = 0;
$totalNew = 0;
$totalUpdated = 0;
$totalLinked = 0;

foreach ($tournaments as $t) {
    $processed++;
    $key = $t['odds_sport_key'];
    $title = $t['title'];
    $catName = $t['category_name'] ?? 'Sport';

    echo sprintf("[%d/%d] Processing %s (%s - %s)... ", $processed, $totalTournaments, $key, $title, $catName);

    try {
        $res = sports_registry_sync_tournament_participants($t, null, $syncOptions);
        $totalFound += $res['participants_found'];
        $totalNew += $res['new_participants'];
        $totalUpdated += $res['updated_participants'];
        $totalLinked += $res['linked_tournaments'];

        echo sprintf("OK (Found: %d, New: %d, Updated: %d, Linked: %d)\n",
            $res['participants_found'],
            $res['new_participants'],
            $res['updated_participants'],
            $res['linked_tournaments']
        );
    } catch (Throwable $e) {
        $failed++;
        echo "FAILED: " . $e->getMessage() . " (continuing...)\n";
    }

    // Rate-limit pause between HTTP calls (250ms)
    usleep(250000);
}

echo str_repeat('-', 60) . "\n";
echo "Synchronization Summary:\n";
echo "  Tournaments processed: {$processed}\n";
echo "  Tournaments failed:    {$failed}\n";
echo "  Participants found:    {$totalFound}\n";
echo "  New participants:      {$totalNew}\n";
echo "  Updated participants:  {$totalUpdated}\n";
echo "  Tournament links:      {$totalLinked}\n";
echo "Done.\n";

