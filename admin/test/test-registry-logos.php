<?php
/** Manual tournament/team logos: admin forms, uploads, URLs, public resolution and sync preservation.
 * Run: php admin/test/test-registry-logos.php (temporary storage, no provider requests).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

if (($argv[1] ?? '') === '--handler') {
    $request = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    ini_set('session.save_path', (string) getenv('APP_STORAGE_DIR'));
    require_once __DIR__ . '/../includes/auth.php';
    $user = auth_authenticate_credentials('logo-test-admin', 'Logo testing password 123!');
    auth_mark_authenticated($user);
    $now = time();
    $_COOKIE[AUTH_ACCESS_COOKIE] = jwt_sign(['aud'=>'admin','scope'=>'admin:access','sub'=>$user['id'],'user_id'=>$user['id'],
        'role'=>$user['role'],'ver'=>$user['auth_version'],'sid'=>$_SESSION['auth_binding'],'iat'=>$now,'nbf'=>$now,'exp'=>$now+900], auth_jwt_secret());
    $_SERVER['REQUEST_METHOD'] = $request['method'] ?? 'POST';
    $_SERVER['SCRIPT_NAME'] = '/admin/plugin/odds-api/registry/' . $request['page'];
    $_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'];
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_GET = $request['get'] ?? [];
    $_POST = ($request['post'] ?? []) + ['csrf_token'=>csrf_token()];
    $_FILES = $request['files'] ?? [];
    ob_start();
    register_shutdown_function(static function (): void {
        $html = ob_get_clean();
        echo json_encode(['status'=>http_response_code() ?: 200, 'html'=>$html]);
    });
    require __DIR__ . '/../plugin/odds-api/registry/' . $request['page'];
    exit;
}

$dir = sys_get_temp_dir() . '/registry-logos-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
putenv('APP_STORAGE_DIR=' . $dir);
putenv('ADMIN_USERNAME=logo-test-admin');
putenv('ADMIN_PASSWORD_HASH=' . password_hash('Logo testing password 123!', PASSWORD_DEFAULT));
putenv('ADMIN_JWT_SECRET=' . str_repeat('logo-test-secret-', 5));
require_once __DIR__ . '/../plugin/odds-api/includes/sports-images.php';
require_once __DIR__ . '/../plugin/odds-api/includes/sports-api.php';
$count = 0;
$uploads = [];
function logo_check(bool $ok, string $label): void {
    global $count;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    $count++;
}
function logo_handler(string $page, array $get, array $post = [], array $files = [], string $method = 'POST'): array {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, '--handler'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot run admin handler.');
    fwrite($pipes[0], json_encode(compact('page','get','post','files','method'))); fclose($pipes[0]);
    $result = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || $errors !== '') throw new RuntimeException('Handler failed: ' . $errors);
    return json_decode($result, true, 32, JSON_THROW_ON_ERROR);
}
try {
    $cat = sports_category_save(['name'=>'Logo Test Basketball','slug'=>'logo-test-basketball']);
    sports_category_update_image((int)$cat['id'], '/uploads/sports/category-fallback.png');
    $t = tournament_save(['sports_category_id'=>$cat['id'],'title'=>'Logo Test League','odds_sport_key'=>'basketball_logo_test']);
    $pdo = sports_registry_pdo();
    $pdo->prepare('INSERT INTO participants(sports_category_id,participant_id,name,slug,image_key,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')
        ->execute([$cat['id'],'logo-test-team','Logo Test Team','custom-team-slug','logo-test-team',time(),time()]);
    $pid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO participant_tournaments VALUES(?,?,?)')->execute([$pid,$t['id'],time()]);

    foreach (['javascript:alert(1)','data:image/png;base64,123','//example.test/logo.png','http://example.test/logo.png','/uploads/../admin/.env','https://user:password@example.test/logo.png', ['invalid']] as $invalid) {
        $rejected = false;
        try { tournament_save_manual_image_url((int)$t['id'], $invalid); } catch (InvalidArgumentException) { $rejected = true; }
        logo_check($rejected, 'Reject unsafe logo URL');
    }
    $url = 'https://images.example.test/logo.png?version=2&size=100';
    $res = logo_handler('tournament-edit.php', ['id'=>$t['id']], ['action'=>'save','title'=>$t['title'],'odds_sport_key'=>$t['odds_sport_key'],
        'sports_category_id'=>$cat['id'],'enabled'=>'1','active'=>'1','logo_url'=>$url]);
    logo_check(str_contains($res['html'], 'Tournament updated successfully.') && tournament_image_get((int)$t['id'])['image_path'] === $url, 'Tournament form saves URL');
    logo_check(sports_registry_resolve_sport_image($t['odds_sport_key'], false) === $url, 'Public tournament logo resolves');
    $res = logo_handler('team-edit.php', ['id'=>$pid], ['action'=>'save','name'=>'Logo Test Team','slug'=>'custom-team-slug','enabled'=>'1','logo_url'=>$url]);
    logo_check(str_contains($res['html'], 'Participant updated successfully.') && participant_get($pid)['image_source'] === 'manual', 'Team form saves manual URL');
    logo_check(participant_get($pid)['slug'] === 'custom-team-slug', 'Logo change preserves custom team slug');
    $res = logo_handler('teams.php', ['sport'=>$cat['slug']], [], [], 'GET');
    logo_check(str_contains($res['html'], 'logo.png?version=2&amp;size=100') && !str_contains($res['html'], 'size=100?t='), 'Team list preserves query string');
    $res = logo_handler('registration.php', ['sport'=>$cat['slug']], [], [], 'GET');
    logo_check(str_contains($res['html'], 'logo.png?version=2&amp;size=100') && str_contains($res['html'], '#logo'), 'Tournament list shows editable logo');
    $res = logo_handler('tournament-edit.php', ['id'=>$t['id']], ['action'=>'save_participant','participant_id'=>$pid,'logo_url'=>'/uploads/sports/modal-team.png']);
    logo_check(participant_get($pid)['image_path'] === '/uploads/sports/modal-team.png', 'Tournament participant editor saves logo path');

    file_put_contents($dir.'/logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF5sAAAAASUVORK5CYII='));
    $file = ['error'=>UPLOAD_ERR_OK,'tmp_name'=>$dir.'/logo.png','size'=>filesize($dir.'/logo.png'),'name'=>'logo.png'];
    $res = logo_handler('tournament-edit.php', ['id'=>$t['id']], ['action'=>'save','title'=>$t['title'],'odds_sport_key'=>$t['odds_sport_key'],
        'sports_category_id'=>$cat['id'],'enabled'=>'1','active'=>'1','logo_url'=>$url], ['tournament_image'=>$file]);
    $tLogo = tournament_image_get((int)$t['id']); $uploads[] = $tLogo['image_path'];
    logo_check(str_starts_with($tLogo['image_path'], '/uploads/tournaments/') && $tLogo['source'] === 'manual', 'Tournament form accepts raster upload');
    $res = logo_handler('team-edit.php', ['id'=>$pid], ['action'=>'save','name'=>'Logo Test Team','slug'=>'custom-team-slug','enabled'=>'1','logo_url'=>$url], ['participant_image'=>$file]);
    $teamLogo = participant_get($pid); $uploads[] = $teamLogo['image_path'];
    $uploads[] = '/uploads/participants/' . basename($teamLogo['image_path']);
    logo_check(str_starts_with($teamLogo['image_path'], '/uploads/teams/') && $teamLogo['image_source'] === 'manual', 'Team form accepts raster upload');
    logo_check(is_file(dirname(__DIR__,2).$tLogo['image_path']) && is_file(dirname(__DIR__,2).$teamLogo['image_path']), 'Uploaded images exist');

    sports_images_record('tournament', $t, 'saved', '/uploads/sports/automatic.png', ['source'=>'sportsdataverse'], '');
    logo_check(tournament_image_get((int)$t['id'])['image_path'] === $tLogo['image_path'], 'In-flight enrichment cannot overwrite manual tournament logo');
    $stats = sports_images_enrich((int)$t['id'], ['manifest'=>[], 'transport'=>static function () { throw new RuntimeException('Manual logos must not trigger provider requests.'); }]);
    logo_check($stats['processed'] === 0 && $stats['remaining'] === 0, 'Enrichment skips manual tournament and team logos');
    sports_odds_api_key_set('isolated-logo-test-key');
    sports_registry_sync_tournament_participants((int)$t['id'], static fn()=>json_encode([['id'=>'logo-test-team','full_name'=>'Logo Test Team']]));
    logo_check(participant_get($pid)['image_path'] === $teamLogo['image_path'], 'Participant sync retains manual logo');
    $blocks = [];
    foreach (['sport','home_team'] as $source) $blocks[] = ['id'=>$source,'type'=>'image','path'=>'$.'.$source,'label'=>$source,'image_source'=>$source,'section'=>$source==='sport'?'title':'loop'];
    $projection = sports_api_project([(object)['sport_key'=>$t['odds_sport_key'],'home_team'=>'Logo Test Team']], $blocks, 1, 0, $t['odds_sport_key']);
    logo_check($projection['title_blocks'][0]['url'] === $tLogo['image_path'] && $projection['records'][0]['blocks'][0]['url'] === $teamLogo['image_path'], 'Public layout uses both manual uploads');

    logo_handler('tournament-edit.php', ['id'=>$t['id']], ['action'=>'remove_tournament_image']);
    logo_handler('team-edit.php', ['id'=>$pid], ['action'=>'remove_image']);
    logo_check(tournament_image_get((int)$t['id']) === [] && participant_get($pid)['image_path'] === '', 'Remove controls clear logo assignments');
    logo_check(sports_registry_resolve_sport_image($t['odds_sport_key'], false) === '/uploads/sports/category-fallback.png', 'Removing tournament logo restores category fallback');
    logo_check(is_file(dirname(__DIR__,2).$teamLogo['image_path']), 'Removing assignment retains reusable upload');
    echo "PASS: {$count} manual registry logo checks\n";
} finally {
    foreach ($uploads as $path) {
        @unlink(dirname(__DIR__,2).$path);
        @unlink(dirname(__DIR__).$path);
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($dir);
}
