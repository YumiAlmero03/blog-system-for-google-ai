<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (($argv[1] ?? '') === '--request') {
    $_GET = ['slug'=>$argv[2]];
    $_SERVER['REQUEST_METHOD'] = $argv[3] ?? 'GET';
    register_shutdown_function(static function(): void { fwrite(STDERR, 'STATUS:' . (http_response_code() ?: 200)); });
    require __DIR__ . '/../../game-api/game.php';
    exit;
}
$dir = sys_get_temp_dir() . '/game-detail-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
putenv('APP_STORAGE_DIR=' . $dir);
putenv('SITE_BASE_URL=https://example.test');
require_once __DIR__ . '/../includes/public-detail.php';
require_once __DIR__ . '/../plugin/games/includes/game-rankings.php';
function detail_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function detail_test_request(string $slug, string $method = 'GET'): array {
    $proc = proc_open([PHP_BINARY, __FILE__, '--request', $slug, $method], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
    $body = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    detail_check(proc_close($proc) === 0 && preg_match('/^STATUS:(\d+)$/', $errors, $match) === 1, 'Clean response: ' . $errors);
    return [(int) $match[1], json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
}
try {
    $pdo = blogs_pdo();
    $pdo->exec("INSERT INTO games(api_id,name,slug,published,done_processing,restrictions,bonus_buy,reels,currencies,languages,orientation) VALUES(42,'Example Game','example-game',1,1,'[]',1,'5','[\"PHP\",\"USD\"]','[\"en\"]','landscape')");
    $stmt = $pdo->prepare('UPDATE games SET short_description=?,long_description=?');
    $stmt->execute(['Game summary', '<h2>How to play</h2><p>Instructions</p><script>alert(1)</script>']);
    [$status,$payload] = detail_test_request('example-game');
    $game = $payload['game'];
    detail_check($status === 200 && $game['bonusBuy'] && $game['reels'] === '5' && $game['currencies'] === ['PHP','USD'], 'Full game facts');
    detail_check($game['seo']['canonicalUrl'] === 'https://example.test/game/example-game/' && $game['seo']['description'] === 'Game summary', 'SEO metadata');
    detail_check(str_contains($game['content_html'], '<h2>How to play</h2>') && !str_contains($game['content_html'], '<script>'), 'Sanitized content');
    detail_check($game['uniquePlayers'] === 0 && $game['slotsLaunchPlays']['count'] === null, 'Unknown upstream count differs from local zero');
    detail_check(!isset($game['restrictions'], $game['done_processing']), 'Internal controls remain private');
    game_rankings_save([['game_id'=>42,'times_played'=>1234], ['game_id'=>43,'times_played'=>0]]);
    [$status,$payload] = detail_test_request('example-game');
    detail_check($payload['game']['slotsLaunchPlays']['count'] === 1234 && !$payload['game']['slotsLaunchPlays']['stale'], 'Stored upstream plays');
    detail_check(game_rankings_count(43)['count'] === 0 && game_rankings_count(99)['count'] === null, 'Zero versus unranked');
    try { game_rankings_save([['game_id'=>42,'times_played'=>-1]]); throw new LogicException('Invalid count accepted'); }
    catch (RuntimeException $error) {}
    detail_check(game_rankings_count(42)['count'] === 1234, 'Failed sync preserves previous data');
    detail_check(detail_test_request('missing')[0] === 404 && detail_test_request('example-game','POST')[0] === 405, 'Request contract');
    $pdo->exec("UPDATE games SET restrictions='PH'");
    detail_check(detail_test_request('example-game')[0] === 404, 'PH restriction retained');
    echo "PASS: game detail fields, safe HTML, SEO, local/upstream counts, missing rankings, atomic validation, methods and eligibility.\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($dir);
}
