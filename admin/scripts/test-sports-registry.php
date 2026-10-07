<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$tempStorageDir = sys_get_temp_dir() . '/sports-reg-v3-test-' . bin2hex(random_bytes(6));
mkdir($tempStorageDir, 0700, true);
putenv('APP_STORAGE_DIR=' . $tempStorageDir);

require_once __DIR__ . '/../plugin/odds-api/includes/sports-registry.php';
require_once __DIR__ . '/../plugin/odds-api/includes/sports-api.php';

$count = 0;
function check(bool $ok, string $message): void {
    global $count;
    if (!$ok) {
        throw new RuntimeException("FAILED: " . $message);
    }
    $count++;
    echo "  [OK] ($count) $message\n";
}

try {
    echo "Starting 15-Point Odds API Tournament Editing & Participant Sync Verification...\n\n";

    $sample1x1Png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

    // 0. Seed / Initial Sync from /v4/sports/
    $mockOddsSportsPayload = json_encode([
        [
            'key' => 'basketball_nba',
            'group' => 'Basketball',
            'title' => 'NBA',
            'description' => 'US Basketball',
            'active' => true,
            'has_outrights' => false,
        ],
        [
            'key' => 'soccer_epl',
            'group' => 'Soccer',
            'title' => 'EPL',
            'description' => 'English Premier League',
            'active' => true,
            'has_outrights' => false,
        ],
    ]);

    $mockSportsTransport = static function(string $url) use ($mockOddsSportsPayload): string {
        if (!str_contains($url, '/v4/sports/')) {
            throw new RuntimeException('Unexpected URL: ' . $url);
        }
        return $mockOddsSportsPayload;
    };

    sports_odds_api_key_set('test_private_odds_key_xyz987');
    $syncInit = sports_registry_sync_odds_sports($mockSportsTransport);

    // 1. Open tournament edit page / record
    $nba = tournament_get('basketball_nba');
    check($nba !== null && (int)$nba['id'] > 0 && $nba['title'] === 'NBA', '1. Open tournament edit page / record (retrieved NBA ID ' . ($nba['id'] ?? 0) . ')');

    // 2. Edit display title
    $customTitle = 'National Basketball Association (NBA)';
    $updatedTitle = tournament_save([
        'id' => (int) $nba['id'],
        'title' => $customTitle,
    ]);
    check($updatedTitle['title'] === $customTitle, '2. Edit display title to custom presentation name');

    // 3. Edit/correct Odds Sport Key with strict validation
    // Verify invalid key with spaces is rejected
    $spaceRejected = false;
    try {
        tournament_save([
            'id' => (int) $nba['id'],
            'odds_sport_key' => 'basketball nba',
        ]);
    } catch (InvalidArgumentException) {
        $spaceRejected = true;
    }

    // Verify invalid key with uppercase is rejected
    $upperRejected = false;
    try {
        tournament_save([
            'id' => (int) $nba['id'],
            'odds_sport_key' => 'BASKETBALL_NBA',
        ]);
    } catch (InvalidArgumentException) {
        $upperRejected = true;
    }

    // Verify valid trimmed safe key is accepted
    $validKeySaved = tournament_save([
        'id' => (int) $nba['id'],
        'odds_sport_key' => 'basketball_nba',
    ]);

    check($spaceRejected && $upperRejected && $validKeySaved['odds_sport_key'] === 'basketball_nba', '3. Edit/correct Odds Sport Key validates format, rejects spaces, and enforces safe characters');

    // 4. Save and refresh from DB
    $refreshed = tournament_get((int) $nba['id']);
    check(
        $refreshed['title'] === $customTitle &&
        $refreshed['odds_sport_key'] === 'basketball_nba' &&
        $refreshed['source_title'] === 'NBA',
        '4. Save and refresh persists customized title, sport key, and source title separately'
    );

    // 5. Enabled state persists
    tournament_save([
        'id' => (int) $nba['id'],
        'enabled' => 0,
    ]);
    $disabled = tournament_get((int) $nba['id']);
    check((int)$disabled['enabled'] === 0, '5a. Enabled state switched off and persisted');

    tournament_save([
        'id' => (int) $nba['id'],
        'enabled' => 1,
    ]);
    $enabledAgain = tournament_get((int) $nba['id']);
    check((int)$enabledAgain['enabled'] === 1, '5b. Enabled state switched on and persisted');

    // 6. /v4/sports/ resync does not erase manual settings unexpectedly
    sports_registry_sync_odds_sports($mockSportsTransport);
    $resyncedNba = tournament_get((int) $nba['id']);
    check(
        $resyncedNba['title'] === $customTitle && // Preserves manual title!
        $resyncedNba['source_title'] === 'NBA' &&  // Updates source title
        (int)$resyncedNba['enabled'] === 1 &&     // Preserves enabled state!
        $resyncedNba['odds_sport_key'] === 'basketball_nba',
        '6. /v4/sports/ resync does not erase manual display title or admin enabled state'
    );

    // 7. NBA uses basketball_nba
    check($resyncedNba['odds_sport_key'] === 'basketball_nba', '7. NBA tournament strictly uses basketball_nba as its sport key');

    // 8. Final endpoint is /v4/sports/basketball_nba/participants?apiKey={apiKey}
    $builtUrl = sports_odds_api_build_url('/v4/sports/{sport}/participants', [
        'sport' => 'basketball_nba',
        'apiKey' => 'test_private_odds_key_xyz987',
    ]);
    check(
        $builtUrl === 'https://api.the-odds-api.com/v4/sports/basketball_nba/participants?apiKey=test_private_odds_key_xyz987',
        '8. Shared request helper builds exact /v4/sports/basketball_nba/participants endpoint'
    );

    // 9. API key loads from private settings
    $loadedKey = sports_odds_api_key_get();
    check($loadedKey === 'test_private_odds_key_xyz987', '9. API key loads securely from private server-side settings');

    // 10. Successful response is parsed (including full_name as returned by The Odds API!)
    $mockRealOddsApiResponse = json_encode([
        ['id' => 'par_01hqmkq6fceknv7cwebesgrx03', 'full_name' => 'Atlanta Hawks'],
        ['id' => 'par_01hqmkq6fdf1pvq7jgdd7hdmpf', 'full_name' => 'Boston Celtics'],
        ['id' => 'par_01hqmkq6fmfyjsnjtexnh7vdwm', 'full_name' => 'Detroit Pistons'],
    ]);

    $lastRequestedUrl = '';
    $mockParticipantTransport = static function(string $url) use (&$lastRequestedUrl, $mockRealOddsApiResponse): string {
        $lastRequestedUrl = $url;
        return $mockRealOddsApiResponse;
    };

    $syncResult = sports_registry_sync_tournament_participants((int)$nba['id'], $mockParticipantTransport);
    check(
        str_contains($lastRequestedUrl, '/v4/sports/basketball_nba/participants') &&
        $syncResult['participants_found'] === 3 &&
        $syncResult['new_participants'] === 3,
        '10. Successful response is parsed handling real full_name and id fields'
    );

    // 11. Participants are inserted/upserted into database
    $pdo = sports_registry_pdo();
    $hawks = $pdo->query('SELECT * FROM participants WHERE participant_id = "par_01hqmkq6fceknv7cwebesgrx03"')->fetch();
    $celtics = $pdo->query('SELECT * FROM participants WHERE participant_id = "par_01hqmkq6fdf1pvq7jgdd7hdmpf"')->fetch();
    $pistons = $pdo->query('SELECT * FROM participants WHERE participant_id = "par_01hqmkq6fmfyjsnjtexnh7vdwm"')->fetch();

    check(
        $hawks && $hawks['name'] === 'Atlanta Hawks' &&
        $celtics && $celtics['name'] === 'Boston Celtics' &&
        $pistons && $pistons['name'] === 'Detroit Pistons',
        '11. Participants are inserted into participants table with correct identities'
    );

    // 12. Running sync twice does not create duplicates
    $reSyncResult = sports_registry_sync_tournament_participants((int)$nba['id'], $mockParticipantTransport);
    $totalParticipantsCount = (int)$pdo->query('SELECT COUNT(*) FROM participants WHERE sports_category_id = ' . (int)$nba['sports_category_id'])->fetchColumn();
    check(
        $reSyncResult['new_participants'] === 0 &&
        $reSyncResult['unchanged_participants'] === 3 &&
        $totalParticipantsCount === 3,
        '12. Running participant sync twice does not create duplicate records'
    );

    // 13. One failed tournament does not stop Sync All Participants
    $mockFailTransport = static function(string $url) use ($mockRealOddsApiResponse): string {
        if (str_contains($url, 'soccer_epl')) {
            throw new RuntimeException('HTTP 404: Tournament not available');
        }
        return $mockRealOddsApiResponse;
    };

    $bulkResult = sports_registry_sync_all_participants($mockFailTransport);
    check(
        $bulkResult['tournaments_processed'] === 1 &&
        $bulkResult['tournaments_failed'] === 1 &&
        isset($bulkResult['errors']['soccer_epl']),
        '13. One failed tournament does not stop Sync All Participants batch processing'
    );

    // 14. Actual API/JSON/database errors are logged (without API key)
    $failedTournament = tournament_get('soccer_epl');
    check(
        $failedTournament['last_sync_status'] === 'Failed' &&
        str_contains($failedTournament['last_sync_message'], 'HTTP 404') &&
        !str_contains($failedTournament['last_sync_message'], 'test_private_odds_key_xyz987'),
        '14. Detailed error status is recorded on the tournament without exposing API key'
    );

    // 15. API key is never exposed
    $safeDiagEndpoint = '/v4/sports/' . rawurlencode($nba['odds_sport_key']) . '/participants';
    $allTournaments = tournament_get_all();
    $allParticipants = participant_get_all();
    $publicPayload = json_encode([$safeDiagEndpoint, $allTournaments, $allParticipants, $syncResult]);
    check(
        !str_contains($publicPayload, 'test_private_odds_key_xyz987'),
        '15. API key remains strictly server-side and is never exposed in output payloads or endpoints'
    );

    // 16. In Tournaments Registry: if sports category is disabled, its tournaments do NOT appear
    $soccerCat = sports_category_get('soccer');
    check($soccerCat !== null, 'Soccer category exists');
    sports_category_save([
        'id' => (int) $soccerCat['id'],
        'name' => 'Soccer',
        'slug' => 'soccer',
        'enabled' => 0,
    ]);
    $tournamentsFiltered = tournament_get_all();
    $keysFiltered = array_column($tournamentsFiltered, 'odds_sport_key');
    check(
        in_array('basketball_nba', $keysFiltered, true) &&
        !in_array('soccer_epl', $keysFiltered, true),
        '16. Disabled sports category hides its tournaments from Tournaments Registry list'
    );

    // 17. Re-enabling the category restores tournaments to Tournaments Registry
    sports_category_save([
        'id' => (int) $soccerCat['id'],
        'name' => 'Soccer',
        'slug' => 'soccer',
        'enabled' => 1,
    ]);
    $tournamentsRestored = tournament_get_all();
    $keysRestored = array_column($tournamentsRestored, 'odds_sport_key');
    check(
        in_array('soccer_epl', $keysRestored, true),
        '17. Re-enabling sports category restores its tournaments to Tournaments Registry list'
    );

    // 18. Tournament Edit supports Enrich API URL field
    $enrichUrlInput = 'https://v2.nba.api-sports.io/teams?';
    $nbaSaved = tournament_save([
        'id' => (int) $nba['id'],
        'enrich_api_url' => $enrichUrlInput,
    ]);
    check($nbaSaved['enrich_api_url'] === $enrichUrlInput, '18. Tournament Edit persists enrich_api_url');

    // 19. Enrich logos endpoint cleaning handles trailing '?' or '&'
    $capturedUrl = '';
    $mockEnrichTransportEmpty = function(string $url) use (&$capturedUrl): string {
        $capturedUrl = $url;
        return json_encode(['response' => []]);
    };
    $cleanTestRes = sports_registry_enrich_tournament_logos($nba['id'], [
        'transport' => $mockEnrichTransportEmpty,
    ]);
    check(
        $capturedUrl === 'https://v2.nba.api-sports.io/teams' &&
        $cleanTestRes['enrich_api_url'] === 'https://v2.nba.api-sports.io/teams',
        '19. Enrich URL endpoint cleans trailing ? while preserving URL structure'
    );

    // 20. Missing or invalid enrich_api_url produces a clear error
    $missingUrlFailed = false;
    try {
        sports_registry_enrich_tournament_logos($failedTournament['id']);
    } catch (InvalidArgumentException $e) {
        $missingUrlFailed = str_contains($e->getMessage(), 'No Enrich API URL configured');
    }
    check($missingUrlFailed, '20. Missing enrich_api_url throws clear configuration requirement error');

    // 21. API-Sports enrichment matches teams and downloads logos
    $mockApiSportsNbaResponse = json_encode([
        'get' => 'teams',
        'parameters' => [],
        'errors' => [],
        'results' => 2,
        'response' => [
            [
                'id' => 1,
                'name' => 'Atlanta Hawks',
                'nickname' => 'Hawks',
                'code' => 'ATL',
                'city' => 'Atlanta',
                'logo' => 'https://example.com/logos/atlanta-hawks.png',
            ],
            [
                'id' => 2,
                'name' => 'Boston Celtics',
                'nickname' => 'Celtics',
                'code' => 'BOS',
                'city' => 'Boston',
                'logo' => 'https://example.com/logos/boston-celtics.png',
            ],
        ],
    ]);

    $mockImgTransport = static function(string $url) use ($sample1x1Png): ?string {
        return $sample1x1Png;
    };

    $mockApiTransport = static function(string $url) use ($mockApiSportsNbaResponse): string {
        return $mockApiSportsNbaResponse;
    };

    sports_api_sports_key_set('test_api_sports_key_abc123');

    $enrichResult = sports_registry_enrich_tournament_logos($nba['id'], [
        'transport' => $mockApiTransport,
        'image_transport' => $mockImgTransport,
    ]);

    check(
        $enrichResult['enriched'] >= 2 &&
        $enrichResult['image_failures'] === 0,
        '21. API-Sports enrichment successfully downloads and assigns logos for matched participants'
    );

    // Verify participant has image_path and image_source = 'api_sports'
    $atlanta = participant_get_all(['search' => 'Atlanta Hawks'])[0] ?? null;
    check(
        $atlanta !== null &&
        !empty($atlanta['image_path']) &&
        $atlanta['image_source'] === 'api_sports',
        '21b. Participant record updated with public image_path and api_sports source'
    );

    // 22. Manual images are preserved and skipped from being overwritten
    participant_save([
        'id' => (int) $atlanta['id'],
        'image_source' => 'manual',
        'image_path' => '/uploads/participants/custom-manual-logo.png',
    ]);

    $enrichAgain = sports_registry_enrich_tournament_logos($nba['id'], [
        'transport' => $mockApiTransport,
        'image_transport' => $mockImgTransport,
    ]);

    $atlantaAfter = participant_get_all(['search' => 'Atlanta Hawks'])[0] ?? null;
    check(
        $enrichAgain['skipped_manual'] >= 1 &&
        $atlantaAfter['image_path'] === '/uploads/participants/custom-manual-logo.png' &&
        $atlantaAfter['image_source'] === 'manual',
        '22. Manually uploaded participant images are strictly preserved and skipped during enrichment'
    );

    // 23. participant_remove_image removes the image and resets source to odds_api
    $removedP = participant_remove_image((int) $atlanta['id']);
    check(
        $removedP['image_path'] === '' && $removedP['image_source'] === 'odds_api',
        '23. participant_remove_image clears image_path and resets source to odds_api'
    );

    // 24. Sports Categories (index.php) verification
    $indexHtml = file_get_contents(__DIR__ . '/../plugin/odds-api/registry/index.php');
    check(
        !str_contains($indexHtml, '<th>Participants</th>') &&
        !str_contains($indexHtml, 'id="tournaments"'),
        '24. Sports Categories table has participant column removed and Tournaments table moved off index.php'
    );
    check(
        str_contains($indexHtml, 'registration.php?sport=') &&
        str_contains($indexHtml, 'edit.php?id='),
        '25. Sports Categories page links to Tournaments list and Edit Sports Category'
    );

    // 26. Tournament List (registration.php) verification
    $regHtml = file_get_contents(__DIR__ . '/../plugin/odds-api/registry/registration.php');
    check(
        str_contains($regHtml, 'Tournaments Registry') &&
        str_contains($regHtml, 'tournaments/') &&
        !preg_match('/<h[1-6][^>]*>.*title.*<\/h[1-6]>/i', $regHtml),
        '26. Category tournament list contains Tournaments Registry table linking to tournament page without forbidden h3/headings'
    );

    // 27. Tournament Page (tournament-edit.php) with participants list and image editor
    $tournHtml = file_get_contents(__DIR__ . '/../plugin/odds-api/registry/tournament-edit.php');
    check(
        str_contains($tournHtml, 'Tournament Participants') &&
        str_contains($tournHtml, 'image-modal') &&
        str_contains($tournHtml, 'upload_participant_image') &&
        str_contains($tournHtml, 'modal-preview-img') &&
        str_contains($tournHtml, 'enrich_api_url'),
        '27. Tournament page contains participants list, viewable image preview, image editor modal, and Enrich API URL'
    );

    echo "\n=======================================================================\n";
    echo "SUCCESS: ALL 27 TOURNAMENT, REGISTRY & IMAGE EDITOR TESTS PASSED!\n";
    echo "=======================================================================\n";

} finally {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempStorageDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        $todo($fileinfo->getRealPath());
    }
    rmdir($tempStorageDir);
}
