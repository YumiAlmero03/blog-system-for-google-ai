<?php
declare(strict_types=1);
require_once __DIR__ . '/game-restrictions.php';

function game_visibility_schema(PDO $pdo): void
{
    games_ensure_integer_column($pdo, 'is_viewable', 1);
    // Games may have only a provider slug/name, without a game_providers record.
    // Store approval overrides only, reusing that identity and never copying names.
    $pdo->exec('CREATE TABLE IF NOT EXISTS game_provider_settings (
        provider_key TEXT PRIMARY KEY,
        approved INTEGER NOT NULL DEFAULT 1 CHECK(approved IN (0,1)),
        updated_at INTEGER NOT NULL
    )');
}

function game_provider_key_sql(string $alias = 'games'): string
{
    return "CASE WHEN $alias.provider_slug <> '' THEN 'slug:' || $alias.provider_slug ELSE 'name:' || lower(trim($alias.provider)) END";
}

function game_visibility_sql(string $alias = 'games'): string
{
    $key = game_provider_key_sql($alias);
    return "$alias.is_viewable = 1 AND NOT EXISTS (SELECT 1 FROM game_provider_settings gps WHERE gps.provider_key = ($key) AND gps.approved = 0)";
}

function game_public_eligibility_sql(string $alias = 'games'): string
{
    return "$alias.published = 1 AND $alias.done_processing = 1 AND (" . games_ph_allowed_sql($alias . '.restrictions') . ') AND (' . game_visibility_sql($alias) . ')';
}

function game_provider_settings_rows(PDO $pdo): array
{
    $key = game_provider_key_sql();
    return $pdo->query("SELECT ($key) AS provider_key, MAX(provider) AS name, MAX(provider_slug) AS slug,
        COUNT(*) AS game_count, COALESCE(s.approved,1) AS approved
        FROM games LEFT JOIN game_provider_settings s ON s.provider_key=($key)
        GROUP BY ($key) ORDER BY name COLLATE NOCASE,slug")->fetchAll();
}

function game_provider_approval_save(PDO $pdo, string $key, mixed $approved): void
{
    $approved = game_visibility_value($approved);
    $identity = game_provider_key_sql();
    $stmt = $pdo->prepare("SELECT 1 FROM games WHERE ($identity)=? LIMIT 1");
    $stmt->execute([$key]);
    if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Provider not found.');
    $stmt = $pdo->prepare('INSERT INTO game_provider_settings(provider_key,approved,updated_at) VALUES(?,?,?)
        ON CONFLICT(provider_key) DO UPDATE SET approved=excluded.approved,updated_at=excluded.updated_at');
    $stmt->execute([$key,$approved,time()]);
}

function game_visibility_value(mixed $value): int
{
    if (!in_array($value,[0,1,'0','1'],true)) throw new InvalidArgumentException('Visibility must be 0 or 1.');
    return (int)$value;
}

class GameSitemapRefreshException extends RuntimeException {}

function game_visibility_refresh(): void
{
    blog_clear_api_cache();
    require_once dirname(__DIR__) . '/scripts/generate-game-sitemaps.php';
    $output = env_value('GAME_SITEMAP_OUTPUT_DIR') ?: dirname(__DIR__);
    try { generate_game_sitemaps(blogs_db_path(), $output); }
    catch (Throwable $error) { throw new GameSitemapRefreshException('Game settings saved, but sitemap refresh failed. Save again to retry.', 0, $error); }
}
