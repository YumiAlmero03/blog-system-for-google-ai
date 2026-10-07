<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/sports-registry.php';

// If called via CLI
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $options = getopt('', ['sport:', 'competition::', 'season::', 'help::']);
    if (isset($options['help']) || empty($options['sport'])) {
        echo "Usage: php admin/plugin/odds-api/scripts/sync-api-sports-teams.php --sport=SLUG_OR_ID [--competition=ID] [--season=YEAR]\n";
        exit(1);
    }

    $sport = $options['sport'];
    $compId = isset($options['competition']) ? (int) $options['competition'] : null;
    $season = isset($options['season']) ? (string) $options['season'] : null;

    try {
        echo "Starting sync for sport: {$sport}...\n";
        $result = sports_api_sync_teams($sport, $compId, $season);
        echo "Sync complete:\n";
        echo "Competitions processed: " . $result['competitions_processed'] . "\n";
        echo "Teams found: " . $result['teams_found'] . "\n";
        echo "New teams: " . $result['new_teams'] . "\n";
        echo "Updated teams: " . $result['updated_teams'] . "\n";
        echo "Unchanged: " . $result['unchanged'] . "\n";
        echo "Images downloaded: " . $result['images_downloaded'] . "\n";
        echo "Image failures: " . $result['image_failures'] . "\n";
        echo "API errors: " . $result['api_errors'] . "\n";

        if (!empty($result['errors'])) {
            echo "Errors encountered:\n";
            foreach ($result['errors'] as $err) {
                echo " - " . $err . "\n";
            }
        }
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "Sync error: " . $e->getMessage() . "\n");
        exit(2);
    }
}

