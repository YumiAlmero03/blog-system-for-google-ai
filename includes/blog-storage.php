<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

const BLOG_CATEGORIES = ['Guides', 'Troubleshooting', 'Casino', 'Promotions', 'Community'];
const BLOG_STATUSES = ['published', 'draft', 'scheduled'];
const BLOG_DEFAULT_AUTHOR = 'Editorial Team';
const BLOG_DEFAULT_IMAGE = '/uploads/blogs/default-featured.svg';
const BLOG_DEFAULT_SITE_TITLE = 'Blog';

function blog_storage_dir(): string
{
    $storageDir = env_value('APP_STORAGE_DIR');
    if (!is_string($storageDir) || $storageDir === '' || $storageDir === '/absolute/path/outside/public/storage') {
        $storageDir = dirname(__DIR__) . '/storage';
    }

    return rtrim($storageDir, '/\\');
}

function blogs_json_path(): string
{
    return blog_storage_dir() . '/blogs.min.json';
}

function blogs_db_path(): string
{
    return blog_storage_dir() . '/blogs.sqlite';
}

function blog_storage_log_error(string $operation, Throwable $exception): void
{
    error_log(sprintf(
        'Blog storage error [%s] path=%s: %s',
        $operation,
        blogs_db_path(),
        $exception->getMessage()
    ));
}

function restore_blog_database_from_backup(): void
{
    $dbPath = blogs_db_path();
    $storageDir = blog_storage_dir();
    $candidates = [];

    foreach ((glob($storageDir . '/blogs.sqlite*') ?: []) as $path) {
        $name = basename($path);
        if ($path === $dbPath) {
            continue;
        }
        if (str_ends_with($name, '-wal') || str_ends_with($name, '-shm')) {
            continue;
        }
        if (str_contains($name, '.corrupt-')) {
            continue;
        }
        if (!preg_match('/^blogs\.sqlite(?:\.|$)/', $name)) {
            continue;
        }
        $candidates[] = $path;
    }

    usort($candidates, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

    foreach ($candidates as $candidate) {
        foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $pathToRemove) {
            if (@is_file($pathToRemove)) {
                @unlink($pathToRemove);
            }
        }

        if (@copy($candidate, $dbPath)) {
            @chmod($dbPath, 0600);
            return;
        }
    }

    foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $pathToRemove) {
        if (@is_file($pathToRemove)) {
            @unlink($pathToRemove);
        }
    }
}

function ensure_blog_storage_dir(): void
{
    $dir = blog_storage_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Blog storage directory unavailable.');
    }
}

