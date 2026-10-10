<?php
declare(strict_types=1);
require_once __DIR__ . '/schema.php';

function games_db_path(): string { return blog_storage_dir() . '/games.sqlite'; }
function games_pdo(): PDO { return blogs_pdo(); }

/** Attach the independent catalogue, migrating legacy tables once under a lock. */
function games_storage_attach(PDO $pdo): void
{
    $lock = fopen(blog_storage_dir() . '/games-migration.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Game migration lock unavailable.');
    try {
        $tables = ['game_providers','game_types','game_themes','games','game_theme_links','game_provider_settings','game_module_settings'];
        $legacy = $pdo->query("SELECT name,sql FROM main.sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_KEY_PAIR);
        $migrate = array_values(array_filter($tables, static fn($table) => isset($legacy[$table])));
        if ($migrate) {
            // Consistent backup before moving any production records, including WAL writes.
            $backup = blog_storage_dir() . '/blogs-before-games-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sqlite';
            $pdo->exec('VACUUM main INTO ' . $pdo->quote($backup));
            @chmod($backup,0600);
        }
        $pdo->exec('ATTACH DATABASE ' . $pdo->quote(games_db_path()) . ' AS game_store');
        if ($migrate) {
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->beginTransaction();
            try {
                foreach ($migrate as $table) {
                    $exists = $pdo->query("SELECT 1 FROM game_store.sqlite_master WHERE type='table' AND name=".$pdo->quote($table))->fetchColumn();
                    if ($exists) throw new RuntimeException('Both game catalogues contain tables; manual reconciliation is required.');
                    $sql = preg_replace('/^CREATE TABLE\s+(?:IF NOT EXISTS\s+)?(?:"[^"]+"|`[^`]+`|\[[^\]]+\]|\w+)/i', 'CREATE TABLE game_store."'.$table.'"', $legacy[$table], 1);
                    $pdo->exec($sql);
                    $pdo->exec('INSERT INTO game_store."'.$table.'" SELECT * FROM main."'.$table.'"');
                    $a = $pdo->query('SELECT COUNT(*) FROM main."'.$table.'"')->fetchColumn();
                    $b = $pdo->query('SELECT COUNT(*) FROM game_store."'.$table.'"')->fetchColumn();
                    if ($a !== $b) throw new RuntimeException('Game migration row count mismatch.');
                    $indexes = $pdo->query("SELECT sql FROM main.sqlite_master WHERE type='index' AND sql IS NOT NULL AND tbl_name=".$pdo->quote($table))->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($indexes as $indexSql) {
                        $indexSql = preg_replace('/^(CREATE\s+(?:UNIQUE\s+)?INDEX\s+)(?:IF NOT EXISTS\s+)?("[^"]+"|`[^`]+`|\[[^\]]+\]|\w+)/i', '$1game_store.$2', $indexSql, 1);
                        $pdo->exec($indexSql);
                    }
                    if ($pdo->query("SELECT 1 FROM main.sqlite_master WHERE name='sqlite_sequence'")->fetchColumn()) {
                        $seq = $pdo->query('SELECT seq FROM main.sqlite_sequence WHERE name='.$pdo->quote($table))->fetchColumn();
                        if ($seq !== false) $pdo->exec('UPDATE game_store.sqlite_sequence SET seq='.(int)$seq.' WHERE name='.$pdo->quote($table));
                    }
                }
                foreach (array_reverse($migrate) as $table) $pdo->exec('DROP TABLE main."'.$table.'"');
                $pdo->commit();
            } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
            finally { $pdo->exec('PRAGMA foreign_keys = ON'); }
        }
        $games = new PDO('sqlite:' . games_db_path(), null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $games->exec('PRAGMA busy_timeout=5000');
        $games->exec('PRAGMA foreign_keys=ON');
        games_schema($games);
        if ($games->query('PRAGMA foreign_key_check')->fetch()) throw new RuntimeException('Game catalogue foreign key check failed.');
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}

function games_enabled(): bool
{
    return (int)games_pdo()->query('SELECT enabled FROM game_module_settings WHERE id=1')->fetchColumn() === 1;
}

function games_cache_version(): string
{
    games_pdo();
    clearstatcache();
    return (string) @filemtime(games_db_path()) . ':' . (string) @filemtime(games_db_path().'-wal') . ':' . (games_enabled() ? '1' : '0');
}
