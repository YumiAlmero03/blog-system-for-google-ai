<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dir = sys_get_temp_dir() . '/settings-replace-' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
putenv('APP_STORAGE_DIR=' . $dir);
require_once __DIR__ . '/../includes/settings-replace.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$connections = [];
try {
    foreach (settings_replace_sources() as $key => $source) {
        $pdo = new PDO('sqlite:' . $dir . '/' . $source['file'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connections[$key] = $pdo;
        $table = array_key_first($source['tables']);
        $column = $source['tables'][$table][0];
        $pdo->exec('CREATE TABLE "' . $table . '" (id INTEGER PRIMARY KEY, "' . $column . '" TEXT, slug TEXT, api_key TEXT, api_data TEXT, updated_at INTEGER)');
        $pdo->prepare('INSERT INTO "' . $table . '" VALUES (1, ?, ?, ?, ?, 1)')->execute(['Old Old old', 'Old', 'Old', '{"key":"Old"}']);
    }
    $preview = settings_find_matches('Old', 'all');
    check($preview['totalRows'] === 5 && $preview['totalMatches'] === 10, 'Preview across all five databases');
    check(settings_find_matches('Old', 'games')['totalRows'] === 1, 'Selected database isolation');
    check(settings_find_matches('Old', 'all')['totalMatches'] === 10, 'Preview does not mutate');
    $connections['livescore']->exec("CREATE TRIGGER reject_update BEFORE UPDATE ON matches BEGIN SELECT RAISE(ABORT, 'test rollback'); END");
    try { settings_apply_replace('Old', 'New', 'all'); throw new LogicException('Expected failure'); }
    catch (PDOException $expected) {}
    check(settings_find_matches('Old', 'all')['totalMatches'] === 10, 'Failure rolls back earlier databases');
    $connections['livescore']->exec('DROP TRIGGER reject_update');
    settings_apply_replace('Old', 'New', 'games');
    check(settings_find_matches('Old', 'all')['totalMatches'] === 8, 'Replacement stays in selected database');
    settings_apply_replace('Old', 'New', 'all');
    check(settings_find_matches('Old', 'all')['totalMatches'] === 0, 'All content replaced');
    foreach (settings_replace_sources() as $key => $source) {
        $table = array_key_first($source['tables']);
        $column = $source['tables'][$table][0];
        $row = $connections[$key]->query('SELECT * FROM "' . $table . '"')->fetch(PDO::FETCH_ASSOC);
        check($row[$column] === 'New New old', 'Case-sensitive replacement');
        check($row['slug'] === 'Old' && $row['api_key'] === 'Old' && $row['api_data'] === '{"key":"Old"}', 'Protected data unchanged');
        check((int) $row['updated_at'] > 1, 'Timestamp updated');
    }
    settings_apply_replace('New', '', 'all');
    check(settings_find_matches('New', 'all')['totalMatches'] === 0, 'Empty replacement supported');
    try { settings_find_matches('x', '../invalid'); throw new LogicException('Invalid scope accepted'); } catch (InvalidArgumentException $expected) {}
    try { settings_apply_replace('', 'x', 'all'); throw new LogicException('Empty search accepted'); } catch (InvalidArgumentException $expected) {}
    $connections['games']->exec('DELETE FROM games');
    $insert = $connections['games']->prepare('INSERT INTO games (id, name) VALUES (?, ?)');
    for ($i = 1; $i <= 55; $i++) $insert->execute([$i, 'Many']);
    $many = settings_find_matches('Many', 'games');
    check($many['totalRows'] === 55 && count($many['matchedRows']) === 50, 'Preview capped while totals stay accurate');
    unset($connections['sports']);
    unlink($dir . '/sports-registry.sqlite');
    settings_find_matches('Many', 'all');
    check(!file_exists($dir . '/sports-registry.sqlite'), 'Missing databases are not created');
    $live = $connections['livescore'];
    $live->exec('CREATE TABLE iframes (id INTEGER PRIMARY KEY, title TEXT, layout_config TEXT, updated_at INTEGER)');
    $layout = [
        ['type'=>'container', 'id'=>'keep', 'children'=>[
            ['type'=>'button', 'id'=>'button', 'url'=>'https://old.example/go?event={$.id}', 'label'=>'https://old.example/', 'path'=>'$.price'],
            ['type'=>'loop', 'children'=>[['type'=>'link', 'url'=>'https://old.example/link']]],
            ['type'=>'image', 'url'=>'https://old.example/image'],
        ]],
    ];
    $json = json_encode($layout, JSON_THROW_ON_ERROR);
    $live->prepare('INSERT INTO iframes VALUES (1, ?, ?, 1)')->execute(['Layout', $json]);
    check(settings_find_matches('https://old.example/', 'livescore')['totalMatches'] === 2, 'Decoded URLs match escaped JSON slashes and nested links');
    check($live->query('SELECT layout_config FROM iframes')->fetchColumn() === $json, 'JSON preview does not mutate');
    settings_apply_replace('https://old.example/', 'https://new.example/path?q="x"&next=', 'all');
    $updated = json_decode($live->query('SELECT layout_config FROM iframes')->fetchColumn(), true, 64, JSON_THROW_ON_ERROR);
    $expected = $layout;
    $expected[0]['children'][0]['url'] = 'https://new.example/path?q="x"&next=go?event={$.id}';
    $expected[0]['children'][1]['children'][0]['url'] = 'https://new.example/path?q="x"&next=link';
    check($updated === $expected, 'Only button/link URLs change; JSON, labels, IDs, paths, image URLs, and dynamic tokens preserved');
    $live->exec("INSERT INTO iframes VALUES (2, 'Broken', '{broken', 1)");
    try { settings_apply_replace('Many', 'Changed', 'all'); throw new LogicException('Invalid JSON accepted'); } catch (JsonException $expectedError) {}
    check(settings_find_matches('Many', 'games')['totalRows'] === 55, 'Invalid layout rolls back earlier database changes');
    echo "Settings find/replace tests passed.\n";
} finally {
    $connections = [];
    foreach (glob($dir . '/*') ?: [] as $file) unlink($file);
    rmdir($dir);
}