function blogs_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    ensure_blog_storage_dir();

    $dbPath = blogs_db_path();
    $createPdo = static function (): PDO {
        $pdo = new PDO('sqlite:' . blogs_db_path(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');

        return $pdo;
    };

    try {
        $pdo = $createPdo();
        $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();
        if ($integrity !== 'ok') {
            throw new RuntimeException('SQLite integrity check failed: ' . (string) $integrity);
        }
    } catch (Throwable $exception) {
        blog_storage_log_error('db-restore', $exception);
        restore_blog_database_from_backup();

        if (!is_file($dbPath)) {
            touch($dbPath);
        }

        try {
            $pdo = $createPdo();
            $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();
            if ($integrity !== 'ok') {
                throw new RuntimeException('Recovered SQLite database is still invalid: ' . (string) $integrity);
            }
        } catch (Throwable $recoveryException) {
            blog_storage_log_error('db-recovery', $recoveryException);
            $pdo = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA journal_mode = DELETE');
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    blogs_schema($pdo);
    blogs_migrate_json($pdo);

    return $pdo;
}

function blogs_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS blog_posts (
            id TEXT PRIMARY KEY,
            slug TEXT NOT NULL UNIQUE,
            title TEXT NOT NULL,
            seo_title TEXT NOT NULL DEFAULT "",
            category TEXT NOT NULL,
            author TEXT NOT NULL,
            excerpt TEXT NOT NULL,
            content TEXT NOT NULL,
            featured_image TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "published",
            focus_keyphrase TEXT NOT NULL DEFAULT "",
            date_label TEXT NOT NULL,
            published_at INTEGER DEFAULT NULL,
            scheduled_at INTEGER DEFAULT NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )'
    );
    $columns = $pdo->query('PRAGMA table_info(blog_posts)')->fetchAll();
    $hasStatus = false;
    $hasFocusKeyphrase = false;
    $hasSeoTitle = false;
    $hasPublishedAt = false;
    $hasScheduledAt = false;
    foreach ($columns as $column) {
        if (($column['name'] ?? null) === 'status') {
            $hasStatus = true;
        }
        if (($column['name'] ?? null) === 'focus_keyphrase') {
            $hasFocusKeyphrase = true;
        }
        if (($column['name'] ?? null) === 'seo_title') {
            $hasSeoTitle = true;
        }
        if (($column['name'] ?? null) === 'published_at') {
            $hasPublishedAt = true;
        }
        if (($column['name'] ?? null) === 'scheduled_at') {
            $hasScheduledAt = true;
        }
    }
    if (!$hasStatus) {
        $pdo->exec('ALTER TABLE blog_posts ADD COLUMN status TEXT NOT NULL DEFAULT "published"');
    }
    if (!$hasFocusKeyphrase) {
        $pdo->exec('ALTER TABLE blog_posts ADD COLUMN focus_keyphrase TEXT NOT NULL DEFAULT ""');
    }
    if (!$hasSeoTitle) {
        $pdo->exec('ALTER TABLE blog_posts ADD COLUMN seo_title TEXT NOT NULL DEFAULT ""');
        $pdo->exec('UPDATE blog_posts SET seo_title = title WHERE seo_title = ""');
    }
    if (!$hasPublishedAt) {
        $pdo->exec('ALTER TABLE blog_posts ADD COLUMN published_at INTEGER DEFAULT NULL');
        $pdo->exec('UPDATE blog_posts SET published_at = created_at WHERE status = "published" AND published_at IS NULL');
    }
    if (!$hasScheduledAt) {
        $pdo->exec('ALTER TABLE blog_posts ADD COLUMN scheduled_at INTEGER DEFAULT NULL');
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_blog_posts_updated_at ON blog_posts(updated_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_blog_posts_status_updated_at ON blog_posts(status, updated_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_blog_posts_scheduled_at ON blog_posts(status, scheduled_at)');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS blog_categories (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL UNIQUE,
            description TEXT NOT NULL DEFAULT "",
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_blog_categories_sort_order ON blog_categories(sort_order ASC, name ASC)');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at INTEGER NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS blog_engagements (
            post_id TEXT NOT NULL,
            action TEXT NOT NULL,
            ip_hash TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            PRIMARY KEY (post_id, action, ip_hash),
            FOREIGN KEY (post_id) REFERENCES blog_posts(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_blog_engagements_post_action ON blog_engagements(post_id, action)');
    games_schema($pdo);
    blog_settings_seed($pdo);
    blog_categories_seed($pdo);
    blogs_publish_due_scheduled($pdo);
}

function games_schema(PDO $pdo): void
{
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

function blog_settings_seed(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO app_settings (setting_key, setting_value, updated_at)
         VALUES (:setting_key, :setting_value, :updated_at)'
    );
    $stmt->execute([
        ':setting_key' => 'website_title',
        ':setting_value' => BLOG_DEFAULT_SITE_TITLE,
        ':updated_at' => time(),
    ]);
    $stmt->execute([
        ':setting_key' => 'ignored_engagement_ips',
        ':setting_value' => '',
        ':updated_at' => time(),
    ]);
}

function blog_categories_seed(PDO $pdo): void
{
    $now = time();
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO blog_categories (id, name, description, sort_order, created_at, updated_at)
         VALUES (:id, :name, "", :sort_order, :created_at, :updated_at)'
    );

    foreach (BLOG_CATEGORIES as $index => $category) {
        $stmt->execute([
            ':id' => normalize_slug($category),
            ':name' => $category,
            ':sort_order' => ($index + 1) * 10,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    $existingStmt = $pdo->query('SELECT DISTINCT category FROM blog_posts WHERE TRIM(category) <> ""');
    foreach ($existingStmt->fetchAll(PDO::FETCH_COLUMN) as $category) {
        $name = normalize_blog_category_name((string) $category);
        if ($name === null) {
            continue;
        }
        $stmt->execute([
            ':id' => normalize_slug($name),
            ':name' => $name,
            ':sort_order' => 1000,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
}

function blogs_migrate_json(PDO $pdo): void
{
    $marker = blog_storage_dir() . '/blogs-json-migrated';
    if (is_file($marker)) {
        return;
    }

    $jsonPath = blogs_json_path();
    if (!is_file($jsonPath) || !is_readable($jsonPath)) {
        @file_put_contents($marker, (string) time());
        return;
    }

    $raw = file_get_contents($jsonPath);
    $blogs = json_decode($raw !== false ? $raw : '', true);
    if (!is_array($blogs)) {
        @file_put_contents($marker, (string) time());
        return;
    }

    $pdo->beginTransaction();
    try {
        foreach ($blogs as $blog) {
            if (!is_array($blog)) {
                continue;
            }
            $normalized = blog_normalize_existing($blog);
            if ($normalized === null) {
                continue;
            }
            blogs_upsert_with_pdo($pdo, $normalized);
            blog_seed_legacy_engagements($pdo, (string) $normalized['id'], $blog);
        }
        $pdo->commit();
        @file_put_contents($marker, (string) time());
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function blog_normalize_existing(array $blog): ?array
{
    $title = isset($blog['title']) && is_string($blog['title']) ? trim($blog['title']) : '';
    $slug = isset($blog['slug']) && is_string($blog['slug']) ? normalize_slug($blog['slug']) : '';
    if ($slug === '' && isset($blog['id']) && is_string($blog['id'])) {
        $slug = normalize_slug($blog['id']);
    }
    if ($slug === '' && $title !== '') {
        $slug = normalize_slug($title);
    }
    if ($title === '' || $slug === '') {
        return null;
    }

    $now = time();
    $status = normalize_blog_status($blog['status'] ?? null);
    $publishedAt = isset($blog['publishedAt']) && is_int($blog['publishedAt']) ? $blog['publishedAt'] : null;
    if ($publishedAt === null && isset($blog['published_at']) && is_int($blog['published_at'])) {
        $publishedAt = $blog['published_at'];
    }
    if ($publishedAt === null && $status === 'published') {
        $publishedAt = isset($blog['timestamp']) && is_int($blog['timestamp']) ? (int) floor($blog['timestamp'] / 1000) : $now;
    }
    $scheduledAt = isset($blog['scheduledAt']) && is_int($blog['scheduledAt']) ? $blog['scheduledAt'] : null;
    if ($scheduledAt === null && isset($blog['scheduled_at']) && is_int($blog['scheduled_at'])) {
        $scheduledAt = $blog['scheduled_at'];
    }
    return [
        'id' => $slug,
        'slug' => $slug,
        'title' => substr($title, 0, 160),
        'seoTitle' => normalize_seo_title($blog['seoTitle'] ?? ($blog['seo_title'] ?? $title)),
        'category' => normalize_blog_category_name($blog['category'] ?? null) ?? blog_default_category(),
        'author' => isset($blog['author']) && is_string($blog['author']) && trim($blog['author']) !== '' ? substr(trim($blog['author']), 0, 80) : BLOG_DEFAULT_AUTHOR,
        'excerpt' => isset($blog['excerpt']) && is_string($blog['excerpt']) ? substr(trim($blog['excerpt']), 0, 360) : '',
        'content' => isset($blog['content']) && is_string($blog['content']) ? substr(trim($blog['content']), 0, 60000) : '',
        'featuredImage' => isset($blog['featuredImage']) && is_string($blog['featuredImage']) ? normalize_featured_image($blog['featuredImage']) : BLOG_DEFAULT_IMAGE,
        'status' => $status,
        'focusKeyphrase' => normalize_focus_keyphrase($blog['focusKeyphrase'] ?? ($blog['focus_keyphrase'] ?? '')),
        'date' => isset($blog['date']) && is_string($blog['date']) && trim($blog['date']) !== '' ? substr(trim($blog['date']), 0, 32) : blog_format_date_label($publishedAt ?? $scheduledAt ?? $now),
        'publishedAt' => $publishedAt,
        'scheduledAt' => $scheduledAt,
        'createdAt' => isset($blog['createdAt']) && is_int($blog['createdAt']) ? $blog['createdAt'] : (isset($blog['timestamp']) && is_int($blog['timestamp']) ? (int) floor($blog['timestamp'] / 1000) : $now),
        'updatedAt' => isset($blog['updatedAt']) && is_int($blog['updatedAt']) ? $blog['updatedAt'] : $now,
    ];
}

function blog_row_to_array(array $row): array
{
    $stats = [
        'views' => array_key_exists('views', $row) ? (int) $row['views'] : null,
        'likes' => array_key_exists('likes', $row) ? (int) $row['likes'] : null,
        'dislikes' => array_key_exists('dislikes', $row) ? (int) $row['dislikes'] : null,
    ];
    if ($stats['views'] === null || $stats['likes'] === null || $stats['dislikes'] === null) {
        $stats = blog_engagement_counts((string) ($row['id'] ?? ''));
    }

    $publishedAt = isset($row['published_at']) && is_numeric($row['published_at']) ? (int) $row['published_at'] : null;
    $scheduledAt = isset($row['scheduled_at']) && is_numeric($row['scheduled_at']) ? (int) $row['scheduled_at'] : null;
    $status = normalize_blog_status($row['status'] ?? null);
    $isPublic = blog_row_is_public([
        'status' => $status,
        'scheduled_at' => $scheduledAt,
    ]);

    return [
        'id' => $row['id'],
        'slug' => $row['slug'],
        'title' => $row['title'],
        'seoTitle' => normalize_seo_title($row['seo_title'] ?? $row['title']),
        'category' => $row['category'],
        'author' => $row['author'],
        'excerpt' => $row['excerpt'],
        'content' => $row['content'],
        'featuredImage' => $row['featured_image'],
        'status' => $isPublic ? 'published' : $status,
        'focusKeyphrase' => normalize_focus_keyphrase($row['focus_keyphrase'] ?? ''),
        'date' => $row['date_label'],
        'publishedAt' => $publishedAt,
        'publishedAtInput' => blog_format_admin_datetime_input($publishedAt),
        'scheduledAt' => $scheduledAt,
        'scheduledAtInput' => blog_format_admin_datetime_input($scheduledAt),
        'isPublic' => $isPublic,
        'createdAt' => (int) $row['created_at'],
        'updatedAt' => (int) $row['updated_at'],
        'views' => (int) $stats['views'],
        'likes' => (int) $stats['likes'],
        'dislikes' => (int) $stats['dislikes'],
    ];
}

function normalize_blog_status(mixed $status): string
{
    if (!is_string($status)) {
        return 'published';
    }

    $status = strtolower(trim($status));
    return in_array($status, BLOG_STATUSES, true) ? $status : 'published';
}

function blog_display_timezone(): DateTimeZone
{
    static $timezone = null;
    if ($timezone instanceof DateTimeZone) {
        return $timezone;
    }

    try {
        $timezone = new DateTimeZone(env_value('ADMIN_TIMEZONE') ?: 'Asia/Manila');
    } catch (Exception) {
        $timezone = new DateTimeZone('Asia/Manila');
    }

    return $timezone;
}

function blog_parse_admin_datetime(mixed $value): ?int
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    $value = trim($value);
    $formats = ['Y-m-d\TH:i', 'Y-m-d H:i'];
    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, blog_display_timezone());
        $errors = DateTimeImmutable::getLastErrors();
        if ($date instanceof DateTimeImmutable && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))) {
            return $date->getTimestamp();
        }
    }

    try {
        return (new DateTimeImmutable($value, blog_display_timezone()))->getTimestamp();
    } catch (Exception) {
        return null;
    }
}

function blog_format_admin_datetime_input(?int $timestamp): string
{
    if ($timestamp === null) {
        return '';
    }

    return (new DateTimeImmutable('@' . $timestamp))
        ->setTimezone(blog_display_timezone())
        ->format('Y-m-d\TH:i');
}

function blog_format_date_label(int $timestamp): string
{
    return (new DateTimeImmutable('@' . $timestamp))
        ->setTimezone(blog_display_timezone())
        ->format('M j, Y');
}

function blog_public_visibility_sql(string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    return '(' . $prefix . 'status = "published" OR (' . $prefix . 'status = "scheduled" AND ' . $prefix . 'scheduled_at IS NOT NULL AND ' . $prefix . 'scheduled_at <= :visibility_now))';
}

function blog_row_is_public(array $row, ?int $now = null): bool
{
    $status = normalize_blog_status($row['status'] ?? null);
    if ($status === 'published') {
        return true;
    }

    $scheduledAt = isset($row['scheduled_at']) && is_numeric($row['scheduled_at']) ? (int) $row['scheduled_at'] : null;
    return $status === 'scheduled' && $scheduledAt !== null && $scheduledAt <= ($now ?? time());
}

function blog_post_is_public(array $post, ?int $now = null): bool
{
    return blog_row_is_public([
        'status' => $post['status'] ?? null,
        'scheduled_at' => $post['scheduledAt'] ?? ($post['scheduled_at'] ?? null),
    ], $now);
}

function blogs_publish_due_scheduled(PDO $pdo, ?int $now = null): void
{
    $now ??= time();
    $stmt = $pdo->prepare(
        'UPDATE blog_posts
         SET status = "published",
             published_at = COALESCE(scheduled_at, published_at, :now),
             updated_at = :now
         WHERE status = "scheduled"
           AND scheduled_at IS NOT NULL
           AND scheduled_at <= :now'
    );
    $stmt->execute([':now' => $now]);
}

function normalize_focus_keyphrase(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }

    $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
    return substr($value, 0, 160);
}

function normalize_seo_title(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }

    $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
    return substr($value, 0, 160);
}

function blog_setting_get(string $key, string $default = ''): string
{
    $stmt = blogs_pdo()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :setting_key LIMIT 1');
    $stmt->execute([':setting_key' => $key]);
    $value = $stmt->fetchColumn();

    return is_string($value) && $value !== '' ? $value : $default;
}

function blog_setting_set(string $key, string $value, int $maxLength = 255): void
{
    $key = strtolower(trim($key));
    $key = preg_replace('/[^a-z0-9_:-]+/', '_', $key) ?? '';
    $key = trim($key, '_');
    if ($key === '') {
        throw new InvalidArgumentException('Setting key is invalid.');
    }

    $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
    $value = substr($value, 0, $maxLength);

    $stmt = blogs_pdo()->prepare(
        'INSERT INTO app_settings (setting_key, setting_value, updated_at)
         VALUES (:setting_key, :setting_value, :updated_at)
         ON CONFLICT(setting_key) DO UPDATE SET
            setting_value = excluded.setting_value,
            updated_at = excluded.updated_at'
    );
    $stmt->execute([
        ':setting_key' => $key,
        ':setting_value' => $value,
        ':updated_at' => time(),
    ]);

    blog_clear_api_cache();
}

function blog_website_title(): string
{
    return blog_setting_get('website_title', BLOG_DEFAULT_SITE_TITLE);
}

function blog_ignored_engagement_ips(): string
{
    return blog_setting_get('ignored_engagement_ips', '');
}

function blog_setting_set_multiline(string $key, string $value, int $maxLength = 4000): void
{
    $key = strtolower(trim($key));
    $key = preg_replace('/[^a-z0-9_:-]+/', '_', $key) ?? '';
    $key = trim($key, '_');
    if ($key === '') {
        throw new InvalidArgumentException('Setting key is invalid.');
    }

    $value = str_replace(["\r\n", "\r"], "\n", trim($value));
    $value = preg_replace("/[ \t]+/", ' ', $value) ?? '';
    $value = substr($value, 0, $maxLength);

    $stmt = blogs_pdo()->prepare(
        'INSERT INTO app_settings (setting_key, setting_value, updated_at)
         VALUES (:setting_key, :setting_value, :updated_at)
         ON CONFLICT(setting_key) DO UPDATE SET
            setting_value = excluded.setting_value,
            updated_at = excluded.updated_at'
    );
    $stmt->execute([
        ':setting_key' => $key,
        ':setting_value' => $value,
        ':updated_at' => time(),
    ]);
}

function blog_plain_text_from_markdown(string $markdown): string
{
    $text = preg_replace('/```[\s\S]*?```/', ' ', $markdown) ?? $markdown;
    $text = preg_replace('/!\[[^\]]*\]\([^)]+\)/', ' ', $text) ?? $text;
    $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text) ?? $text;
    $text = preg_replace('/:::faq|:::|[#>*_`~|[\](){}-]+/', ' ', $text) ?? $text;
    return trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
}

function blog_read_minutes(string $content): int
{
    preg_match_all('/[\p{L}\p{N}]+(?:[\'’.-][\p{L}\p{N}]+)*/u', blog_plain_text_from_markdown($content), $matches);
    $wordCount = isset($matches[0]) ? count($matches[0]) : 0;
    return max(1, (int) ceil($wordCount / 200));
}

function blog_engagement_counts(string $postId): array
{
    $postId = normalize_slug($postId);
    if ($postId === '') {
        return ['views' => 0, 'likes' => 0, 'dislikes' => 0];
    }

    $stmt = blogs_pdo()->prepare(
        'SELECT action, COUNT(*) AS total
         FROM blog_engagements
         WHERE post_id = :post_id
         GROUP BY action'
    );
    $stmt->execute([':post_id' => $postId]);

    $counts = ['views' => 0, 'likes' => 0, 'dislikes' => 0];
    foreach ($stmt->fetchAll() as $row) {
        $action = (string) ($row['action'] ?? '');
        if ($action === 'view') {
            $counts['views'] = (int) ($row['total'] ?? 0);
        } elseif ($action === 'like') {
            $counts['likes'] = (int) ($row['total'] ?? 0);
        } elseif ($action === 'dislike') {
            $counts['dislikes'] = (int) ($row['total'] ?? 0);
        }
    }

    return $counts;
}

function blog_request_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        $value = $_SERVER[$key] ?? '';
        if (is_string($value) && filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }
    }

    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if (is_string($forwarded)) {
        $first = trim(explode(',', $forwarded)[0] ?? '');
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }

    return '';
}

function blog_ip_matches_cidr(string $ip, string $cidr): bool
{
    [$range, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
    if (!is_string($bits) || !ctype_digit($bits)) {
        return false;
    }

    $ipBin = @inet_pton($ip);
    $rangeBin = @inet_pton(trim($range));
    if ($ipBin === false || $rangeBin === false || strlen($ipBin) !== strlen($rangeBin)) {
        return false;
    }

    $maxBits = strlen($ipBin) * 8;
    $bitsInt = max(0, min($maxBits, (int) $bits));
    $bytes = intdiv($bitsInt, 8);
    $remainder = $bitsInt % 8;
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($rangeBin, 0, $bytes)) {
        return false;
    }
    if ($remainder === 0) {
        return true;
    }

    $mask = (0xff << (8 - $remainder)) & 0xff;
    return (ord($ipBin[$bytes]) & $mask) === (ord($rangeBin[$bytes]) & $mask);
}

function blog_ip_is_ignored(string $ip): bool
{
    if ($ip === '') {
        return true;
    }

    $entries = preg_split('/[\s,]+/', blog_ignored_engagement_ips()) ?: [];
    foreach ($entries as $entry) {
        $entry = trim($entry);
        if ($entry === '') {
            continue;
        }
        if ($entry === $ip || (str_contains($entry, '/') && blog_ip_matches_cidr($ip, $entry))) {
            return true;
        }
    }

    return false;
}

function blog_engagement_ip_hash(string $ip): string
{
    $salt = env_value('APP_KEY');
    if (!is_string($salt) || $salt === '') {
        $salt = blogs_db_path();
    }

    return hash_hmac('sha256', $ip, $salt);
}

function blog_record_engagement(string $postId, string $action): array
{
    $post = blogs_find($postId);
    if ($post === null) {
        return ['ok' => false, 'error' => 'Blog post not found.', 'counts' => ['views' => 0, 'likes' => 0, 'dislikes' => 0]];
    }

    $action = strtolower(trim($action));
    if (!in_array($action, ['view', 'like', 'dislike'], true)) {
        return ['ok' => false, 'error' => 'Invalid action.', 'counts' => blog_engagement_counts((string) $post['id'])];
    }

    $ip = blog_request_ip();
    if (blog_ip_is_ignored($ip)) {
        return ['ok' => true, 'ignored' => true, 'counts' => blog_engagement_counts((string) $post['id'])];
    }

    $pdo = blogs_pdo();
    $now = time();
    $ipHash = blog_engagement_ip_hash($ip);
    if ($action === 'like' || $action === 'dislike') {
        $opposite = $action === 'like' ? 'dislike' : 'like';
        $deleteStmt = $pdo->prepare('DELETE FROM blog_engagements WHERE post_id = :post_id AND action = :action AND ip_hash = :ip_hash');
        $deleteStmt->execute([
            ':post_id' => $post['id'],
            ':action' => $opposite,
            ':ip_hash' => $ipHash,
        ]);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO blog_engagements (post_id, action, ip_hash, created_at, updated_at)
         VALUES (:post_id, :action, :ip_hash, :created_at, :updated_at)
         ON CONFLICT(post_id, action, ip_hash) DO UPDATE SET updated_at = excluded.updated_at'
    );
    $stmt->execute([
        ':post_id' => $post['id'],
        ':action' => $action,
        ':ip_hash' => $ipHash,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    blog_clear_api_cache();
    return ['ok' => true, 'ignored' => false, 'counts' => blog_engagement_counts((string) $post['id'])];
}

function normalize_blog_category_name(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
    if ($value === '' || strlen($value) > 50) {
        return null;
    }

    return $value;
}

function blog_default_category(): string
{
    $categories = blog_categories_all();
    $first = $categories[0]['name'] ?? null;
    return is_string($first) && $first !== '' ? $first : BLOG_CATEGORIES[0];
}

function blog_categories_all(bool $includeCounts = false): array
{
    $pdo = blogs_pdo();
    $sql = $includeCounts
        ? 'SELECT c.*, COUNT(p.id) AS post_count
           FROM blog_categories c
           LEFT JOIN blog_posts p ON p.category = c.name
           GROUP BY c.id
           ORDER BY c.sort_order DESC, c.name ASC'
        : 'SELECT *, 0 AS post_count FROM blog_categories ORDER BY sort_order DESC, name ASC';
    $rows = $pdo->query($sql)->fetchAll();

    return array_map(static function (array $row): array {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'sortOrder' => (int) ($row['sort_order'] ?? 0),
            'postCount' => (int) ($row['post_count'] ?? 0),
            'createdAt' => (int) ($row['created_at'] ?? 0),
            'updatedAt' => (int) ($row['updated_at'] ?? 0),
        ];
    }, $rows);
}

function blog_category_names(): array
{
    return array_map(static fn (array $category): string => $category['name'], blog_categories_all());
}

function blog_categories_page(): array
{
    return blog_categories_all(true);
}

function blog_category_find(string $id): ?array
{
    $id = normalize_slug($id);
    if ($id === '') {
        return null;
    }

    $stmt = blogs_pdo()->prepare(
        'SELECT c.*, COUNT(p.id) AS post_count
         FROM blog_categories c
         LEFT JOIN blog_posts p ON p.category = c.name
         WHERE c.id = :id
         GROUP BY c.id
         LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (string) ($row['id'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'sortOrder' => (int) ($row['sort_order'] ?? 0),
        'postCount' => (int) ($row['post_count'] ?? 0),
        'createdAt' => (int) ($row['created_at'] ?? 0),
        'updatedAt' => (int) ($row['updated_at'] ?? 0),
    ];
}

function blog_category_save(array $input): array
{
    $name = normalize_blog_category_name($input['name'] ?? null);
    if ($name === null) {
        return ['ok' => false, 'errors' => ['Category name is required and must be 50 characters or less.']];
    }

    $existingId = isset($input['id']) && is_string($input['id']) ? normalize_slug($input['id']) : '';
    $id = normalize_slug($name);
    if ($id === '') {
        return ['ok' => false, 'errors' => ['Category slug is invalid.']];
    }

    $description = isset($input['description']) && is_string($input['description'])
        ? substr(trim($input['description']), 0, 180)
        : '';
    $sortOrder = isset($input['sort_order']) && is_numeric($input['sort_order'])
        ? max(0, min(100000, (int) $input['sort_order']))
        : 0;

    $pdo = blogs_pdo();
    $duplicateStmt = $pdo->prepare('SELECT id FROM blog_categories WHERE (id = :id OR lower(name) = lower(:name)) AND id <> :existing_id LIMIT 1');
    $duplicateStmt->execute([
        ':id' => $id,
        ':name' => $name,
        ':existing_id' => $existingId,
    ]);
    if ($duplicateStmt->fetchColumn()) {
        return ['ok' => false, 'errors' => ['A category with this name already exists.']];
    }

    $existing = $existingId !== '' ? blog_category_find($existingId) : null;
    $now = time();
    $pdo->beginTransaction();
    try {
        if ($existing) {
            $stmt = $pdo->prepare(
                'UPDATE blog_categories
                 SET id = :id, name = :name, description = :description, sort_order = :sort_order, updated_at = :updated_at
                 WHERE id = :existing_id'
            );
            $stmt->execute([
                ':id' => $id,
                ':name' => $name,
                ':description' => $description,
                ':sort_order' => $sortOrder,
                ':updated_at' => $now,
                ':existing_id' => $existingId,
            ]);

            if ($existing['name'] !== $name) {
                $postsStmt = $pdo->prepare('UPDATE blog_posts SET category = :new_name, updated_at = :updated_at WHERE category = :old_name');
                $postsStmt->execute([
                    ':new_name' => $name,
                    ':old_name' => $existing['name'],
                    ':updated_at' => $now,
                ]);
            }
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO blog_categories (id, name, description, sort_order, created_at, updated_at)
                 VALUES (:id, :name, :description, :sort_order, :created_at, :updated_at)'
            );
            $stmt->execute([
                ':id' => $id,
                ':name' => $name,
                ':description' => $description,
                ':sort_order' => $sortOrder,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    blog_clear_api_cache();
    return ['ok' => true, 'category' => blog_category_find($id)];
}

function blog_category_delete(string $id): array
{
    $category = blog_category_find($id);
    if (!$category) {
        return ['ok' => false, 'error' => 'Category not found.'];
    }
    if ($category['postCount'] > 0) {
        return ['ok' => false, 'error' => 'Move or edit posts in this category before deleting it.'];
    }
    if (count(blog_categories_all()) <= 1) {
        return ['ok' => false, 'error' => 'At least one category is required.'];
    }

    $stmt = blogs_pdo()->prepare('DELETE FROM blog_categories WHERE id = :id');
    $stmt->execute([':id' => normalize_slug($id)]);
    blog_clear_api_cache();

    return ['ok' => true, 'deleted' => $stmt->rowCount() > 0];
}

function blog_clear_api_cache(): void
{
    $dir = blog_storage_dir() . '/api-cache';
    if (!is_dir($dir)) {
        return;
    }

    foreach (glob($dir . '/*.json') ?: [] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function normalize_blog_category_filter(?string $category): ?string
{
    if (!is_string($category)) {
        return null;
    }

    $category = trim($category);
    if ($category === '') {
        return null;
    }

    foreach (blog_category_names() as $allowedCategory) {
        if (strcasecmp($allowedCategory, $category) === 0) {
            return $allowedCategory;
        }
    }

    return null;
}

function request_blog_category_filter(): ?string
{
    if (!isset($_POST['category']) || is_array($_POST['category'])) {
        return null;
    }

    return normalize_blog_category_filter((string) $_POST['category']);
}

function blog_like_term(string $value): string
{
    return '%' . strtr($value, [
        '\\' => '\\\\',
        '%' => '\\%',
        '_' => '\\_',
    ]) . '%';
}

function blog_extract_internal_link_slugs(string $content, array $knownSlugs): array
{
    if ($content === '' || $knownSlugs === []) {
        return [];
    }

    $siteHosts = [];
    $siteBaseUrl = env_value('SITE_BASE_URL');
    if (is_string($siteBaseUrl) && $siteBaseUrl !== '') {
        $host = parse_url($siteBaseUrl, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $siteHosts[strtolower($host)] = true;
        }
    }
    if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '') {
        $siteHosts[strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) ?? $_SERVER['HTTP_HOST'])] = true;
    }

    $targets = [];
    $urlPatterns = [
        '/\bhref\s*=\s*["\']([^"\']+)["\']/i',
        '/\[[^\]]+\]\(([^)\s]+)(?:\s+["\'][^"\']*["\'])?\)/',
    ];

    foreach ($urlPatterns as $pattern) {
        if (preg_match_all($pattern, $content, $matches) === false) {
            continue;
        }

        foreach ($matches[1] ?? [] as $rawUrl) {
            $url = html_entity_decode((string) $rawUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && $host !== '' && !isset($siteHosts[strtolower($host)])) {
                continue;
            }
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (preg_match('#^/(?:blogs?|blog)/([A-Za-z0-9-]+)/?$#i', $path, $slugMatch) !== 1) {
                continue;
            }

            $slug = normalize_slug(rawurldecode($slugMatch[1]));
            if ($slug !== '' && isset($knownSlugs[$slug])) {
                $targets[$slug] = true;
            }
        }
    }
    if (preg_match_all('#(?<![A-Za-z0-9_-])/(?:blogs?|blog)/([A-Za-z0-9-]+)(?:/|\b)#i', $content, $matches) !== false) {
        foreach ($matches[1] ?? [] as $rawSlug) {
            $slug = normalize_slug(rawurldecode((string) $rawSlug));
            if ($slug !== '' && isset($knownSlugs[$slug])) {
                $targets[$slug] = true;
            }
        }
    }
    if (preg_match_all('#https?://([^/\s)]+)/+(?:blogs?|blog)/([A-Za-z0-9-]+)(?:/|\b)#i', $content, $matches, PREG_SET_ORDER) !== false) {
        foreach ($matches as $match) {
            $host = strtolower((string) ($match[1] ?? ''));
            $host = preg_replace('/:\d+$/', '', $host) ?? $host;
            if (!isset($siteHosts[$host])) {
                continue;
            }
            $slug = normalize_slug(rawurldecode((string) ($match[2] ?? '')));
            if ($slug !== '' && isset($knownSlugs[$slug])) {
                $targets[$slug] = true;
            }
        }
    }

    return array_keys($targets);
}

function blog_count_internal_links(string $content): int
{
    if ($content === '') {
        return 0;
    }

    $siteHosts = [];
    $siteBaseUrl = env_value('SITE_BASE_URL');
    if (is_string($siteBaseUrl) && $siteBaseUrl !== '') {
        $host = parse_url($siteBaseUrl, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $siteHosts[strtolower($host)] = true;
        }
    }
    if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '') {
        $requestHost = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) ?? $_SERVER['HTTP_HOST'];
        $siteHosts[strtolower($requestHost)] = true;
    }

    $urls = [];
    $patterns = [
        '/\bhref\s*=\s*["\']([^"\']*)["\']/i',
        '/\[[^\]]+\]\(([^)\s]+)(?:\s+["\'][^"\']*["\'])?\)/',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $content, $matches) !== false) {
            foreach ($matches[1] ?? [] as $url) {
                $urls[] = html_entity_decode(trim((string) $url), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
    }
    if (preg_match_all('/:::button\s*\n?([\s\S]*?)\n?:::/i', $content, $buttonBlocks) !== false) {
        foreach ($buttonBlocks[1] ?? [] as $buttonBlock) {
            if (preg_match('/^\s*url\s*:\s*(.*?)\s*$/im', (string) $buttonBlock, $urlMatch) === 1) {
                $urls[] = html_entity_decode(trim((string) $urlMatch[1]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
    }

    $count = 0;
    foreach ($urls as $url) {
        if ($url === '' || $url === '#' || preg_match('/^javascript:/i', $url) === 1) {
            continue;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (is_string($host) && $host !== '' && !isset($siteHosts[strtolower($host)])) {
            continue;
        }
        if (preg_match('/^(?:https?:)?\/\//i', $url) === 1 && (!is_string($host) || $host === '')) {
            continue;
        }
        $count++;
    }

    return $count;
}

function blog_add_internal_link_metrics(PDO $pdo, array $items): array
{
    if ($items === []) {
        return $items;
    }

    $posts = $pdo->query('SELECT id, slug, title, content FROM blog_posts')->fetchAll(PDO::FETCH_ASSOC);
    $knownSlugs = [];
    $titlesBySlug = [];
    foreach ($posts as $post) {
        $slug = normalize_slug((string) ($post['slug'] ?? ''));
        if ($slug === '') {
            continue;
        }
        $knownSlugs[$slug] = true;
        $titlesBySlug[$slug] = (string) ($post['title'] ?? $slug);
    }

    $outboundBySlug = [];
    $inboundBySlug = [];
    foreach ($posts as $post) {
        $sourceSlug = normalize_slug((string) ($post['slug'] ?? ''));
        if ($sourceSlug === '') {
            continue;
        }

        $targets = array_values(array_filter(
            blog_extract_internal_link_slugs((string) ($post['content'] ?? ''), $knownSlugs),
            static fn (string $targetSlug): bool => $targetSlug !== $sourceSlug
        ));
        $outboundBySlug[$sourceSlug] = $targets;
        foreach ($targets as $targetSlug) {
            $inboundBySlug[$targetSlug][$sourceSlug] = true;
        }
    }

    foreach ($items as &$item) {
        $slug = normalize_slug((string) ($item['slug'] ?? ''));
        $outboundSlugs = $outboundBySlug[$slug] ?? [];
        $inboundSlugs = array_keys($inboundBySlug[$slug] ?? []);
        $item['internalLinks'] = blog_count_internal_links((string) ($item['content'] ?? ''));
        $item['linkedFrom'] = count($inboundSlugs);
        $item['internalLinkTitles'] = array_values(array_map(static fn (string $targetSlug): string => $titlesBySlug[$targetSlug] ?? $targetSlug, $outboundSlugs));
        $item['linkedFromTitles'] = array_values(array_map(static fn (string $sourceSlug): string => $titlesBySlug[$sourceSlug] ?? $sourceSlug, $inboundSlugs));
    }
    unset($item);

    return $items;
}

function blogs_page(int $count, int $page, ?string $category = null, ?string $status = null, ?string $search = null): array
{
    $count = max(1, min(100, $count));
    $page = max(1, $page);
    $offset = ($page - 1) * $count;
    $pdo = blogs_pdo();
    blogs_publish_due_scheduled($pdo);
    $category = normalize_blog_category_filter($category);
    $status = $status === null ? null : normalize_blog_status($status);
    $search = is_string($search) ? trim($search) : '';

    $where = [];
    $params = [];
    if ($category !== null) {
        $where[] = 'category = :category';
        $params[':category'] = $category;
    }
    if ($status !== null) {
        if ($status === 'published') {
            $where[] = blog_public_visibility_sql();
            $params[':visibility_now'] = time();
        } else {
            $where[] = 'status = :status';
            $params[':status'] = $status;
        }
    }
    if ($search !== '') {
        $where[] = '(title LIKE :search ESCAPE \'\\\' OR slug LIKE :search ESCAPE \'\\\' OR category LIKE :search ESCAPE \'\\\' OR author LIKE :search ESCAPE \'\\\' OR excerpt LIKE :search ESCAPE \'\\\' OR content LIKE :search ESCAPE \'\\\' OR focus_keyphrase LIKE :search ESCAPE \'\\\' OR status LIKE :search ESCAPE \'\\\')';
        $params[':search'] = blog_like_term($search);
    }
    $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

    $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM blog_posts' . $whereSql);
    $totalStmt->execute($params);
    $total = (int) $totalStmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT p.*,
            SUM(CASE WHEN e.action = "view" THEN 1 ELSE 0 END) AS views,
            SUM(CASE WHEN e.action = "like" THEN 1 ELSE 0 END) AS likes,
            SUM(CASE WHEN e.action = "dislike" THEN 1 ELSE 0 END) AS dislikes
         FROM blog_posts p
         LEFT JOIN blog_engagements e ON e.post_id = p.id' .
         $whereSql .
        ' GROUP BY p.id
          ORDER BY  p.created_at DESC
          LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, $key === ':visibility_now' ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $count, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $items = blog_add_internal_link_metrics($pdo, array_map('blog_row_to_array', $stmt->fetchAll()));

    return [
        'items' => $items,
        'total' => $total,
        'count' => $count,
        'page' => $page,
        'category' => $category,
        'search' => $search,
        'totalPages' => max(1, (int) ceil($total / $count)),
    ];
}

function blogs_all(): array
{
    return blogs_page(100, 1)['items'];
}

function blogs_public_all(): array
{
    return blogs_page(100, 1, null, 'published')['items'];
}

function blogs_have_future_scheduled_posts(?int $now = null): bool
{
    $stmt = blogs_pdo()->prepare(
        'SELECT COUNT(*) FROM blog_posts
         WHERE status = "scheduled"
           AND scheduled_at IS NOT NULL
           AND scheduled_at > :now'
    );
    $stmt->execute([':now' => $now ?? time()]);

    return (int) $stmt->fetchColumn() > 0;
}

function blogs_find(string $id): ?array
{
    $id = normalize_slug($id);
    $pdo = blogs_pdo();
    blogs_publish_due_scheduled($pdo);
    $stmt = $pdo->prepare(
        'SELECT p.*,
            SUM(CASE WHEN e.action = "view" THEN 1 ELSE 0 END) AS views,
            SUM(CASE WHEN e.action = "like" THEN 1 ELSE 0 END) AS likes,
            SUM(CASE WHEN e.action = "dislike" THEN 1 ELSE 0 END) AS dislikes
         FROM blog_posts p
         LEFT JOIN blog_engagements e ON e.post_id = p.id
         WHERE p.id = :id OR p.slug = :id
         GROUP BY p.id
         LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return is_array($row) ? blog_row_to_array($row) : null;
}

function blog_ensure_post_route(string $slug): void
{
    unset($slug);
}

function blog_remove_post_route(string $slug): void
{
    unset($slug);
}

function blogs_upsert_with_pdo(PDO $pdo, array $blog): array
{
    $stmt = $pdo->prepare(
        'INSERT INTO blog_posts (
            id, slug, title, seo_title, category, author, excerpt, content, featured_image, status, focus_keyphrase, date_label, published_at, scheduled_at, created_at, updated_at
        ) VALUES (
            :id, :slug, :title, :seo_title, :category, :author, :excerpt, :content, :featured_image, :status, :focus_keyphrase, :date_label, :published_at, :scheduled_at, :created_at, :updated_at
        )
        ON CONFLICT(id) DO UPDATE SET
            slug = excluded.slug,
            title = excluded.title,
            seo_title = excluded.seo_title,
            category = excluded.category,
            author = excluded.author,
            excerpt = excluded.excerpt,
            content = excluded.content,
            featured_image = excluded.featured_image,
            status = excluded.status,
            focus_keyphrase = excluded.focus_keyphrase,
            date_label = excluded.date_label,
            published_at = excluded.published_at,
            scheduled_at = excluded.scheduled_at,
            updated_at = excluded.updated_at'
    );
    $stmt->execute([
        ':id' => $blog['id'],
        ':slug' => $blog['slug'],
        ':title' => $blog['title'],
        ':seo_title' => normalize_seo_title($blog['seoTitle'] ?? ($blog['seo_title'] ?? $blog['title'])),
        ':category' => $blog['category'],
        ':author' => $blog['author'],
        ':excerpt' => $blog['excerpt'],
        ':content' => $blog['content'],
        ':featured_image' => $blog['featuredImage'],
        ':status' => normalize_blog_status($blog['status'] ?? null),
        ':focus_keyphrase' => normalize_focus_keyphrase($blog['focusKeyphrase'] ?? ($blog['focus_keyphrase'] ?? '')),
        ':date_label' => $blog['date'],
        ':published_at' => $blog['publishedAt'] ?? null,
        ':scheduled_at' => $blog['scheduledAt'] ?? null,
        ':created_at' => $blog['createdAt'],
        ':updated_at' => $blog['updatedAt'],
    ]);

    return $blog;
}

function blog_duplicate_validation_errors(array $blog): array
{
    $slug = isset($blog['slug']) && is_string($blog['slug']) ? normalize_slug($blog['slug']) : '';
    if ($slug === '') {
        return [];
    }

    $existingId = '';
    if (isset($_POST['id']) && !is_array($_POST['id'])) {
        $existingId = normalize_slug((string) $_POST['id']);
    }

    $stmt = blogs_pdo()->prepare(
        'SELECT id FROM blog_posts
         WHERE slug = :slug
           AND (:existing_id = "" OR id <> :existing_id)
         LIMIT 1'
    );
    $stmt->execute([
        ':slug' => $slug,
        ':existing_id' => $existingId,
    ]);

    return $stmt->fetchColumn() ? ['A blog post with this slug already exists.'] : [];
}

function blog_legacy_engagement_count(array $blog, string $key): int
{
    $value = $blog[$key] ?? null;
    if (is_int($value)) {
        return max(0, $value);
    }
    if (is_float($value)) {
        return max(0, (int) $value);
    }
    if (is_string($value)) {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        return $digits === '' ? 0 : max(0, (int) $digits);
    }

    return 0;
}

function blog_seed_legacy_engagements(PDO $pdo, string $postId, array $blog): void
{
    $counts = [
        'view' => blog_legacy_engagement_count($blog, 'views'),
        'like' => blog_legacy_engagement_count($blog, 'likes'),
        'dislike' => blog_legacy_engagement_count($blog, 'dislikes'),
    ];
    if (max($counts) <= 0) {
        return;
    }

    $now = time();
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO blog_engagements (post_id, action, ip_hash, created_at, updated_at)
         VALUES (:post_id, :action, :ip_hash, :created_at, :updated_at)'
    );
    foreach ($counts as $action => $total) {
        for ($index = 0; $index < $total; $index++) {
            $stmt->execute([
                ':post_id' => $postId,
                ':action' => $action,
                ':ip_hash' => hash('sha256', 'legacy:' . $postId . ':' . $action . ':' . $index),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }
    }
}

function blogs_upsert(array $blog, bool $manageTransaction = true): array
{
    $pdo = blogs_pdo();
    $now = time();
    $existing = blogs_find($blog['id']);
    $oldSlug = $existing['slug'] ?? null;

    $blog['id'] = $existing['id'] ?? $blog['slug'];
    $blog['status'] = normalize_blog_status($blog['status'] ?? ($existing['status'] ?? null));
    $publishedAtEdited = array_key_exists('publishedAt', $blog) || array_key_exists('published_at', $blog);
    $scheduledAtEdited = array_key_exists('scheduledAt', $blog) || array_key_exists('scheduled_at', $blog);
    $blog['publishedAt'] = $publishedAtEdited
        ? ($blog['publishedAt'] ?? ($blog['published_at'] ?? null))
        : ($existing['publishedAt'] ?? null);
    $blog['scheduledAt'] = $scheduledAtEdited
        ? ($blog['scheduledAt'] ?? ($blog['scheduled_at'] ?? null))
        : ($existing['scheduledAt'] ?? null);
    if ($blog['status'] === 'published') {
        $blog['scheduledAt'] = null;
        $blog['publishedAt'] = is_int($blog['publishedAt']) ? $blog['publishedAt'] : ($existing['publishedAt'] ?? $now);
    } elseif ($blog['status'] === 'scheduled') {
        $blog['scheduledAt'] = is_int($blog['scheduledAt']) ? $blog['scheduledAt'] : ($blog['publishedAt'] ?? $now);
        $blog['publishedAt'] = $blog['scheduledAt'];
    }
    $blog['date'] = blog_format_date_label($blog['publishedAt'] ?? $blog['scheduledAt'] ?? ($existing['publishedAt'] ?? $existing['createdAt'] ?? $now));
    $blog['seoTitle'] = normalize_seo_title($blog['seoTitle'] ?? ($blog['seo_title'] ?? ($existing['seoTitle'] ?? $blog['title'])));
    $blog['focusKeyphrase'] = array_key_exists('focusKeyphrase', $blog) || array_key_exists('focus_keyphrase', $blog)
        ? normalize_focus_keyphrase($blog['focusKeyphrase'] ?? ($blog['focus_keyphrase'] ?? ''))
        : normalize_focus_keyphrase($existing['focusKeyphrase'] ?? '');
    $blog['createdAt'] = $existing['createdAt'] ?? $now;
    $blog['updatedAt'] = $now;

    if ($manageTransaction) {
        $pdo->beginTransaction();
    }

    try {
        blogs_upsert_with_pdo($pdo, $blog);

        if ($manageTransaction) {
            $pdo->commit();
        }
        blogs_publish_due_scheduled($pdo, $now);
    } catch (Throwable $exception) {
        if ($manageTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return $blog;
}

function blogs_delete(string $id): bool
{
    $post = blogs_find($id);
    if ($post !== null) {
        $engagementStmt = blogs_pdo()->prepare('DELETE FROM blog_engagements WHERE post_id = :post_id');
        $engagementStmt->execute([':post_id' => $post['id']]);
    }
    $stmt = blogs_pdo()->prepare('DELETE FROM blog_posts WHERE id = :id OR slug = :id');
    $stmt->execute([':id' => normalize_slug($id)]);
    $deleted = $stmt->rowCount() > 0;

    return $deleted;
}

function blogs_sync_routes(): int
{
    return 0;
}

function normalize_slug(string $value): string
{
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
    $slug = trim(preg_replace('/-+/', '-', $slug) ?? '', '-');
    return substr($slug, 0, 96);
}

function normalize_featured_image(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return BLOG_DEFAULT_IMAGE;
    }

    if (str_starts_with($value, '/uploads/blogs/') && !str_contains($value, '..') && strlen($value) <= 180) {
        return $value;
    }

    return BLOG_DEFAULT_IMAGE;
}

function request_positive_int(string $key, int $default, int $min, int $max): int
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) {
        return $default;
    }

    $value = trim((string) $_POST[$key]);
    if (!ctype_digit($value)) {
        return $default;
    }

    return max($min, min($max, (int) $value));
}

function blog_payload_from_post(): array
{
    $title = request_string('title', 160);
    $seoTitle = request_string('seo_title', 160);
    $slug = request_string('slug', 110);
    $category = request_string('category', 50);
    $author = request_string('author', 80) ?? BLOG_DEFAULT_AUTHOR;
    $excerpt = request_string('excerpt', 360);
    $content = request_string('content', 60000);
    $featuredImage = request_string('featured_image', 180) ?? BLOG_DEFAULT_IMAGE;
    $status = normalize_blog_status($_POST['status'] ?? null);
    $focusKeyphrase = request_string('focus_keyphrase', 160) ?? '';
    $existingId = request_string('id', 110);
    $publishedAt = blog_parse_admin_datetime($_POST['published_at'] ?? null);
    $scheduledAt = blog_parse_admin_datetime($_POST['scheduled_at'] ?? null);

    $slug = $slug !== null ? normalize_slug($slug) : '';
    if ($slug === '' && $title !== null) {
        $slug = normalize_slug($title);
    }

    $errors = [];
    if ($title === null) {
        $errors[] = 'Title is required.';
    }
    if ($seoTitle === null) {
        $errors[] = 'SEO title is required.';
    }
    if ($slug === '' || strlen($slug) > 96) {
        $errors[] = 'Slug is invalid.';
    }
    if ($category === null || !in_array($category, blog_category_names(), true)) {
        $errors[] = 'Category is invalid.';
    }
    if ($excerpt === null) {
        $errors[] = 'Excerpt is required.';
    }
    if ($content === null) {
        $errors[] = 'Content is required.';
    }
    $postedPublishedAt = isset($_POST['published_at']) && !is_array($_POST['published_at']) ? trim((string) $_POST['published_at']) : '';
    if ($status === 'published' && $postedPublishedAt !== '' && $publishedAt === null) {
        $errors[] = 'Publish date is invalid.';
    }
    if ($status === 'scheduled' && $scheduledAt === null) {
        $errors[] = 'Scheduled publish date is required.';
    }

    if ($errors !== []) {
        return ['ok' => false, 'errors' => $errors];
    }

    return [
        'ok' => true,
        'blog' => [
            'id' => $existingId !== null ? normalize_slug($existingId) : $slug,
            'slug' => $slug,
            'title' => $title,
            'seoTitle' => normalize_seo_title($seoTitle),
            'category' => $category,
            'author' => $author,
            'excerpt' => $excerpt,
            'content' => $content,
            'featuredImage' => normalize_featured_image($featuredImage),
            'status' => $status,
            'focusKeyphrase' => normalize_focus_keyphrase($focusKeyphrase),
            'publishedAt' => $publishedAt,
            'scheduledAt' => $scheduledAt,
        ],
    ];
}
