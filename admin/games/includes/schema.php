<?php
declare(strict_types=1);
function games_schema(PDO $pdo): void
{
    foreach ($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $database) {
        if ($database['name'] === 'game_store') {
            $gamePdo = new PDO('sqlite:' . $database['file'], null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
            games_schema($gamePdo);
            return;
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS game_module_settings (id INTEGER PRIMARY KEY CHECK(id=1), enabled INTEGER NOT NULL DEFAULT 1 CHECK(enabled IN (0,1)))");
    $pdo->exec('INSERT OR IGNORE INTO game_module_settings(id,enabled) VALUES(1,1)');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS game_providers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            api_id INTEGER NOT NULL UNIQUE,
            name TEXT NOT NULL,
            thumbnail TEXT NOT NULL DEFAULT "",
            created_at INTEGER NOT NULL DEFAULT 0,
            updated_at INTEGER NOT NULL DEFAULT 0
        )'
    );
    game_providers_ensure_thumbnail_column($pdo);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_game_providers_name ON game_providers(name)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS game_types (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            api_id INTEGER NOT NULL UNIQUE,
            name TEXT NOT NULL,
            created_at INTEGER NOT NULL DEFAULT 0,
            updated_at INTEGER NOT NULL DEFAULT 0
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_game_types_name ON game_types(name)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS game_themes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            api_id INTEGER NOT NULL UNIQUE,
            name TEXT NOT NULL,
            created_at INTEGER NOT NULL DEFAULT 0,
            updated_at INTEGER NOT NULL DEFAULT 0
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_game_themes_name ON game_themes(name)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS games (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            api_id INTEGER NOT NULL UNIQUE,
            name TEXT NOT NULL,
            slug TEXT NOT NULL,
            url TEXT NOT NULL DEFAULT "",
            thumb TEXT NOT NULL DEFAULT "",
            short_description TEXT NOT NULL DEFAULT "",
            long_description TEXT NOT NULL DEFAULT "",
            provider_id INTEGER DEFAULT NULL,
            provider TEXT NOT NULL DEFAULT "",
            provider_slug TEXT NOT NULL DEFAULT "",
            type_id INTEGER DEFAULT NULL,
            type TEXT NOT NULL DEFAULT "",
            type_slug TEXT NOT NULL DEFAULT "",
            themes TEXT NOT NULL DEFAULT "[]",
            megaways INTEGER NOT NULL DEFAULT 0,
            bonus_buy INTEGER NOT NULL DEFAULT 0,
            progressive INTEGER NOT NULL DEFAULT 0,
            featured INTEGER NOT NULL DEFAULT 0,
            release TEXT NOT NULL DEFAULT "",
            reels TEXT NOT NULL DEFAULT "",
            rtp REAL DEFAULT NULL,
            volatility TEXT NOT NULL DEFAULT "",
            currencies TEXT NOT NULL DEFAULT "[]",
            languages TEXT NOT NULL DEFAULT "[]",
            land_based INTEGER NOT NULL DEFAULT 0,
            markets TEXT NOT NULL DEFAULT "[]",
            paylines TEXT NOT NULL DEFAULT "",
            max_exposure TEXT NOT NULL DEFAULT "",
            min_bet REAL DEFAULT NULL,
            max_bet REAL DEFAULT NULL,
            max_win_per_spin REAL DEFAULT NULL,
            autoplay INTEGER NOT NULL DEFAULT 0,
            quickspin INTEGER NOT NULL DEFAULT 0,
            tumbling_reels INTEGER NOT NULL DEFAULT 0,
            increasing_multipliers INTEGER NOT NULL DEFAULT 0,
            orientation TEXT NOT NULL DEFAULT "",
            restrictions TEXT NOT NULL DEFAULT "[]",
            upcoming INTEGER NOT NULL DEFAULT 0,
            published INTEGER NOT NULL DEFAULT 0,
            done_processing INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL DEFAULT 0,
            updated_at INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (provider_id) REFERENCES game_providers(id) ON DELETE SET NULL,
            FOREIGN KEY (type_id) REFERENCES game_types(id) ON DELETE SET NULL
        )'
    );
    games_ensure_text_column($pdo, 'short_description');
    games_ensure_text_column($pdo, 'long_description');
    if (games_ensure_integer_column($pdo, 'done_processing', 0)) {
        $pdo->exec('UPDATE games SET done_processing = 1');
    }
    games_remove_slug_unique_constraint($pdo);
    game_visibility_schema($pdo);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_games_slug ON games(slug)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_games_provider_id ON games(provider_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_games_type_id ON games(type_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_games_provider_slug ON games(provider_slug)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_games_type_slug ON games(type_slug)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_games_featured_published ON games(featured, published)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_games_published_updated_at ON games(published, updated_at DESC)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS game_theme_links (
            game_id INTEGER NOT NULL,
            theme_id INTEGER NOT NULL,
            PRIMARY KEY (game_id, theme_id),
            FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
            FOREIGN KEY (theme_id) REFERENCES game_themes(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_game_theme_links_theme_id ON game_theme_links(theme_id)');
}

function game_providers_ensure_thumbnail_column(PDO $pdo): void
{
    $columns = $pdo->query('PRAGMA table_info(game_providers)')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'thumbnail') {
            return;
        }
    }

    $pdo->exec('ALTER TABLE game_providers ADD COLUMN thumbnail TEXT NOT NULL DEFAULT ""');
}

function games_ensure_text_column(PDO $pdo, string $name): void
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
        throw new InvalidArgumentException('Invalid column name.');
    }

    $columns = $pdo->query('PRAGMA table_info(games)')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $column) {
        if (($column['name'] ?? '') === $name) {
            return;
        }
    }

    $pdo->exec('ALTER TABLE games ADD COLUMN ' . $name . ' TEXT NOT NULL DEFAULT ""');
}

