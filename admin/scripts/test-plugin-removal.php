<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
$temp = sys_get_temp_dir() . '/plugin-removal-' . bin2hex(random_bytes(6));
mkdir($temp, 0700);
try {
    // Copy only PHP source into an isolated installation with neither plugin.
    foreach (['admin/includes', 'admin/partials', 'api', 'game-api'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') continue;
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $target = $temp . '/' . $relative;
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
            copy($file->getPathname(), $target);
        }
    }
    $runner = <<<'CODE'
<?php
putenv('APP_STORAGE_DIR=' . __DIR__ . '/storage');
putenv('SITE_BASE_URL=https://example.test');
require __DIR__ . '/admin/includes/public-detail.php';
require __DIR__ . '/admin/includes/blog-renderer.php';
require __DIR__ . '/admin/includes/index-checker.php';
require __DIR__ . '/admin/includes/indexing-queue.php';
$pdo = blogs_pdo();
if (public_game_find('missing') !== null || public_blog_find('missing') !== null) throw new RuntimeException('Unexpected detail');
if (index_checker_eligible_sets($pdo)['game'] !== []) throw new RuntimeException('Games remain in indexing');
if (indexing_reconcile($pdo, 'game') !== 0) throw new RuntimeException('Game indexing not skipped');
indexing_google_sitemap_job($pdo);
if ($pdo->query("SELECT COUNT(*) FROM pragma_database_list WHERE name='game_store'")->fetchColumn()) throw new RuntimeException('Absent catalogue attached');
echo 'core-ok';
CODE;
    file_put_contents($temp . '/check.php', $runner);
    $output = []; $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($temp . '/check.php') . ' 2>&1', $output, $code);
    if ($code !== 0 || implode("\n", $output) !== 'core-ok') throw new RuntimeException(implode("\n", $output));
    foreach (['game', 'game-play', 'slot-list', 'provider-list', 'odds-api', 'sports-layout', 'sports'] as $endpoint) {
        $entry = $temp . '/api/' . $endpoint . '.php';
        $codeText = 'register_shutdown_function(function(){if(http_response_code()!==404)exit(2);});require ' . var_export($entry, true) . ';';
        $output = []; $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codeText) . ' 2>&1', $output, $code);
        $json = json_decode(implode("\n", $output), true);
        if ($code !== 0 || ($json['error'] ?? '') !== 'Plugin unavailable.') throw new RuntimeException('Missing plugin response: ' . $endpoint);
    }
    foreach (['game','game-play','slot-list','provider-list'] as $endpoint) {
        $entry=$temp.'/game-api/'.$endpoint.'.php';
        $codeText='register_shutdown_function(function(){if(http_response_code()!==404)exit(2);});require '.var_export($entry,true).';';
        $output=[]; $code=0;
        exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($codeText).' 2>&1',$output,$code);
        $json=json_decode(implode("\n",$output),true);
        if($code!==0 || ($json['error']??'')!=='Plugin unavailable.') throw new RuntimeException('Missing plugin canonical API: '.$endpoint);
    }
    echo "PASS: absent plugins preserve blog bootstrap/details/indexing; legacy and root game-api endpoints return JSON 404.\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname());
    }
    rmdir($temp);
}
