<?php
/** PH rechecks: signed requests, exact country rules, safe failures, visibility, batches and permissions.
 * Run: php admin/test/test-game-ph-recheck.php (temporary storage; no live provider calls).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dir = sys_get_temp_dir() . '/game-ph-recheck-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
putenv('APP_STORAGE_DIR=' . $dir); putenv('GAME_SITEMAP_OUTPUT_DIR=' . $dir);
putenv('SITE_BASE_URL=https://example.test');
putenv('SLOTSLAUNCH_API_KEY=ph-test-key'); putenv('SLOTSLAUNCH_API_SECRET=ph-test-secret');
putenv('SLOTSLAUNCH_ORIGIN=https://www.example.test'); putenv('SLOTSLAUNCH_SITE_DOMAIN=example.test');
putenv('ADMIN_USERNAME=super_user'); putenv('ADMIN_PASSWORD_HASH=' . password_hash('Visibility test password 123!', PASSWORD_DEFAULT));
putenv('ADMIN_JWT_SECRET=' . str_repeat('ph-recheck-secret-', 5));
ini_set('session.save_path', $dir);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/public-detail.php';
require_once __DIR__ . '/../plugin/games/includes/ph-availability.php';
$count = 0;
function ph_check(bool $ok, string $message): void {
    global $count;
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    $count++;
}
function ph_handler(array $request, bool $expectSitemapWarning = false): array {
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-d', 'html_errors=1', __DIR__ . '/test-game-visibility.php', '--handler'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fwrite($pipes[0], json_encode($request)); fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if ($expectSitemapWarning) {
        ph_check(str_contains($errors, 'PH recheck sitemap refresh failed: Cannot open the sitemap lock.'), 'Lock refresh failure is logged server-side: ' . $errors);
        $errors = preg_replace('/^(?:\[[^\r\n]+\] )?PH recheck sitemap refresh failed: [^\r\n]+\r?\n/', '', $errors);
    }
    ph_check(proc_close($process) === 0 && preg_match('/^STATUS:(\d+)$/', $errors, $match), 'Admin request has no warnings: ' . $errors);
    return [(int)$match[1], json_decode($body, true), $body];
}
try {
    $pdo = blogs_pdo();
    $_SESSION['user'] = auth_authenticate_credentials('super_user', 'Visibility test password 123!');
    admin_user_save(['username'=>'editor','display_name'=>'Editor','password'=>'Visibility test password 123!','active'=>1]);
    $insert = $pdo->prepare('INSERT INTO games(api_id,name,slug,provider,provider_slug,published,done_processing,is_viewable,restrictions,short_description,featured,updated_at) VALUES(?,?,?,?,?,1,1,?,?,"Edited description",1,1)');
    $slugs = ['newly-blocked','allowed-again','manually-hidden','provider-blocked','unpublished','missing','malformed','not-exact-ph'];
    foreach ($slugs as $index=>$slug) $insert->execute([39475+$index, $slug, $slug, 'Provider', $index===3 ? 'blocked' : 'approved', $index===2 ? 0 : 1, in_array($index,[1,5],true) ? '["PH"]' : '[]']);
    game_provider_approval_save($pdo, 'slug:blocked', 0);
    game_visibility_refresh();
    ph_check(str_contains(file_get_contents($dir.'/sitemap-games-1.xml'), '/game/unpublished/'), 'Published game initially appears in sitemap');
    $before = $pdo->query('SELECT id,name,slug,provider_slug,done_processing,is_viewable,featured,short_description FROM games ORDER BY id')->fetchAll();
    $rows = [
        ['id'=>39475,'restrictions'=>'US, ph','published'=>true],
        ['id'=>39476,'restrictions'=>[],'published'=>1],
        ['id'=>39477,'restrictions'=>[],'published'=>true],
        ['id'=>39478,'restrictions'=>[],'published'=>true],
        ['id'=>39479,'restrictions'=>[],'published'=>false],
        ['id'=>39481,'restrictions'=>['invalid'=>true],'published'=>1],
        ['id'=>39482,'restrictions'=>'ALPHA,PHL','published'=>true],
        ['id'=>999999,'restrictions'=>'PH','published'=>true],
    ];
    $transport = static function (string $url, array $headers) use ($rows): array {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        ph_check($query['token'] === 'ph-test-key' && count($query['id'])===8 && (int)$query['per_page']===100, 'Uses saved game IDs in a bounded request');
        $h = [];
        foreach ($headers as $header) { [$name,$value] = explode(': ', $header, 2); $h[$name] = $value; }
        ph_check($h['Origin']==='https://example.test', 'Uses registered origin without www');
        ph_check(hash_equals(hash_hmac('sha256', $h['X-SL-Timestamp']."\nGET\n/api/games", 'ph-test-secret'), $h['X-SL-Signature']), 'Signs path without query string');
        return ['data'=>$rows,'meta'=>['last_page'=>1]];
    };
    $result = games_ph_recheck($pdo, 0, null, null, $transport);
    ph_check($result['checked']===6 && $result['unknown']===2 && $result['restricted']===1 && $result['unpublished']===1 && $result['available']===4, 'Classifies PH, publication, and unknown states');
    ph_check(public_game_find('newly-blocked')===null && public_game_find('allowed-again')!==null, 'Fresh restrictions hide and restore games');
    ph_check(public_game_find('manually-hidden')===null && public_game_find('provider-blocked')===null, 'Manual visibility and provider approvals still apply');
    ph_check(public_game_find('unpublished')===null && public_game_find('missing')===null && public_game_find('not-exact-ph')!==null, 'Unpublished/missing stay hidden; substring PH matches do not block');
    ph_check($before===$pdo->query('SELECT id,name,slug,provider_slug,done_processing,is_viewable,featured,short_description FROM games ORDER BY id')->fetchAll(), 'Protected content and manual settings preserved');
    mkdir($dir.'/api-cache'); file_put_contents($dir.'/api-cache/test.json', '{}');
    game_visibility_refresh();
    ph_check(!is_file($dir.'/api-cache/test.json'), 'Changed availability can clear API caches');
    $xml = file_get_contents($dir.'/sitemap-games-1.xml');
    ph_check(!str_contains($xml,'/game/newly-blocked/') && str_contains($xml,'/game/allowed-again/'), 'Sitemap tracks the new eligibility');
    ph_check(!str_contains($xml, '/game/unpublished/'), 'Recheck unpublishing removes a previously listed sitemap URL');
    $republished = games_ph_recheck($pdo, 0, null, 5, static fn()=>['data'=>[['id'=>39479,'restrictions'=>[],'published'=>true]]]);
    game_visibility_refresh();
    ph_check($republished['available']===1 && str_contains(file_get_contents($dir.'/sitemap-games-1.xml'), '/game/unpublished/'), 'Republishing eligible game restores sitemap URL');

    $snapshot = $pdo->query('SELECT * FROM games ORDER BY id')->fetchAll();
    foreach ([['error'=>'Unauthorized'], ['data'=>'bad'], ['data'=>[],'meta'=>['last_page'=>2]], ['data'=>[$rows[0],$rows[0]]]] as $badResponse) {
        $rejected = false;
        try { games_ph_recheck($pdo, 0, null, null, static fn()=>$badResponse); } catch (GamePhCheckException) { $rejected=true; }
        ph_check($rejected && $snapshot===$pdo->query('SELECT * FROM games ORDER BY id')->fetchAll(), 'Malformed or ambiguous response keeps stored availability');
    }
    $rejected = false;
    try { games_ph_recheck($pdo, 0, null, null, static function () { throw new GamePhCheckException('Rate limited.'); }); } catch (GamePhCheckException) { $rejected=true; }
    ph_check($rejected && $snapshot===$pdo->query('SELECT * FROM games ORDER BY id')->fetchAll(), 'Network/rate-limit failure preserves the entire batch');
    $single = games_ph_recheck($pdo, 0, null, 1, static fn()=>['data'=>[['id'=>39475,'restrictions'=>'["PHILIPPINES"]','published'=>1]]]);
    ph_check($single['processed']===1 && $single['restricted']===1 && $single['done'], 'Per-game recheck handles PHILIPPINES and JSON lists');

    for ($i=0;$i<102;$i++) $insert->execute([50000+$i, 'Batch '.$i, 'batch-'.$i, 'Provider', 'approved', 1, '[]']);
    $bulk = static function (string $url): array {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        return ['data'=>array_map(static fn($id)=>['id'=>(int)$id,'restrictions'=>[],'published'=>1], $query['id'])];
    };
    $first = games_ph_recheck($pdo, 0, null, null, $bulk);
    $insert->execute([60000, 'New import', 'new-import', 'Provider', 'approved', 1, '["PH"]']);
    $second = games_ph_recheck($pdo, $first['next_after_id'], $first['max_id'], null, $bulk);
    ph_check($first['processed']===100 && !$first['done'] && $second['processed']===10 && $second['done'], 'Bulk keyset pagination checks all 110 snapshot records');
    ph_check(public_game_find('new-import')===null, 'Games imported during a run wait for the next run');

    $path = '/admin/plugin/games/recheck-ph.php';
    [$status] = ph_handler(['path'=>$path,'role'=>'editor']); ph_check($status===403, 'Editors cannot change imported restrictions');
    [$status] = ph_handler(['path'=>$path,'role'=>'super_user','bad_csrf'=>true]); ph_check($status===403, 'CSRF required');
    [$status] = ph_handler(['path'=>$path,'role'=>'super_user','method'=>'GET']); ph_check($status===405, 'Mutation is POST only');
    [$status] = ph_handler(['path'=>$path,'role'=>'super_user','post'=>['slot_id'=>'bad']]); ph_check($status===422, 'Invalid game input rejected');
    // Simulate a record unpublished earlier without a successful sitemap refresh.
    $pdo->exec("UPDATE games SET published=0 WHERE slug='unpublished'");
    ph_check(str_contains(file_get_contents($dir.'/sitemap-games-1.xml'), '/game/unpublished/'), 'Fixture has stale sitemap entry before a no-change recheck');
    [$status,$payload,$body] = ph_handler(['path'=>$path,'role'=>'super_user','post'=>['after_id'=>'99999','max_id'=>'99999']]);
    ph_check($status===200 && $payload['ok'] && $payload['done'] && $payload['processed']===0 && !str_contains($body,'ph-test-'), 'Authenticated response exposes no credentials');
    ph_check($payload['changed']===0 && !str_contains(file_get_contents($dir.'/sitemap-games-1.xml'), '/game/unpublished/'), 'Completed recheck removes stale unpublished URL even without database changes');
    // Force an unopenable lock even as root; displayed PHP errors must not corrupt JSON.
    $lockPath = $dir . '/.game-sitemaps.lock';
    unlink($lockPath); mkdir($lockPath);
    try {
        [$status,$payload,$body] = ph_handler(['path'=>$path,'role'=>'super_user','post'=>['after_id'=>'99999','max_id'=>'99999']], true);
        ph_check($status===200 && $payload['ok'] && isset($payload['warning']) && !str_contains($body, '<br'), 'Sitemap lock failure returns valid JSON with a warning even when display_errors was enabled');
    } finally { rmdir($lockPath); touch($lockPath); }
    [$status,,$html] = ph_handler(['path'=>'/admin/plugin/games/index.php','role'=>'super_user','method'=>'GET']);
    ph_check($status===200 && str_contains($html,'Recheck PH Availability') && str_contains($html,'data-recheck-ph="1"'), 'Admin list contains bulk and individual controls');
    [$status,,$html] = ph_handler(['path'=>'/admin/plugin/games/index.php','role'=>'editor','method'=>'GET']);
    ph_check($status===200 && !str_contains($html,'data-recheck-ph='), 'Admin-only actions are hidden from editors');
    echo "PASS: {$count} game PH recheck checks\n";
} finally {
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($dir);
}