function games_ensure_integer_column(PDO $pdo, string $name, int $existingDefault): bool
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
        throw new InvalidArgumentException('Invalid column name.');
    }

    $columns = $pdo->query('PRAGMA table_info(games)')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $column) {
        if (($column['name'] ?? '') === $name) {
            return false;
        }
    }

    $pdo->exec('ALTER TABLE games ADD COLUMN ' . $name . ' INTEGER NOT NULL DEFAULT ' . $existingDefault);
    return true;
}

function games_remove_slug_unique_constraint(PDO $pdo): void
{
    $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'games'")->fetchColumn();
    if (!is_string($sql) || !str_contains($sql, 'slug TEXT NOT NULL UNIQUE')) {
        return;
    }

    $pdo->exec('PRAGMA foreign_keys = OFF');
    try {
        $pdo->beginTransaction();
        $pdo->exec(
            'CREATE TABLE games_slug_migration (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                api_id INTEGER NOT NULL UNIQUE,
                name TEXT NOT NULL,
                slug TEXT NOT NULL,
                url TEXT NOT NULL DEFAULT "",
                thumb TEXT NOT NULL DEFAULT "",
                short_description TEXT NOT NULL DEFAULT "",
                long_description TEXT NOT NULL DEFAULT "",
                provider_id INTEGER DEFAULT NULL,
                provider TEXT NOT NULL DEFAULT "",
                provider_slug TEXT NOT NULL DEFAULT "",
                type_id INTEGER DEFAULT NULL,
                type TEXT NOT NULL DEFAULT "",
                type_slug TEXT NOT NULL DEFAULT "",
                themes TEXT NOT NULL DEFAULT "[]",
                megaways INTEGER NOT NULL DEFAULT 0,
                bonus_buy INTEGER NOT NULL DEFAULT 0,
                progressive INTEGER NOT NULL DEFAULT 0,
                featured INTEGER NOT NULL DEFAULT 0,
                release TEXT NOT NULL DEFAULT "",
                reels TEXT NOT NULL DEFAULT "",
                rtp REAL DEFAULT NULL,
                volatility TEXT NOT NULL DEFAULT "",
                currencies TEXT NOT NULL DEFAULT "[]",
                languages TEXT NOT NULL DEFAULT "[]",
                land_based INTEGER NOT NULL DEFAULT 0,
                markets TEXT NOT NULL DEFAULT "[]",
                paylines TEXT NOT NULL DEFAULT "",
                max_exposure TEXT NOT NULL DEFAULT "",
                min_bet REAL DEFAULT NULL,
                max_bet REAL DEFAULT NULL,
                max_win_per_spin REAL DEFAULT NULL,
                autoplay INTEGER NOT NULL DEFAULT 0,
                quickspin INTEGER NOT NULL DEFAULT 0,
                tumbling_reels INTEGER NOT NULL DEFAULT 0,
                increasing_multipliers INTEGER NOT NULL DEFAULT 0,
                orientation TEXT NOT NULL DEFAULT "",
                restrictions TEXT NOT NULL DEFAULT "[]",
                upcoming INTEGER NOT NULL DEFAULT 0,
                published INTEGER NOT NULL DEFAULT 0,
                done_processing INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0,
                FOREIGN KEY (provider_id) REFERENCES game_providers(id) ON DELETE SET NULL,
                FOREIGN KEY (type_id) REFERENCES game_types(id) ON DELETE SET NULL
            )'
        );
        $pdo->exec(
            'INSERT INTO games_slug_migration (
                id, api_id, name, slug, url, thumb, short_description, long_description, provider_id, provider, provider_slug,
                type_id, type, type_slug, themes, megaways, bonus_buy, progressive, featured,
                release, reels, rtp, volatility, currencies, languages, land_based, markets,
                paylines, max_exposure, min_bet, max_bet, max_win_per_spin, autoplay, quickspin,
                tumbling_reels, increasing_multipliers, orientation, restrictions, upcoming, done_processing,
                published, created_at, updated_at
            )
            SELECT
                id, api_id, name, slug, url, thumb, short_description, long_description, provider_id, provider, provider_slug,
                type_id, type, type_slug, themes, megaways, bonus_buy, progressive, featured,
                release, reels, rtp, volatility, currencies, languages, land_based, markets,
                paylines, max_exposure, min_bet, max_bet, max_win_per_spin, autoplay, quickspin,
                tumbling_reels, increasing_multipliers, orientation, restrictions, upcoming, done_processing,
                published, created_at, updated_at
            FROM games'
        );
        $pdo->exec('DROP TABLE games');
        $pdo->exec('ALTER TABLE games_slug_migration RENAME TO games');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('DROP TABLE IF EXISTS games_slug_migration');
        $pdo->exec('PRAGMA foreign_keys = ON');
        throw $e;
    }

    $pdo->exec('PRAGMA foreign_keys = ON');
}
