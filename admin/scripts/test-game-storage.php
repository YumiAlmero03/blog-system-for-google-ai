<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dir = sys_get_temp_dir().'/game-storage-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
putenv('APP_STORAGE_DIR='.$dir); putenv('SITE_BASE_URL=https://example.test');
require_once __DIR__.'/../includes/blog-storage.php';
function storage_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function storage_remove(string $path): void { foreach (scandir($path) as $name) { if ($name==='.' || $name==='..') continue; $file=$path.'/'.$name; is_dir($file) ? storage_remove($file) : unlink($file); } rmdir($path); }
try {
    $legacy = new PDO('sqlite:'.blogs_db_path(), null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    games_schema($legacy);
    $legacy->exec('DROP TABLE game_module_settings');
    $legacy->exec("CREATE TABLE preserved_blog_data (value TEXT); INSERT INTO preserved_blog_data VALUES('keep')");
    $legacy->exec("INSERT INTO game_providers(api_id,name) VALUES(11,'Provider')");
    $legacy->exec("INSERT INTO game_types(api_id,name) VALUES(12,'Table Games')");
    $legacy->exec("INSERT INTO game_themes(api_id,name) VALUES(13,'Classic')");
    $legacy->exec("ALTER TABLE games ADD COLUMN rewrite_status TEXT DEFAULT 'pending'");
    $legacy->exec("INSERT INTO games(api_id,name,slug,provider_id,type_id,published,done_processing,short_description,long_description,rewrite_status) VALUES(14,'Example','example',1,1,1,1,'Short','<p>Long</p>','done')");
    $legacy->exec('INSERT INTO game_theme_links VALUES(1,1)');
    $legacy->exec("INSERT INTO game_provider_settings VALUES('slug:blocked',0,1)");
    $legacy->exec("CREATE INDEX custom_game_rewrite ON games(rewrite_status)");
    $legacy->exec("UPDATE sqlite_sequence SET seq=1000 WHERE name='games'");
    $before = $legacy->query('SELECT * FROM games')->fetchAll();
    $legacy = null;
    $pdo = blogs_pdo();
    storage_check($pdo->query('SELECT * FROM games')->fetchAll()===$before,'Game records and enrichment fields preserved');
    storage_check($pdo->query("SELECT value FROM preserved_blog_data")->fetchColumn()==='keep','Blog records preserved');
    storage_check(!$pdo->query("SELECT 1 FROM main.sqlite_master WHERE name='games'")->fetchColumn(),'Game table removed from blog DB');
    storage_check((int)$pdo->query('SELECT count(*) FROM game_theme_links')->fetchColumn()===1,'Theme links preserved');
    storage_check((int)$pdo->query('SELECT approved FROM game_provider_settings')->fetchColumn()===0,'Provider approval preserved');
    storage_check(count(glob($dir.'/blogs-before-games-*.sqlite'))===1,'Pre-migration backup created');
    storage_check(games_enabled(),'Module enabled by default');
    $games = new PDO('sqlite:'.games_db_path());
    storage_check(!$games->query("SELECT 1 FROM sqlite_master WHERE name='blog_posts'")->fetchColumn(),'No blog tables in game DB');
    storage_check((bool)$games->query("SELECT 1 FROM sqlite_master WHERE name='custom_game_rewrite'")->fetchColumn(),'Custom indexes preserved');
    storage_check((int)$games->query("SELECT seq FROM sqlite_sequence WHERE name='games'")->fetchColumn()===1000,'Autoincrement sequence preserved');
    storage_check(!$games->query('PRAGMA foreign_key_check')->fetch(),'Game relationships valid');
    $command = [PHP_BINARY, '-r', 'require '.var_export(__DIR__.'/../includes/blog-storage.php',true).'; blogs_pdo(); echo games_enabled() ? "enabled" : "disabled";'];
    $process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    storage_check(proc_close($process)===0 && $output==='enabled' && $errors==='','Second-process migration is idempotent');
    storage_check(count(glob($dir.'/blogs-before-games-*.sqlite'))===1,'Migration not repeated');
    echo "PASS: separate game database, full row preservation, relationships/indexes/sequences, backup, untouched blog data, and repeat bootstrap.\n";
} finally { storage_remove($dir); }
