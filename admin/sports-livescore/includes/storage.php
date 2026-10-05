<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/env.php';

function sports_livescore_db_path(): string
{
    $directory = env_value('APP_STORAGE_DIR');
    if (!$directory || $directory === '/absolute/path/outside/public/storage') $directory = dirname(__DIR__, 2) . '/storage';
    return rtrim($directory, '/\\') . '/sports-livescore.sqlite';
}

/** Independent connection: never attaches or migrates the blog/game databases. */
function sports_livescore_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $path = sports_livescore_db_path();
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Sports livescore storage unavailable.');
    }
    $connection = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    chmod($path, 0600);
    $connection->exec('PRAGMA busy_timeout=5000');
    $connection->exec('PRAGMA foreign_keys=ON');
    $connection->exec('CREATE TABLE IF NOT EXISTS matches (
        id INTEGER PRIMARY KEY,
        source TEXT NOT NULL,
        source_match_id TEXT NOT NULL,
        sport TEXT NOT NULL,
        competition TEXT NOT NULL DEFAULT \'\',
        home_name TEXT NOT NULL,
        away_name TEXT NOT NULL,
        home_score TEXT,
        away_score TEXT,
        status TEXT NOT NULL DEFAULT \'scheduled\'
            CHECK (status IN (\'scheduled\',\'live\',\'finished\',\'postponed\',\'cancelled\')),
        starts_at INTEGER NOT NULL,
        synced_at INTEGER NOT NULL,
        UNIQUE (source, source_match_id)
    )');
    $connection->exec('CREATE INDEX IF NOT EXISTS matches_status_start ON matches(status, starts_at)');
    $pdo = $connection;
    return $pdo;
}
