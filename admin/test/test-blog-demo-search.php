<?php
/** Run the real demo-search handler with isolated games and authenticated staff. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$directory = sys_get_temp_dir() . '/blog-demo-search-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
putenv('APP_STORAGE_DIR=' . $directory);
putenv('SITE_BASE_URL=https://example.test');
putenv('ADMIN_USERNAME=super_user');
putenv('ADMIN_PASSWORD_HASH=' . password_hash('Visibility test password 123!', PASSWORD_DEFAULT));
putenv('ADMIN_JWT_SECRET=' . str_repeat('demo-search-test-', 4));
ini_set('session.save_path', $directory);
require_once __DIR__ . '/../includes/auth.php';

function demo_check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function demo_request(array $query, ?string $role = 'editor'): array {
    $request = ['path'=>'/admin/game-search.php', 'method'=>'GET', 'get'=>$query];
    if ($role !== null) $request['role'] = $role;
    $process = proc_open([PHP_BINARY, __DIR__ . '/test-game-visibility.php', '--handler'], [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
    fwrite($pipes[0], json_encode($request)); fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    demo_check(proc_close($process) === 0 && preg_match('/^STATUS:(\d+)$/', $errors, $status) === 1, 'Handler error: ' . $errors);
    return [(int)$status[1], json_decode($body, true)];
}
try {
    $pdo = blogs_pdo();
    $_SESSION['user'] = auth_authenticate_credentials('super_user', 'Visibility test password 123!');
    foreach (['admin','editor'] as $role) admin_user_save(['username'=>$role, 'display_name'=>$role, 'password'=>'Visibility test password 123!', 'active'=>1, 'role'=>$role]);
    $insert = $pdo->prepare('INSERT INTO games(api_id,name,slug,url,provider,provider_slug,published,done_processing,is_viewable,restrictions,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    foreach ([
        [101,'Sweet Bonanza','sweet-bonanza','Pragmatic Play',1,1,'[]'],
        [102,'Sweet Bonanza 1000','sweet-bonanza-1000','Pragmatic Play',1,1,'[]'],
        [103,'Sweet Hidden','sweet-hidden','Pragmatic Play',1,0,'[]'],
        [104,'Sweet Draft','sweet-draft','Pragmatic Play',0,1,'[]'],
        [105,'Sweet Restricted','sweet-restricted','Pragmatic Play',1,1,'["PH"]'],
        [106,'Sweet Blocked','sweet-blocked','Blocked',1,1,'[]'],
    ] as [$id,$name,$slug,$provider,$published,$visible,$restrictions]) {
        $insert->execute([$id,$name,$slug,'https://slotslaunch.com/iframe/' . $id,$provider,normalize_slug($provider),$published,1,$visible,$restrictions,time()]);
    }
    $pdo->exec("INSERT INTO game_provider_settings(provider_key,approved,updated_at) VALUES('slug:blocked',0,1)");
    foreach (['super_user','admin','editor'] as $role) {
        [$status,$data] = demo_request(['search'=>'Sweet Bonanza'], $role);
        demo_check($status === 200 && ($data['ok'] ?? false), $role . ' can search demo games (HTTP ' . $status . ')');
        demo_check(array_column($data['slots'], 'slug') === ['sweet-bonanza','sweet-bonanza-1000'], 'Exact match ranks first');
        demo_check($data['slots'][0]['apiId'] === 101 && str_contains($data['slots'][0]['iframeUrl'], '/iframe/101'), 'Search returns the game identity and demo URL');
    }
    foreach ([['search'=>'Sweet'], ['query'=>'Sweet'], ['search'=>'Pragmatic Play']] as $query) {
        [$status,$data] = demo_request($query);
        demo_check($status === 200 && count($data['slots']) === 2, 'Search and query alias preserve game visibility');
    }
    [$status,$data] = demo_request(['slug'=>'sweet-bonanza']);
    demo_check($status === 200 && $data['game']['apiId'] === 101, 'Saved demo slug resolves on reopen');
    [$status,$data] = demo_request(['search'=>'Sweet','limit'=>'1']);
    demo_check($status === 200 && count($data['slots']) === 1, 'Search limit respected');
    foreach (['s','missing game'] as $query) {
        [$status,$data] = demo_request(['search'=>$query]);
        demo_check($status === 200 && $data['slots'] === [], 'Short/missing search returns no games');
    }
    [$status] = demo_request(['search'=>'Sweet'], null);
    demo_check($status === 403, 'Anonymous searches stay protected');
    echo "PASS: admin/editor search, relevance, provider/query alias, visibility, saved demo lookup, limits and authentication.\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($directory);
}
