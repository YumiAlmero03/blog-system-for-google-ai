<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Copy only source so neither the local .env nor any persistent data enters the test.
$root = dirname(__DIR__,2);
$temp = sys_get_temp_dir() . '/fresh-storage-' . bin2hex(random_bytes(6));
function fresh_remove(string $path): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') fresh_remove($path . '/' . $name);
        rmdir($path);
    } else { unlink($path); }
}
function fresh_check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function fresh_run(string $code): string {
    global $temp;
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r',
        'putenv("APP_STORAGE_DIR=/absolute/path/outside/public/storage"); chdir(' . var_export($temp, true) . '); ' . $code],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    fresh_check(proc_close($process) === 0 && $errors === '', 'Handler failed: ' . $errors);
    return $output;
}
try {
    mkdir($temp, 0700);
    foreach (['admin/includes', 'admin/games', 'api'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') continue;
            $target = $temp . '/' . substr($file->getPathname(), strlen($root) + 1);
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
            copy($file->getPathname(), $target);
        }
    }
    foreach (['missing', 'empty'] as $state) {
        if ($state === 'empty') { fresh_remove($temp . '/admin/storage'); mkdir($temp . '/admin/storage', 0700); }
        fresh_run('require "admin/includes/blog-storage.php"; blogs_pdo();');
        fresh_check(is_file($temp . '/admin/storage/blogs.sqlite'), "$state storage: database created");
        fresh_check(is_file($temp . '/admin/storage/games.sqlite'), "$state storage: separate game database created");
        fresh_check(!is_dir($temp . '/admin/storage/api-cache'), 'Cache must be lazy');
        foreach (['login-attempts.min.json', 'customer-tickets.json', 'playnow-clicks.json', 'backups'] as $name) {
            fresh_check(!file_exists($temp . '/admin/storage/' . $name), "$name must be lazy");
        }
        $counts = json_decode(fresh_run('require "admin/includes/blog-storage.php"; $pdo=blogs_pdo(); $counts=[]; foreach (["admin_users","writer_social_links","blog_posts","blog_engagements","games","game_providers","game_types","game_themes","game_theme_links"] as $table) $counts[$table]=$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn(); echo json_encode($counts);'), true, 512, JSON_THROW_ON_ERROR);
        fresh_check(count(array_filter($counts)) === 0, 'No sample users, blogs, games or engagement');
        foreach (['blog-post-list.php', 'blog-category-list.php', 'slot-list.php', 'provider-list.php', 'settings/seo.php'] as $endpoint) {
            $response = json_decode(fresh_run('$_SERVER["REQUEST_METHOD"]="GET"; require ' . var_export('api/' . $endpoint, true) . ';'), true, 512, JSON_THROW_ON_ERROR);
            fresh_check(($response['ok'] ?? false) === true, "$state storage: $endpoint");
            if ($endpoint === 'blog-post-list.php') fresh_check($response['blogs'] === [], 'Empty blog list');
            if ($endpoint === 'slot-list.php') fresh_check($response['slots'] === [], 'Empty game list');
        }
        fresh_check(is_dir($temp . '/admin/storage/api-cache'), 'List cache created on demand');
        fresh_check(fresh_run('require "admin/includes/admin-support-data.php"; echo json_encode([admin_ticket_rows(),admin_chat_rows("")]);') === '[[],[]]', 'Empty support lists');
        fresh_run('require "admin/includes/blog-storage.php"; blogs_pdo()->exec("UPDATE app_settings SET setting_value=\'preserved\' WHERE setting_key=\'website_title\'"); blogs_pdo()->exec("INSERT INTO games(api_id,name,slug) VALUES(123,\'Preserved\',\'preserved\')");');
        fresh_check(fresh_run('require "admin/includes/blog-storage.php"; $pdo=blogs_pdo(); echo $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key=\'website_title\'")->fetchColumn(), ":", $pdo->query("SELECT name FROM games WHERE api_id=123")->fetchColumn();') === 'preserved:Preserved', 'Existing data survives another process bootstrap');
    }
    echo "Fresh storage checks passed (missing/empty directories, schema, empty APIs, lazy files, support reads, existing data).\n";
} finally {
    if (is_dir($temp)) fresh_remove($temp);
}
