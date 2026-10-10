<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/env.php';
require_once __DIR__ . '/../../../includes/blog-storage.php';
require_once __DIR__ . '/../../../includes/image-validation.php';

function sports_registry_db_path(): string
{
    $dir = blog_storage_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Storage directory unavailable.');
    }
    return $dir . '/sports-registry.sqlite';
}

function sports_registry_pdo(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = sports_registry_db_path();
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    @chmod($path, 0600);
    $pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec('PRAGMA foreign_keys=ON');

    // 1. Primary Sports Categories table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sports_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            image_key TEXT NOT NULL UNIQUE,
            image_path TEXT NOT NULL DEFAULT '',
            enabled INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_sports_cat_slug ON sports_categories(slug);
        CREATE INDEX IF NOT EXISTS idx_sports_cat_image_key ON sports_categories(image_key);

        CREATE TABLE IF NOT EXISTS sport_settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT '',
            updated_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS tournaments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sports_category_id INTEGER NOT NULL REFERENCES sports_categories(id) ON DELETE CASCADE,
            odds_sport_key TEXT NOT NULL UNIQUE,
            title TEXT NOT NULL,
            source_title TEXT NOT NULL DEFAULT '',
            custom_title TEXT NOT NULL DEFAULT '',
            description TEXT NOT NULL DEFAULT '',
            active INTEGER NOT NULL DEFAULT 1,
            has_outrights INTEGER NOT NULL DEFAULT 0,
            enabled INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            last_sync_at INTEGER NOT NULL DEFAULT 0,
            last_sync_status TEXT NOT NULL DEFAULT '',
            last_sync_message TEXT NOT NULL DEFAULT '',
            enrich_api_url TEXT NOT NULL DEFAULT '',
            api_config TEXT NOT NULL DEFAULT '{}',
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_tournaments_cat ON tournaments(sports_category_id);
        CREATE INDEX IF NOT EXISTS idx_tournaments_odds_sport_key ON tournaments(odds_sport_key);

        CREATE TABLE IF NOT EXISTS participants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            odds_sport_key TEXT NOT NULL DEFAULT '',
            sports_category_id INTEGER NOT NULL REFERENCES sports_categories(id) ON DELETE CASCADE,
            participant_id TEXT NOT NULL DEFAULT '',
            name TEXT NOT NULL,
            slug TEXT NOT NULL,
            image_key TEXT NOT NULL,
            image_path TEXT NOT NULL DEFAULT '',
            image_source TEXT NOT NULL DEFAULT 'odds_api',
            image_failure INTEGER NOT NULL DEFAULT 0,
            enabled INTEGER NOT NULL DEFAULT 1,
            api_data TEXT NOT NULL DEFAULT '{}',
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            UNIQUE(sports_category_id, participant_id, name)
        );
        CREATE INDEX IF NOT EXISTS idx_participants_cat ON participants(sports_category_id);
        CREATE INDEX IF NOT EXISTS idx_participants_odds_sport_key ON participants(odds_sport_key);
        CREATE INDEX IF NOT EXISTS idx_participants_participant_id ON participants(participant_id);
        CREATE INDEX IF NOT EXISTS idx_participants_slug ON participants(slug);
        CREATE INDEX IF NOT EXISTS idx_participants_name ON participants(name);
        CREATE INDEX IF NOT EXISTS idx_participants_image_key ON participants(image_key);

        CREATE TABLE IF NOT EXISTS participant_tournaments (
            participant_id INTEGER NOT NULL REFERENCES participants(id) ON DELETE CASCADE,
            tournament_id INTEGER NOT NULL REFERENCES tournaments(id) ON DELETE CASCADE,
            created_at INTEGER NOT NULL,
            PRIMARY KEY (participant_id, tournament_id)
        );

        -- Legacy tables preserved/mirrored for backward compatibility
        CREATE TABLE IF NOT EXISTS sports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            image_key TEXT NOT NULL UNIQUE,
            image_path TEXT NOT NULL DEFAULT '',
            api_sport_id TEXT NOT NULL DEFAULT '',
            api_base_url TEXT NOT NULL DEFAULT '',
            enabled INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_sports_slug ON sports(slug);
        CREATE INDEX IF NOT EXISTS idx_sports_image_key ON sports(image_key);

        CREATE TABLE IF NOT EXISTS competitions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sport_id INTEGER NOT NULL REFERENCES sports(id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            api_league_id TEXT NOT NULL,
            season TEXT NOT NULL DEFAULT '',
            country TEXT NOT NULL DEFAULT '',
            enabled INTEGER NOT NULL DEFAULT 1,
            api_config TEXT NOT NULL DEFAULT '{}',
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS teams (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sport_id INTEGER NOT NULL REFERENCES sports(id) ON DELETE CASCADE,
            api_team_id TEXT NOT NULL,
            name TEXT NOT NULL,
            slug TEXT NOT NULL,
            code TEXT NOT NULL DEFAULT '',
            country TEXT NOT NULL DEFAULT '',
            image_key TEXT NOT NULL,
            image_path TEXT NOT NULL DEFAULT '',
            source_image_url TEXT NOT NULL DEFAULT '',
            image_source TEXT NOT NULL DEFAULT 'api',
            image_failure INTEGER NOT NULL DEFAULT 0,
            enabled INTEGER NOT NULL DEFAULT 1,
            api_data TEXT NOT NULL DEFAULT '{}',
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            UNIQUE(sport_id, api_team_id)
        );

        CREATE TABLE IF NOT EXISTS team_competitions (
            team_id INTEGER NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
            competition_id INTEGER NOT NULL REFERENCES competitions(id) ON DELETE CASCADE,
            PRIMARY KEY (team_id, competition_id)
        );
    ");

    // Dynamic schema migration for existing tournaments table
    try {
        $cols = $pdo->query('PRAGMA table_info(tournaments)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('source_title', $cols, true)) {
            $pdo->exec("ALTER TABLE tournaments ADD COLUMN source_title TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('custom_title', $cols, true)) {
            $pdo->exec("ALTER TABLE tournaments ADD COLUMN custom_title TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('sort_order', $cols, true)) {
            $pdo->exec("ALTER TABLE tournaments ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0");
        }
        if (!in_array('last_sync_at', $cols, true)) {
            $pdo->exec("ALTER TABLE tournaments ADD COLUMN last_sync_at INTEGER NOT NULL DEFAULT 0");
        }
        if (!in_array('last_sync_status', $cols, true)) {
            $pdo->exec("ALTER TABLE tournaments ADD COLUMN last_sync_status TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('last_sync_message', $cols, true)) {
            $pdo->exec("ALTER TABLE tournaments ADD COLUMN last_sync_message TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('enrich_api_url', $cols, true)) {
            $pdo->exec("ALTER TABLE tournaments ADD COLUMN enrich_api_url TEXT NOT NULL DEFAULT ''");
        }
    } catch (Throwable) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS sports_image_assets (
        entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL, name TEXT NOT NULL,
        status TEXT NOT NULL, image_path TEXT NOT NULL DEFAULT '', source TEXT NOT NULL DEFAULT '',
        metadata TEXT NOT NULL DEFAULT '{}', message TEXT NOT NULL DEFAULT '', checked_at INTEGER NOT NULL,
        PRIMARY KEY(entity_type, entity_id)
    )");
    return $pdo;
}

// --------------------------------------------------------------------------
// Utility & Formatting Helpers
// --------------------------------------------------------------------------

function sports_registry_slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
    return trim($text, '-');
}

function sports_registry_sport_image_key(string $slug): string
{
    return strtolower(trim($slug)) . '_sports';
}

function sports_registry_participant_image_key(string $participantSlug, string $categorySlug): string
{
    return strtolower(trim($participantSlug)) . '_' . strtolower(trim($categorySlug));
}

function sports_registry_team_image_key(string $teamSlug, string $sportSlug): string
{
    return sports_registry_participant_image_key($teamSlug, $sportSlug);
}

function sports_registry_upload_dirs(string $subdir): array
{
    $base = dirname(__DIR__, 4);
    $webDir = $base . '/uploads/' . $subdir;
    $adminDir = dirname(__DIR__, 3) . '/uploads/' . $subdir;

    if (!is_dir($webDir)) @mkdir($webDir, 0755, true);
    if (!is_dir($adminDir)) @mkdir($adminDir, 0755, true);

    return ['web' => $webDir, 'admin' => $adminDir];
}

function sports_registry_delete_file(string $publicPath): void
{
    $publicPath = '/' . ltrim($publicPath, '/');
    $base = dirname(__DIR__, 4);
    $adminBase = dirname(__DIR__, 3);

    @unlink($base . $publicPath);
    @unlink($adminBase . $publicPath);
}

function sports_registry_validate_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('File upload failed.');
    }
    if (!isset($file['tmp_name']) || !is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        if (!is_file($file['tmp_name'])) {
            throw new InvalidArgumentException('Invalid upload file.');
        }
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        throw new InvalidArgumentException('Image file is too large (max 10MB).');
    }

    $mime = detect_blog_image_mime($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    if (!is_string($mime) || !isset($allowed[$mime])) {
        throw new InvalidArgumentException('Unsupported image type. Use JPEG, PNG, WebP, or AVIF.');
    }

    $dimensions = @getimagesize($file['tmp_name']);
    if ($dimensions === false || empty($dimensions[0]) || empty($dimensions[1])) {
        throw new InvalidArgumentException('Invalid image dimensions.');
    }

    return [
        'mime' => $mime,
        'ext' => $allowed[$mime],
        'width' => (int) $dimensions[0],
        'height' => (int) $dimensions[1],
    ];
}

// --------------------------------------------------------------------------
// API Key Settings
// --------------------------------------------------------------------------

function sports_odds_api_key_get(): string
{
    $pdo = sports_registry_pdo();
    $stmt = $pdo->prepare('SELECT value FROM sport_settings WHERE key=?');
    $stmt->execute(['odds_api_key']);
    $val = (string) ($stmt->fetchColumn() ?: '');
    if ($val !== '') {
        return $val;
    }
    if (function_exists('odds_api_config')) {
        try {
            $conf = odds_api_config();
            if (!empty($conf['api_key'])) return (string) $conf['api_key'];
        } catch (Throwable) {}
    }
    if (function_exists('odds_api_pdo')) {
        try {
            $key = odds_api_pdo()->query('SELECT api_key FROM settings WHERE id=1')->fetchColumn();
            if ($key) return (string) $key;
        } catch (Throwable) {}
    }
    return '';
}

function sports_odds_api_key_set(string $key): void
{
    $pdo = sports_registry_pdo();
    $stmt = $pdo->prepare('INSERT INTO sport_settings(key, value, updated_at) VALUES(?, ?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at');
    $stmt->execute(['odds_api_key', trim($key), time()]);
}

function sports_api_sports_key_get(): string
{
    $pdo = sports_registry_pdo();
    $stmt = $pdo->prepare('SELECT value FROM sport_settings WHERE key=?');
    $stmt->execute(['api_sports_key']);
    return (string) ($stmt->fetchColumn() ?: '');
}

function sports_api_sports_key_set(string $key): void
{
    $pdo = sports_registry_pdo();
    $stmt = $pdo->prepare('INSERT INTO sport_settings(key, value, updated_at) VALUES(?, ?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at');
    $stmt->execute(['api_sports_key', trim($key), time()]);
}

// --------------------------------------------------------------------------
// The Odds API Shared Request Helper & Safe Error Logger
// --------------------------------------------------------------------------

function sports_odds_api_build_url(string $pathTemplate, array $params = []): string
{
    $apiKey = $params['apiKey'] ?? ($params['api_key'] ?? sports_odds_api_key_get());
    $sport = trim((string)($params['sport'] ?? ($params['odds_sport_key'] ?? '')));

    // Replace {sport} placeholder without double-encoding
    $path = str_replace('{sport}', rawurlencode($sport), $pathTemplate);
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }

    $baseUrl = 'https://api.the-odds-api.com' . $path;
    $queryParams = [];
    if ($apiKey !== '') {
        $queryParams['apiKey'] = $apiKey;
    }

    foreach ($params as $k => $v) {
        if (in_array($k, ['apiKey', 'api_key', 'sport', 'odds_sport_key'], true)) continue;
        if ($v !== null && $v !== '') {
            $queryParams[$k] = $v;
        }
    }

    $query = http_build_query($queryParams);
    return $query !== '' ? ($baseUrl . '?' . $query) : $baseUrl;
}

function sports_registry_log_sync_error(array $context): void
{
    $clean = [
        'time' => gmdate('Y-m-d H:i:s \U\T\C'),
        'tournament_id' => $context['tournament_id'] ?? null,
        'tournament_title' => $context['tournament_title'] ?? null,
        'odds_sport_key' => $context['odds_sport_key'] ?? null,
        'http_status' => $context['http_status'] ?? null,
        'error' => $context['error'] ?? null,
        'response_error' => $context['response_error'] ?? null,
        'json_error' => $context['json_error'] ?? null,
        'db_error' => $context['db_error'] ?? null,
    ];
    $clean = array_filter($clean, static fn($v) => $v !== null && $v !== '');

    error_log('[The Odds API Sync Error] ' . json_encode($clean, JSON_UNESCAPED_SLASHES));

    if (!empty($clean['tournament_id'])) {
        try {
            $pdo = sports_registry_pdo();
            $errSummary = $clean['error'] ?? ($clean['response_error'] ?? ($clean['json_error'] ?? 'Sync failed'));
            $pdo->prepare('UPDATE tournaments SET last_sync_at = ?, last_sync_status = "Failed", last_sync_message = ? WHERE id = ?')
                ->execute([time(), substr((string)$errSummary, 0, 255), (int)$clean['tournament_id']]);
        } catch (Throwable) {}
    }
}

// --------------------------------------------------------------------------
// Sports Categories (Primary)
// --------------------------------------------------------------------------

function sports_category_get_all(bool $onlyEnabled = false): array
{
    $pdo = sports_registry_pdo();
    $sql = 'SELECT sc.*,
            (SELECT COUNT(*) FROM tournaments t WHERE t.sports_category_id = sc.id) AS tournaments_count,
            (SELECT COUNT(*) FROM participants p WHERE p.sports_category_id = sc.id) AS participants_count
            FROM sports_categories sc ' .
            ($onlyEnabled ? 'WHERE sc.enabled = 1 ' : '') .
            'ORDER BY sc.name ASC';
    return $pdo->query($sql)->fetchAll();
}

function sports_category_get(int|string $idOrSlug): ?array
{
    $pdo = sports_registry_pdo();
    if (is_numeric($idOrSlug) && (int) $idOrSlug > 0) {
        $stmt = $pdo->prepare('SELECT * FROM sports_categories WHERE id = ?');
        $stmt->execute([(int) $idOrSlug]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM sports_categories WHERE slug = ?');
        $stmt->execute([(string) $idOrSlug]);
    }
    $row = $stmt->fetch();
    return $row ?: null;
}

function sports_category_save(array $data): array
{
    $pdo = sports_registry_pdo();
    $id = isset($data['id']) && is_numeric($data['id']) ? (int) $data['id'] : 0;
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Category name is required.');
    }

    $slug = trim((string) ($data['slug'] ?? ''));
    if ($slug === '') {
        $slug = sports_registry_slugify($name);
    } else {
        $slug = sports_registry_slugify($slug);
    }
    if ($slug === '') {
        throw new InvalidArgumentException('Category slug is required.');
    }

    $imageKey = sports_registry_sport_image_key($slug);
    $enabled = !empty($data['enabled']) ? 1 : 0;
    $now = time();

    // Check slug uniqueness
    $stmt = $pdo->prepare('SELECT id FROM sports_categories WHERE slug = ? AND id != ?');
    $stmt->execute([$slug, $id]);
    if ($stmt->fetchColumn()) {
        throw new InvalidArgumentException("Category slug '{$slug}' is already registered.");
    }

    if ($id > 0) {
        if (isset($data['image_path'])) {
            $stmt = $pdo->prepare('UPDATE sports_categories SET name = ?, slug = ?, image_key = ?, image_path = ?, enabled = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([$name, $slug, $imageKey, (string) $data['image_path'], $enabled, $now, $id]);
        } else {
            $stmt = $pdo->prepare('UPDATE sports_categories SET name = ?, slug = ?, image_key = ?, enabled = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([$name, $slug, $imageKey, $enabled, $now, $id]);
        }
    } else {
        $imagePath = (string) ($data['image_path'] ?? '');
        $stmt = $pdo->prepare('INSERT INTO sports_categories (name, slug, image_key, image_path, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$name, $slug, $imageKey, $imagePath, $enabled, $now, $now]);
        $id = (int) $pdo->lastInsertId();
    }

    // Mirror to legacy sports table
    try {
        $legacy = $pdo->prepare('SELECT id FROM sports WHERE slug = ? OR id = ?');
        $legacy->execute([$slug, $id]);
        $legId = $legacy->fetchColumn();
        if ($legId) {
            $pdo->prepare('UPDATE sports SET name = ?, slug = ?, image_key = ?, enabled = ?, updated_at = ? WHERE id = ?')
                ->execute([$name, $slug, $imageKey, $enabled, $now, $legId]);
        } else {
            $pdo->prepare('INSERT OR IGNORE INTO sports (id, name, slug, image_key, image_path, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $name, $slug, $imageKey, (string)($data['image_path'] ?? ''), $enabled, $now, $now]);
        }
    } catch (Throwable) {}

    return sports_category_get($id) ?? [];
}

function sports_category_update_image(int $categoryId, string $imagePath): void
{
    $pdo = sports_registry_pdo();
    $now = time();
    $stmt = $pdo->prepare('UPDATE sports_categories SET image_path = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$imagePath, $now, $categoryId]);

    try {
        $pdo->prepare('UPDATE sports SET image_path = ?, updated_at = ? WHERE id = ?')->execute([$imagePath, $now, $categoryId]);
    } catch (Throwable) {}
}

function sports_category_save_image(int $categoryId, array $file): array
{
    $cat = sports_category_get($categoryId);
    if (!$cat) {
        throw new InvalidArgumentException('Category not found.');
    }

    $validation = sports_registry_validate_upload($file);
    $dirs = sports_registry_upload_dirs('sports');
    $filename = $cat['image_key'] . '.' . $validation['ext'];
    $webDest = $dirs['web'] . '/' . $filename;
    $adminDest = $dirs['admin'] . '/' . $filename;

    $saved = optimize_uploaded_image($file['tmp_name'], $webDest, $validation['mime']);
    if (!$saved) {
        $saved = optimize_uploaded_image($file['tmp_name'], $adminDest, $validation['mime']);
        if (!$saved) {
            throw new RuntimeException('Failed to save category image.');
        }
        @copy($adminDest, $webDest);
    } else {
        @copy($webDest, $adminDest);
    }

    @chmod($webDest, 0644);
    @chmod($adminDest, 0644);

    $publicPath = '/uploads/sports/' . $filename;
    sports_category_update_image($categoryId, $publicPath);

    $cat['image_path'] = $publicPath;
    return $cat;
}

function sports_category_remove_image(int $categoryId): bool
{
    $cat = sports_category_get($categoryId);
    if (!$cat || empty($cat['image_path'])) {
        return false;
    }

    sports_registry_delete_file($cat['image_path']);
    $pdo = sports_registry_pdo();
    $now = time();
    $stmt = $pdo->prepare('UPDATE sports_categories SET image_path = "", updated_at = ? WHERE id = ?');
    $stmt->execute([$now, $categoryId]);

    try {
        $pdo->prepare('UPDATE sports SET image_path = "", updated_at = ? WHERE id = ?')->execute([$now, $categoryId]);
    } catch (Throwable) {}

    return true;
}

function sports_category_delete(int $id): bool
{
    $pdo = sports_registry_pdo();
    $cat = sports_category_get($id);
    if (!$cat) {
        return false;
    }

    if (!empty($cat['image_path'])) {
        sports_registry_delete_file($cat['image_path']);
    }

    $stmt = $pdo->prepare('DELETE FROM sports_categories WHERE id = ?');
    $ok = $stmt->execute([$id]);

    try {
        $pdo->prepare('DELETE FROM sports WHERE id = ?')->execute([$id]);
    } catch (Throwable) {}

    return $ok;
}

// Aliases for sports_category_* (backward compatibility)
function sports_registry_get_all(bool $onlyEnabled = false): array { return sports_category_get_all($onlyEnabled); }
function sports_registry_get(int|string $idOrSlug): ?array { return sports_category_get($idOrSlug); }
function sports_registry_save(array $data): array { return sports_category_save($data); }
function sports_registry_update_image(int $sportId, string $imagePath): void { sports_category_update_image($sportId, $imagePath); }
function sports_registry_save_sport_image(int $sportId, array $file): array { return sports_category_save_image($sportId, $file); }
function sports_registry_remove_sport_image(int $sportId): bool { return sports_category_remove_image($sportId); }
function sports_registry_delete(int $id): bool { return sports_category_delete($id); }
function sports_registry_tree(array $options = []): array {
    require_once __DIR__ . '/sports-api.php';
    return sports_api_tree($options);
}

// --------------------------------------------------------------------------
// Tournaments (Odds API Sport Keys)
// --------------------------------------------------------------------------

function tournament_get_all(?int $categoryId = null, bool $onlyEnabled = false, bool $onlyEnabledCategory = true, bool $onlyActive = false): array
{
    $pdo = sports_registry_pdo();
    $where = [];
    $params = [];

    if ($categoryId !== null && $categoryId > 0) {
        $where[] = 't.sports_category_id = ?';
        $params[] = $categoryId;
    }
    if ($onlyEnabled) {
        $where[] = 't.enabled = 1';
    }
    if ($onlyEnabledCategory) {
        $where[] = 'sc.enabled = 1';
    }
    if ($onlyActive) {
        $where[] = 't.active = 1';
    }

    $sql = 'SELECT t.*, sc.name AS category_name, sc.slug AS category_slug, sc.enabled AS category_enabled,
            (SELECT COUNT(*) FROM participant_tournaments pt WHERE pt.tournament_id = t.id) AS participants_count
            FROM tournaments t
            JOIN sports_categories sc ON sc.id = t.sports_category_id ' .
            ($where ? 'WHERE ' . implode(' AND ', $where) . ' ' : '') .
            'ORDER BY t.sort_order ASC, sc.name ASC, t.title ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function tournament_get(int|string $idOrOddsKey): ?array
{
    $pdo = sports_registry_pdo();
    if (is_numeric($idOrOddsKey) && (int) $idOrOddsKey > 0) {
        $stmt = $pdo->prepare('SELECT t.*, sc.name AS category_name, sc.slug AS category_slug,
            (SELECT COUNT(*) FROM participant_tournaments pt WHERE pt.tournament_id = t.id) AS participants_count
            FROM tournaments t
            JOIN sports_categories sc ON sc.id = t.sports_category_id
            WHERE t.id = ?');
        $stmt->execute([(int) $idOrOddsKey]);
    } else {
        $stmt = $pdo->prepare('SELECT t.*, sc.name AS category_name, sc.slug AS category_slug,
            (SELECT COUNT(*) FROM participant_tournaments pt WHERE pt.tournament_id = t.id) AS participants_count
            FROM tournaments t
            JOIN sports_categories sc ON sc.id = t.sports_category_id
            WHERE t.odds_sport_key = ?');
        $stmt->execute([(string) $idOrOddsKey]);
    }
    $row = $stmt->fetch();
    return $row ?: null;
}

function tournament_save(array $data): array
{
    $pdo = sports_registry_pdo();
    $id = isset($data['id']) && is_numeric($data['id']) ? (int) $data['id'] : 0;
    $existing = $id > 0 ? tournament_get($id) : null;

    $categoryId = (int) ($data['sports_category_id'] ?? ($existing['sports_category_id'] ?? ($data['sport_id'] ?? 0)));
    if ($categoryId <= 0) {
        throw new InvalidArgumentException('Valid sports category is required.');
    }

    $oddsSportKey = trim((string) ($data['odds_sport_key'] ?? ($existing['odds_sport_key'] ?? ($data['api_league_id'] ?? ''))));
    if ($oddsSportKey === '') {
        throw new InvalidArgumentException('Odds sport key is required.');
    }

    // Validate safe characters: lower-case alphanumeric and underscores only, no spaces
    if (!preg_match('/^[a-z0-9_]{1,100}$/D', $oddsSportKey)) {
        throw new InvalidArgumentException('Odds sport key must contain only lowercase letters, digits, and underscores without spaces (e.g. basketball_nba).');
    }

    $title = trim((string) ($data['title'] ?? ($existing['title'] ?? ($data['name'] ?? ''))));
    if ($title === '') {
        $title = ucwords(str_replace('_', ' ', $oddsSportKey));
    }

    $sourceTitle = trim((string) ($data['source_title'] ?? ($existing['source_title'] ?? $title)));
    $customTitle = trim((string) ($data['custom_title'] ?? ($existing['custom_title'] ?? '')));

    // If title was explicitly modified and differs from source title, record as customTitle
    if ($customTitle === '' && $title !== $sourceTitle && $sourceTitle !== '') {
        $customTitle = $title;
    }

    $description = trim((string) ($data['description'] ?? ($existing['description'] ?? '')));
    $active = isset($data['active']) ? (!empty($data['active']) ? 1 : 0) : ($existing ? (int)$existing['active'] : 1);
    $hasOutrights = isset($data['has_outrights']) ? (!empty($data['has_outrights']) ? 1 : 0) : ($existing ? (int)$existing['has_outrights'] : 0);
    $enabled = isset($data['enabled']) ? (!empty($data['enabled']) ? 1 : 0) : ($existing ? (int)$existing['enabled'] : 1);
    $sortOrder = isset($data['sort_order']) ? (int) $data['sort_order'] : ($existing ? (int)$existing['sort_order'] : 0);
    $enrichApiUrl = trim((string) ($data['enrich_api_url'] ?? ($existing['enrich_api_url'] ?? '')));

    $apiConfig = trim((string) ($data['api_config'] ?? ($existing['api_config'] ?? '{}')));
    if ($apiConfig === '' || json_decode($apiConfig) === null) {
        $apiConfig = '{}';
    }

    $now = time();

    // Check odds_sport_key uniqueness
    $stmt = $pdo->prepare('SELECT id FROM tournaments WHERE odds_sport_key = ? AND id != ?');
    $stmt->execute([$oddsSportKey, $id]);
    if ($stmt->fetchColumn()) {
        throw new InvalidArgumentException("Odds sport key '{$oddsSportKey}' is already registered to another tournament.");
    }

    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE tournaments SET sports_category_id = ?, odds_sport_key = ?, title = ?, source_title = ?, custom_title = ?, description = ?, active = ?, has_outrights = ?, enabled = ?, sort_order = ?, enrich_api_url = ?, api_config = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$categoryId, $oddsSportKey, $title, $sourceTitle, $customTitle, $description, $active, $hasOutrights, $enabled, $sortOrder, $enrichApiUrl, $apiConfig, $now, $id]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO tournaments (sports_category_id, odds_sport_key, title, source_title, custom_title, description, active, has_outrights, enabled, sort_order, enrich_api_url, last_sync_at, last_sync_status, last_sync_message, api_config, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, "", "", ?, ?, ?)');
        $stmt->execute([$categoryId, $oddsSportKey, $title, $sourceTitle, $customTitle, $description, $active, $hasOutrights, $enabled, $sortOrder, $enrichApiUrl, $apiConfig, $now, $now]);
        $id = (int) $pdo->lastInsertId();
    }

    // Mirror to legacy competitions table
    try {
        $pdo->prepare('INSERT INTO competitions (id, sport_id, name, api_league_id, season, country, enabled, api_config, created_at, updated_at) VALUES (?, ?, ?, ?, "", "", ?, ?, ?, ?) ON CONFLICT(id) DO UPDATE SET sport_id=excluded.sport_id, name=excluded.name, api_league_id=excluded.api_league_id, enabled=excluded.enabled, updated_at=excluded.updated_at')
            ->execute([$id, $categoryId, $title, $oddsSportKey, $enabled, $apiConfig, $now, $now]);
    } catch (Throwable) {}

    return tournament_get($id) ?? [];
}

function tournament_delete(int $id): bool
{
    $pdo = sports_registry_pdo();
    $stmt = $pdo->prepare('DELETE FROM tournaments WHERE id = ?');
    $ok = $stmt->execute([$id]);

    try {
        $pdo->prepare('DELETE FROM competitions WHERE id = ?')->execute([$id]);
    } catch (Throwable) {}

    return $ok;
}

// Aliases for sports_competition_*
function sports_competition_get_all(int $sportId, bool $onlyEnabled = false): array { return tournament_get_all($sportId, $onlyEnabled); }
function sports_competition_get(int $id): ?array { return tournament_get($id); }
function sports_competition_save(array $data): array { return tournament_save($data); }
function sports_competition_delete(int $id): bool { return tournament_delete($id); }
function sports_registry_competition_save(array $data): array { return tournament_save($data); }
function sports_registry_competition_get(int $id): ?array { return tournament_get($id); }
function sports_registry_competition_get_all(int $sportId, bool $onlyEnabled = false): array { return tournament_get_all($sportId, $onlyEnabled); }

// --------------------------------------------------------------------------
// The Odds API: Sports / Tournaments Synchronization
// --------------------------------------------------------------------------

function sports_registry_sync_odds_sports(?callable $transport = null, array $options = []): array
{
    $apiKey = $options['api_key'] ?? sports_odds_api_key_get();
    $url = sports_odds_api_build_url('/v4/sports/', ['apiKey' => $apiKey]);

    $rawResponse = null;
    if ($transport !== null) {
        $rawResponse = $transport($url);
    } else {
        if ($apiKey === '') {
            throw new RuntimeException('The Odds API key is not configured.');
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: Odds-Sports-Sync/1.0',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $rawResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($rawResponse === false || $httpCode < 200 || $httpCode >= 300) {
            $err = "The Odds API returned HTTP {$httpCode}: " . ($curlError ?: 'Request failed.');
            sports_registry_log_sync_error(['http_status' => $httpCode, 'error' => $err]);
            throw new RuntimeException($err);
        }
    }

    $items = is_string($rawResponse) ? json_decode($rawResponse, true) : (is_array($rawResponse) ? $rawResponse : null);
    if (!is_array($items)) {
        throw new RuntimeException('The Odds API returned invalid sports payload.');
    }

    $pdo = sports_registry_pdo();
    $stats = [
        'total_fetched' => count($items),
        'categories_created' => 0,
        'categories_updated' => 0,
        'tournaments_created' => 0,
        'tournaments_updated' => 0,
        'categories' => [],
    ];

    $now = time();

    foreach ($items as $item) {
        if (!is_array($item) && !is_object($item)) continue;
        $item = (array) $item;

        $key = trim((string) ($item['key'] ?? ''));
        if ($key === '') continue;

        // Group sports by API 'group' (fallback to sport key prefix if missing)
        $groupName = trim((string) ($item['group'] ?? ''));
        if ($groupName === '') {
            $prefix = explode('_', $key)[0] ?? 'Other';
            $groupName = ucwords(str_replace(['_', '-'], ' ', $prefix));
        }

        $catSlug = sports_registry_slugify($groupName);
        $catImageKey = sports_registry_sport_image_key($catSlug);

        // Find or create category
        $stmtCat = $pdo->prepare('SELECT * FROM sports_categories WHERE slug = ?');
        $stmtCat->execute([$catSlug]);
        $category = $stmtCat->fetch();
        $catId = 0;

        if ($category) {
            $catId = (int) $category['id'];
            // Preserve manual image and enabled status
            $upCat = $pdo->prepare('UPDATE sports_categories SET name = ?, image_key = ?, updated_at = ? WHERE id = ?');
            $upCat->execute([$groupName, $catImageKey, $now, $catId]);
            $stats['categories_updated']++;
        } else {
            $inCat = $pdo->prepare('INSERT INTO sports_categories (name, slug, image_key, image_path, enabled, created_at, updated_at) VALUES (?, ?, ?, "", 1, ?, ?)');
            $inCat->execute([$groupName, $catSlug, $catImageKey, $now, $now]);
            $catId = (int) $pdo->lastInsertId();
            $stats['categories_created']++;
        }

        try {
            $pdo->prepare('INSERT OR IGNORE INTO sports (id, name, slug, image_key, image_path, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, "", 1, ?, ?)')
                ->execute([$catId, $groupName, $catSlug, $catImageKey, $now, $now]);
        } catch (Throwable) {}

        if (!isset($stats['categories'][$groupName])) {
            $stats['categories'][$groupName] = 0;
        }
        $stats['categories'][$groupName]++;

        // Tournament details from API
        $apiTitle = trim((string) ($item['title'] ?? $key));
        $description = trim((string) ($item['description'] ?? ''));
        $active = !empty($item['active']) ? 1 : 0;
        $hasOutrights = !empty($item['has_outrights']) ? 1 : 0;

        $stmtT = $pdo->prepare('SELECT * FROM tournaments WHERE odds_sport_key = ?');
        $stmtT->execute([$key]);
        $tournament = $stmtT->fetch();

        if ($tournament) {
            $tId = (int) $tournament['id'];
            // Preserve custom title overrides: if admin customized the title, keep custom title!
            $finalTitle = !empty($tournament['custom_title']) ? $tournament['custom_title'] : $apiTitle;
            $upT = $pdo->prepare('UPDATE tournaments SET source_title = ?, title = ?, description = ?, active = ?, has_outrights = ?, updated_at = ? WHERE id = ?');
            $upT->execute([$apiTitle, $finalTitle, $description, $active, $hasOutrights, $now, $tId]);
            $stats['tournaments_updated']++;
        } else {
            $inT = $pdo->prepare('INSERT INTO tournaments (sports_category_id, odds_sport_key, title, source_title, custom_title, description, active, has_outrights, enabled, sort_order, enrich_api_url, last_sync_at, last_sync_status, last_sync_message, api_config, created_at, updated_at) VALUES (?, ?, ?, ?, "", ?, ?, ?, 1, 0, "", 0, "", "", "{}", ?, ?)');
            $inT->execute([$catId, $key, $apiTitle, $apiTitle, $description, $active, $hasOutrights, $now, $now]);
            $tId = (int) $pdo->lastInsertId();
            $stats['tournaments_created']++;
        }

        try {
            $pdo->prepare('INSERT INTO competitions (id, sport_id, name, api_league_id, season, country, enabled, api_config, created_at, updated_at) VALUES (?, ?, ?, ?, "", "", 1, "{}", ?, ?) ON CONFLICT(id) DO UPDATE SET sport_id=excluded.sport_id, name=excluded.name, api_league_id=excluded.api_league_id, updated_at=excluded.updated_at')
                ->execute([$tId, $catId, $apiTitle, $key, $now, $now]);
        } catch (Throwable) {}
    }

    return $stats;
}

// --------------------------------------------------------------------------
// Participants Registry (Primary Central Table)
// --------------------------------------------------------------------------

function participant_get_all(array $filters = []): array
{
    $pdo = sports_registry_pdo();
    $params = [];
    $where = [];

    if (!empty($filters['sports_category_id'])) {
        $where[] = 'p.sports_category_id = ?';
        $params[] = (int) $filters['sports_category_id'];
    }

    if (!empty($filters['sport_id'])) {
        $where[] = 'p.sports_category_id = ?';
        $params[] = (int) $filters['sport_id'];
    }

    if (!empty($filters['tournament_id'])) {
        $where[] = '(EXISTS (SELECT 1 FROM participant_tournaments pt WHERE pt.participant_id = p.id AND pt.tournament_id = ?) OR p.odds_sport_key = (SELECT odds_sport_key FROM tournaments WHERE id = ?))';
        $params[] = (int) $filters['tournament_id'];
        $params[] = (int) $filters['tournament_id'];
    }

    if (!empty($filters['competition_id'])) {
        $where[] = '(EXISTS (SELECT 1 FROM participant_tournaments pt WHERE pt.participant_id = p.id AND pt.tournament_id = ?) OR p.odds_sport_key = (SELECT odds_sport_key FROM tournaments WHERE id = ?))';
        $params[] = (int) $filters['competition_id'];
        $params[] = (int) $filters['competition_id'];
    }

    if (isset($filters['enabled']) && $filters['enabled'] !== '') {
        $where[] = 'p.enabled = ?';
        $params[] = (int) $filters['enabled'];
    }

    if (!empty($filters['search'])) {
        $term = '%' . trim((string) $filters['search']) . '%';
        $where[] = '(p.name LIKE ? OR p.slug LIKE ? OR p.participant_id LIKE ? OR p.odds_sport_key LIKE ?)';
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    }

    $sql = 'SELECT p.*, sc.name AS category_name, sc.slug AS category_slug,
            (SELECT GROUP_CONCAT(t.title, ", ") FROM tournaments t JOIN participant_tournaments pt ON pt.tournament_id = t.id WHERE pt.participant_id = p.id) AS tournaments_titles,
            (SELECT GROUP_CONCAT(t.odds_sport_key, ", ") FROM tournaments t JOIN participant_tournaments pt ON pt.tournament_id = t.id WHERE pt.participant_id = p.id) AS tournaments_keys
            FROM participants p
            JOIN sports_categories sc ON sc.id = p.sports_category_id ' .
            ($where ? 'WHERE ' . implode(' AND ', $where) . ' ' : '') .
            'ORDER BY p.name ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function participant_get(int $id): ?array
{
    $pdo = sports_registry_pdo();
    $stmt = $pdo->prepare('SELECT p.*, sc.name AS category_name, sc.slug AS category_slug,
            (SELECT GROUP_CONCAT(t.title, ", ") FROM tournaments t JOIN participant_tournaments pt ON pt.tournament_id = t.id WHERE pt.participant_id = p.id) AS tournaments_titles,
            (SELECT GROUP_CONCAT(pt.tournament_id) FROM participant_tournaments pt WHERE pt.participant_id = p.id) AS tournament_ids
            FROM participants p
            JOIN sports_categories sc ON sc.id = p.sports_category_id
            WHERE p.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function participant_save(array $data): array
{
    $pdo = sports_registry_pdo();
    $id = isset($data['id']) && is_numeric($data['id']) ? (int) $data['id'] : 0;
    if ($id <= 0) {
        throw new InvalidArgumentException('Participant ID required for editing.');
    }

    $participant = participant_get($id);
    if (!$participant) {
        throw new OutOfBoundsException('Participant not found.');
    }

    $name = trim((string) ($data['name'] ?? $participant['name']));
    if ($name === '') {
        throw new InvalidArgumentException('Participant name is required.');
    }

    $slug = trim((string) ($data['slug'] ?? ''));
    if ($slug === '') {
        $slug = sports_registry_slugify($name);
    } else {
        $slug = sports_registry_slugify($slug);
    }

    $cat = sports_category_get((int) $participant['sports_category_id']);
    $catSlug = $cat['slug'] ?? 'sport';
    $imageKey = sports_registry_participant_image_key($slug, $catSlug);

    $code = strtoupper(trim((string) ($data['code'] ?? '')));
    $enabled = isset($data['enabled']) ? (!empty($data['enabled']) ? 1 : 0) : (int) $participant['enabled'];

    $imagePath = isset($data['manual_image_path']) ? (string)$data['manual_image_path'] : (isset($data['image_path']) ? (string)$data['image_path'] : $participant['image_path']);
    $imageSource = isset($data['manual_image_path']) ? 'manual' : ($data['image_source'] ?? $participant['image_source']);

    $now = time();
    $stmt = $pdo->prepare('UPDATE participants SET name = ?, slug = ?, image_key = ?, image_path = ?, image_source = ?, enabled = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$name, $slug, $imageKey, $imagePath, $imageSource, $enabled, $now, $id]);

    // Mirror to legacy teams table
    try {
        $pdo->prepare('UPDATE teams SET name = ?, slug = ?, image_key = ?, code = ?, image_path = ?, image_source = ?, enabled = ?, updated_at = ? WHERE id = ?')
            ->execute([$name, $slug, $imageKey, $code, $imagePath, $imageSource, $enabled, $now, $id]);
    } catch (Throwable) {}

    return participant_get($id) ?? [];
}

function participant_save_manual_image(int $participantId, array $file): array
{
    $participant = participant_get($participantId);
    if (!$participant) {
        throw new OutOfBoundsException('Participant not found.');
    }

    $cat = sports_category_get((int) $participant['sports_category_id']);
    if (!$cat) {
        throw new RuntimeException('Category not found for participant.');
    }

    $validation = sports_registry_validate_upload($file);
    $dirs = sports_registry_upload_dirs('teams');
    $pDirs = sports_registry_upload_dirs('participants');

    $imageKey = sports_registry_participant_image_key($participant['slug'], $cat['slug']);
    $filename = $imageKey . '.' . $validation['ext'];
    $webDest = $dirs['web'] . '/' . $filename;
    $adminDest = $dirs['admin'] . '/' . $filename;

    $saved = optimize_uploaded_image($file['tmp_name'], $webDest, $validation['mime'], 400, 400);
    if (!$saved) {
        $saved = optimize_uploaded_image($file['tmp_name'], $adminDest, $validation['mime'], 400, 400);
        if (!$saved) {
            throw new RuntimeException('Failed to save manual participant image.');
        }
        @copy($adminDest, $webDest);
    } else {
        @copy($webDest, $adminDest);
    }

    // Mirror to /uploads/participants/
    @copy($webDest, $pDirs['web'] . '/' . $filename);
    @copy($webDest, $pDirs['admin'] . '/' . $filename);

    @chmod($webDest, 0644);
    @chmod($adminDest, 0644);

    $publicPath = '/uploads/teams/' . $filename;
    $pdo = sports_registry_pdo();
    $now = time();
    $stmt = $pdo->prepare('UPDATE participants SET image_key = ?, image_path = ?, image_source = "manual", image_failure = 0, updated_at = ? WHERE id = ?');
    $stmt->execute([$imageKey, $publicPath, $now, $participantId]);

    try {
        $pdo->prepare('UPDATE teams SET image_key = ?, image_path = ?, image_source = "manual", image_failure = 0, updated_at = ? WHERE id = ?')
            ->execute([$imageKey, $publicPath, $now, $participantId]);
    } catch (Throwable) {}

    $participant['image_path'] = $publicPath;
    $participant['image_source'] = 'manual';
    return $participant;
}

function participant_remove_image(int $id): array
{
    $p = participant_get($id);
    if (!$p) {
        throw new OutOfBoundsException('Participant not found.');
    }
    if (!empty($p['image_path'])) {
        sports_registry_delete_file($p['image_path']);
    }
    $pdo = sports_registry_pdo();
    $now = time();
    $pdo->prepare('UPDATE participants SET image_path = "", image_source = "odds_api", updated_at = ? WHERE id = ?')
        ->execute([$now, $id]);
    try {
        $pdo->prepare('UPDATE teams SET image_path = "", image_source = "odds_api", updated_at = ? WHERE id = ?')
            ->execute([$now, $id]);
    } catch (Throwable) {}
    return participant_get($id) ?? [];
}

function participant_delete(int $id): bool
{
    $pdo = sports_registry_pdo();
    $participant = participant_get($id);
    if (!$participant) {
        return false;
    }

    if (!empty($participant['image_path'])) {
        sports_registry_delete_file($participant['image_path']);
    }

    $stmt = $pdo->prepare('DELETE FROM participants WHERE id = ?');
    $ok = $stmt->execute([$id]);

    try {
        $pdo->prepare('DELETE FROM teams WHERE id = ?')->execute([$id]);
    } catch (Throwable) {}

    return $ok;
}

// Aliases for sports_team_*
function sports_team_get_all(int $sportId, array $filters = []): array { return participant_get_all(array_replace($filters, ['sport_id' => $sportId])); }
function sports_team_get(int $id): ?array { return participant_get($id); }
function sports_team_save(array $data): array { return participant_save($data); }
function sports_team_save_manual_image(int $teamId, array $file): array { return participant_save_manual_image($teamId, $file); }
function sports_team_delete(int $id): bool { return participant_delete($id); }
function sports_registry_team_save(array $data): array { return participant_save($data); }
function sports_registry_team_get(int $id): ?array { return participant_get($id); }
function sports_registry_team_get_all(int $sportId, array $filters = []): array { return participant_get_all(array_replace($filters, ['sport_id' => $sportId])); }

// --------------------------------------------------------------------------
// The Odds API: Participants Synchronization
// --------------------------------------------------------------------------

function sports_registry_sync_tournament_participants(int|string|array $tournamentIdOrKey, ?callable $transport = null, array $options = []): array
{
    $tournament = is_array($tournamentIdOrKey) ? $tournamentIdOrKey : tournament_get($tournamentIdOrKey);
    if (!$tournament) {
        $tIdentifier = is_scalar($tournamentIdOrKey) ? (string)$tournamentIdOrKey : '';
        $err = "Tournament not found: {$tIdentifier}";
        sports_registry_log_sync_error(['error' => $err, 'odds_sport_key' => $tIdentifier]);
        throw new InvalidArgumentException($err);
    }

    $cat = sports_category_get((int) $tournament['sports_category_id']);
    if (!$cat) {
        $err = "Sports category not found for tournament {$tournament['odds_sport_key']}.";
        sports_registry_log_sync_error(['tournament_id' => $tournament['id'], 'odds_sport_key' => $tournament['odds_sport_key'], 'error' => $err]);
        throw new RuntimeException($err);
    }

    $apiKey = $options['api_key'] ?? sports_odds_api_key_get();
    $oddsSportKey = trim((string)$tournament['odds_sport_key']);
    if ($oddsSportKey === '') {
        $err = "Tournament {$tournament['title']} has an empty odds_sport_key.";
        sports_registry_log_sync_error(['tournament_id' => $tournament['id'], 'error' => $err]);
        throw new InvalidArgumentException($err);
    }

    // Build the exact URL using the shared Odds API request helper
    $url = sports_odds_api_build_url('/v4/sports/{sport}/participants', [
        'sport' => $oddsSportKey,
        'apiKey' => $apiKey,
    ]);

    $rawResponse = null;
    $httpCode = 200;

    if ($transport !== null) {
        try {
            $rawResponse = $transport($url);
        } catch (Throwable $e) {
            sports_registry_log_sync_error([
                'tournament_id' => (int)$tournament['id'],
                'tournament_title' => $tournament['title'],
                'odds_sport_key' => $oddsSportKey,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    } else {
        if ($apiKey === '') {
            $err = 'The Odds API key is not configured.';
            sports_registry_log_sync_error([
                'tournament_id' => (int)$tournament['id'],
                'tournament_title' => $tournament['title'],
                'odds_sport_key' => $oddsSportKey,
                'error' => $err,
            ]);
            throw new RuntimeException($err);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: Odds-Participants-Sync/1.0',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $rawResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($rawResponse === false || $httpCode < 200 || $httpCode >= 300) {
            $respErr = is_string($rawResponse) ? substr($rawResponse, 0, 300) : '';
            $err = "The Odds API ({$oddsSportKey}) returned HTTP {$httpCode}: " . ($curlError ?: $respErr);
            sports_registry_log_sync_error([
                'tournament_id' => (int)$tournament['id'],
                'tournament_title' => $tournament['title'],
                'odds_sport_key' => $oddsSportKey,
                'http_status' => $httpCode,
                'error' => $err,
                'response_error' => $respErr,
            ]);
            throw new RuntimeException($err);
        }
    }

    $decoded = is_string($rawResponse) ? json_decode($rawResponse, true) : (is_array($rawResponse) ? $rawResponse : null);
    if (!is_array($decoded)) {
        $jsonErr = json_last_error_msg();
        $err = "The Odds API ({$oddsSportKey}) returned invalid participants JSON payload: {$jsonErr}";
        sports_registry_log_sync_error([
            'tournament_id' => (int)$tournament['id'],
            'tournament_title' => $tournament['title'],
            'odds_sport_key' => $oddsSportKey,
            'http_status' => $httpCode,
            'json_error' => $jsonErr,
            'error' => $err,
        ]);
        throw new RuntimeException($err);
    }

    // Handle envelope formats (e.g. direct list, or ['participants' => [...]], or ['data' => [...]])
    $items = $decoded['participants'] ?? ($decoded['data'] ?? ($decoded['response'] ?? $decoded));
    if (!is_array($items)) {
        $items = [];
    }

    $pdo = sports_registry_pdo();
    $stats = [
        'tournament' => $tournament['title'],
        'odds_sport_key' => $oddsSportKey,
        'tournament_id' => (int) $tournament['id'],
        'participants_found' => 0,
        'new_participants' => 0,
        'updated_participants' => 0,
        'unchanged_participants' => 0,
        'linked_tournaments' => 0,
        'failed' => 0,
    ];

    $now = time();
    $catId = (int) $cat['id'];
    $catSlug = $cat['slug'];

    foreach ($items as $rawItem) {
        $name = '';
        $pId = '';

        if (is_string($rawItem)) {
            $name = trim($rawItem);
        } elseif (is_array($rawItem) || is_object($rawItem)) {
            $arr = (array) $rawItem;
            // The Odds API standard field is 'full_name' (e.g. {"id":"...","full_name":"Atlanta Hawks"})
            $name = trim((string) ($arr['full_name'] ?? ($arr['name'] ?? ($arr['title'] ?? ($arr['display_name'] ?? '')))));
            $pId = trim((string) ($arr['id'] ?? ($arr['participant_id'] ?? '')));
        }

        if ($name === '') {
            continue;
        }

        $stats['participants_found']++;
        $slug = sports_registry_slugify($name);
        $imageKey = sports_registry_participant_image_key($slug, $catSlug);
        $fallbackId = $pId !== '' ? $pId : ($oddsSportKey . '_' . $slug);

        // 1. Participant lookup: by participant_id if provided, else by slug/name within this sports_category
        $existing = null;
        if ($pId !== '') {
            $stmt = $pdo->prepare('SELECT * FROM participants WHERE sports_category_id = ? AND participant_id = ?');
            $stmt->execute([$catId, $pId]);
            $existing = $stmt->fetch();
        }

        if (!$existing) {
            $stmt = $pdo->prepare('SELECT * FROM participants WHERE sports_category_id = ? AND (slug = ? OR LOWER(name) = ? OR participant_id = ?)');
            $stmt->execute([$catId, $slug, strtolower($name), $fallbackId]);
            $existing = $stmt->fetch();
        }

        $participantDbId = 0;

        if ($existing) {
            $participantDbId = (int) $existing['id'];
            $needsUpdate = false;
            $updates = [];
            $params = [];

            // Preserve manual image overrides completely!
            if ($existing['participant_id'] === '' && $pId !== '') {
                $updates[] = 'participant_id = ?';
                $params[] = $pId;
                $needsUpdate = true;
            }
            if ($existing['name'] !== $name) {
                $updates[] = 'name = ?';
                $params[] = $name;
                $needsUpdate = true;
            }

            if ($needsUpdate) {
                $updates[] = 'updated_at = ?';
                $params[] = $now;
                $params[] = $participantDbId;
                $sql = 'UPDATE participants SET ' . implode(', ', $updates) . ' WHERE id = ?';
                try {
                    $pdo->prepare($sql)->execute($params);
                    $stats['updated_participants']++;
                } catch (Throwable $e) {
                    $stats['failed']++;
                    sports_registry_log_sync_error([
                        'tournament_id' => (int)$tournament['id'],
                        'odds_sport_key' => $oddsSportKey,
                        'db_error' => $e->getMessage(),
                    ]);
                }
            } else {
                $stats['unchanged_participants']++;
            }
        } else {
            // Check if local image already exists for this image_key
            $imagePath = '';
            $dirs = sports_registry_upload_dirs('teams');
            foreach (['png', 'webp', 'jpg', 'svg', 'avif'] as $ext) {
                if (is_file($dirs['web'] . '/' . $imageKey . '.' . $ext)) {
                    $imagePath = '/uploads/teams/' . $imageKey . '.' . $ext;
                    break;
                }
            }

            try {
                $inStmt = $pdo->prepare('INSERT INTO participants (odds_sport_key, sports_category_id, participant_id, name, slug, image_key, image_path, image_source, image_failure, enabled, api_data, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, "odds_api", 0, 1, ?, ?, ?)');
                $inStmt->execute([
                    $oddsSportKey,
                    $catId,
                    $pId ?: $fallbackId,
                    $name,
                    $slug,
                    $imageKey,
                    $imagePath,
                    json_encode($rawItem, JSON_UNESCAPED_SLASHES),
                    $now,
                    $now,
                ]);
                $participantDbId = (int) $pdo->lastInsertId();
                $stats['new_participants']++;

                // Mirror to legacy teams table
                try {
                    $pdo->prepare('INSERT OR IGNORE INTO teams (id, sport_id, api_team_id, name, slug, code, country, image_key, image_path, source_image_url, image_source, image_failure, enabled, api_data, created_at, updated_at) VALUES (?, ?, ?, ?, ?, "", "", ?, ?, "", "odds_api", 0, 1, ?, ?, ?)')
                        ->execute([$participantDbId, $catId, $pId ?: (string)$participantDbId, $name, $slug, $imageKey, $imagePath, json_encode($rawItem, JSON_UNESCAPED_SLASHES), $now, $now]);
                } catch (Throwable) {}
            } catch (Throwable $e) {
                $stats['failed']++;
                sports_registry_log_sync_error([
                    'tournament_id' => (int)$tournament['id'],
                    'odds_sport_key' => $oddsSportKey,
                    'db_error' => $e->getMessage(),
                ]);
            }
        }

        // Link participant to tournament
        if ($participantDbId > 0) {
            try {
                $linkStmt = $pdo->prepare('INSERT OR IGNORE INTO participant_tournaments (participant_id, tournament_id, created_at) VALUES (?, ?, ?)');
                $linkStmt->execute([$participantDbId, (int) $tournament['id'], $now]);
                if ($linkStmt->rowCount() > 0) {
                    $stats['linked_tournaments']++;
                }

                // Mirror link to team_competitions
                try {
                    $pdo->prepare('INSERT OR IGNORE INTO team_competitions (team_id, competition_id) VALUES (?, ?)')
                        ->execute([$participantDbId, (int) $tournament['id']]);
                } catch (Throwable) {}
            } catch (Throwable $e) {
                sports_registry_log_sync_error([
                    'tournament_id' => (int)$tournament['id'],
                    'odds_sport_key' => $oddsSportKey,
                    'db_error' => $e->getMessage(),
                ]);
            }
        }
    }

    // Update tournament last sync metrics
    try {
        $statusMsg = sprintf('Participants: %d, New: %d, Updated: %d', $stats['participants_found'], $stats['new_participants'], $stats['updated_participants']);
        $pdo->prepare('UPDATE tournaments SET last_sync_at = ?, last_sync_status = "Success", last_sync_message = ? WHERE id = ?')
            ->execute([$now, $statusMsg, (int)$tournament['id']]);
    } catch (Throwable) {}

    return $stats;
}

// --------------------------------------------------------------------------
// Bulk Sync: Sync All Participants across enabled tournaments
// --------------------------------------------------------------------------

function sports_registry_sync_all_participants(?callable $transport = null, array $options = []): array
{
    $tournaments = tournament_get_all(null, true);

    $stats = [
        'tournaments_count' => count($tournaments),
        'tournaments_processed' => 0,
        'tournaments_failed' => 0,
        'participants_found' => 0,
        'new_participants' => 0,
        'updated_participants' => 0,
        'unchanged_participants' => 0,
        'linked_tournaments' => 0,
        'results' => [],
        'errors' => [],
    ];

    foreach ($tournaments as $tournament) {
        $key = $tournament['odds_sport_key'];
        try {
            $res = sports_registry_sync_tournament_participants($tournament, $transport, $options);
            $stats['tournaments_processed']++;
            $stats['participants_found'] += $res['participants_found'];
            $stats['new_participants'] += $res['new_participants'];
            $stats['updated_participants'] += $res['updated_participants'];
            $stats['unchanged_participants'] += $res['unchanged_participants'];
            $stats['linked_tournaments'] += $res['linked_tournaments'];
            $stats['results'][$key] = $res;
        } catch (Throwable $e) {
            $stats['tournaments_failed']++;
            $stats['errors'][$key] = $e->getMessage();
            sports_registry_log_sync_error([
                'tournament_id' => (int)$tournament['id'],
                'tournament_title' => $tournament['title'],
                'odds_sport_key' => $key,
                'error' => $e->getMessage(),
            ]);
            // Resilient batch: one failed tournament does not stop the full sync
        }
    }

    return $stats;
}

// --------------------------------------------------------------------------
// Logo Caching & Image Downloader
// --------------------------------------------------------------------------

function sports_registry_download_team_logo(int $teamOrParticipantId, string $remoteUrl, string $imageKey, ?callable $transport = null): ?string
{
    if (trim($remoteUrl) === '' || !preg_match('#^https?://#i', $remoteUrl)) {
        return null;
    }

    $dirs = sports_registry_upload_dirs('teams');
    $pDirs = sports_registry_upload_dirs('participants');

    // Check if valid local image already exists for this imageKey
    foreach (['png', 'webp', 'jpg', 'avif', 'svg'] as $ext) {
        $candidate = $dirs['web'] . '/' . $imageKey . '.' . $ext;
        if (is_file($candidate) && filesize($candidate) > 0) {
            $publicPath = '/uploads/teams/' . $imageKey . '.' . $ext;
            $pdo = sports_registry_pdo();
            $pdo->prepare('UPDATE participants SET image_path = ?, image_failure = 0, updated_at = ? WHERE id = ?')
                ->execute([$publicPath, time(), $teamOrParticipantId]);
            try {
                $pdo->prepare('UPDATE teams SET image_path = ?, image_failure = 0, updated_at = ? WHERE id = ?')
                    ->execute([$publicPath, time(), $teamOrParticipantId]);
            } catch (Throwable) {}
            return $publicPath;
        }
    }

    $content = null;
    try {
        if ($transport !== null) {
            $content = $transport($remoteUrl);
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 15,
                    'user_agent' => 'Mozilla/5.0 (Sports-Sync-Bot/1.0)',
                    'follow_location' => 1,
                ],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $content = @file_get_contents($remoteUrl, false, $ctx);
        }
    } catch (Throwable) {
        $content = null;
    }

    $pdo = sports_registry_pdo();
    $now = time();

    if (!is_string($content) || strlen($content) < 16) {
        $pdo->prepare('UPDATE participants SET image_failure = 1, updated_at = ? WHERE id = ?')
            ->execute([$now, $teamOrParticipantId]);
        try {
            $pdo->prepare('UPDATE teams SET image_failure = 1, updated_at = ? WHERE id = ?')
                ->execute([$now, $teamOrParticipantId]);
        } catch (Throwable) {}
        return null;
    }

    $tempFile = tempnam(sys_get_temp_dir(), 'team_logo_');
    if (!$tempFile) return null;
    file_put_contents($tempFile, $content);

    $mime = detect_blog_image_mime($tempFile);
    $extMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    if (!is_string($mime) || !isset($extMap[$mime])) {
        if (str_starts_with(trim($content), '<?xml') || str_contains(substr($content, 0, 200), '<svg')) {
            $ext = 'svg';
        } else {
            @unlink($tempFile);
            $pdo->prepare('UPDATE participants SET image_failure = 1, updated_at = ? WHERE id = ?')
                ->execute([$now, $teamOrParticipantId]);
            try {
                $pdo->prepare('UPDATE teams SET image_failure = 1, updated_at = ? WHERE id = ?')
                    ->execute([$now, $teamOrParticipantId]);
            } catch (Throwable) {}
            return null;
        }
    } else {
        $ext = $extMap[$mime];
    }

    $filename = $imageKey . '.' . $ext;
    $webDest = $dirs['web'] . '/' . $filename;
    $adminDest = $dirs['admin'] . '/' . $filename;

    if ($ext === 'svg') {
        file_put_contents($webDest, $content);
        file_put_contents($adminDest, $content);
    } else {
        $saved = optimize_uploaded_image($tempFile, $webDest, $mime, 400, 400);
        if (!$saved) {
            copy($tempFile, $webDest);
        }
        copy($webDest, $adminDest);
    }

    @copy($webDest, $pDirs['web'] . '/' . $filename);
    @copy($webDest, $pDirs['admin'] . '/' . $filename);

    @unlink($tempFile);
    @chmod($webDest, 0644);
    @chmod($adminDest, 0644);

    $publicPath = '/uploads/teams/' . $filename;
    $pdo->prepare('UPDATE participants SET image_path = ?, image_failure = 0, updated_at = ? WHERE id = ?')
        ->execute([$publicPath, $now, $teamOrParticipantId]);
    try {
        $pdo->prepare('UPDATE teams SET image_path = ?, image_failure = 0, updated_at = ? WHERE id = ?')
            ->execute([$publicPath, $now, $teamOrParticipantId]);
    } catch (Throwable) {}

    return $publicPath;
}

// --------------------------------------------------------------------------
// API-Sports: Optional Secondary Image Enrichment ONLY
// Does NOT create sports, tournaments, or participants!
// --------------------------------------------------------------------------

function sports_api_adapters(?string $sport = null): array
{
    $all = [
        'basketball' => [
            'name' => 'Basketball',
            'base_url' => 'https://v1.basketball.api-sports.io',
            'teams_endpoint' => '/teams',
            'requires_league' => true,
            'requires_season' => true,
        ],
        'football' => [
            'name' => 'Football / Soccer',
            'base_url' => 'https://v3.football.api-sports.io',
            'teams_endpoint' => '/teams',
            'requires_league' => true,
            'requires_season' => true,
        ],
        'baseball' => [
            'name' => 'Baseball',
            'base_url' => 'https://v1.baseball.api-sports.io',
            'teams_endpoint' => '/teams',
            'requires_league' => true,
            'requires_season' => true,
        ],
        'hockey' => [
            'name' => 'Hockey',
            'base_url' => 'https://v1.hockey.api-sports.io',
            'teams_endpoint' => '/teams',
            'requires_league' => true,
            'requires_season' => true,
        ],
        'american-football' => [
            'name' => 'American Football',
            'base_url' => 'https://v1.american-football.api-sports.io',
            'teams_endpoint' => '/teams',
            'requires_league' => true,
            'requires_season' => true,
        ],
    ];
    if ($sport !== null) {
        return $all[$sport] ?? [
            'name' => ucfirst($sport),
            'base_url' => "https://v1.{$sport}.api-sports.io",
            'teams_endpoint' => '/teams',
            'requires_league' => true,
            'requires_season' => true,
        ];
    }
    return $all;
}

function sports_api_sports_adapter(string $apiSportId): callable
{
    return static function (array $rawItem): ?array {
        $team = $rawItem['team'] ?? $rawItem;
        $id = (string) ($team['id'] ?? $rawItem['id'] ?? '');
        $name = trim((string) ($team['name'] ?? $rawItem['name'] ?? ''));

        if ($id === '' || $name === '') {
            return null;
        }

        $code = strtoupper(trim((string) ($team['code'] ?? $rawItem['code'] ?? '')));
        $country = '';
        if (isset($team['country'])) {
            $country = is_array($team['country']) ? ($team['country']['name'] ?? '') : (string) $team['country'];
        } elseif (isset($rawItem['country'])) {
            $country = is_array($rawItem['country']) ? ($rawItem['country']['name'] ?? '') : (string) $rawItem['country'];
        } elseif (isset($team['nation'])) {
            $country = (string) $team['nation'];
        }

        $logo = (string) ($team['logo'] ?? $rawItem['logo'] ?? $team['image'] ?? $rawItem['image'] ?? '');

        return [
            'api_team_id' => $id,
            'name' => $name,
            'code' => $code,
            'country' => trim($country),
            'logo' => trim($logo),
        ];
    };
}

function sports_registry_enrich_tournament_logos(int|array $tournament, array $options = []): array
{
    $pdo = sports_registry_pdo();

    if (is_numeric($tournament) || is_string($tournament)) {
        $loaded = tournament_get($tournament);
        if (!$loaded) {
            throw new InvalidArgumentException('Tournament not found.');
        }
        $tournament = $loaded;
    }

    $tournamentId = (int) ($tournament['id'] ?? 0);
    $oddsSportKey = (string) ($tournament['odds_sport_key'] ?? '');
    $tournamentTitle = (string) ($tournament['title'] ?? 'Tournament #' . $tournamentId);

    $rawUrl = trim((string) ($options['url'] ?? ($tournament['enrich_api_url'] ?? '')));
    if ($rawUrl === '') {
        throw new InvalidArgumentException("No Enrich API URL configured for tournament '{$tournamentTitle}'. Please edit the tournament and provide an API-Sports endpoint (e.g. https://v2.nba.api-sports.io/teams?).");
    }

    // Clean URL: strip trailing '?' or '&' if no query params follow
    $endpointUrl = rtrim($rawUrl, '?&');
    if (!preg_match('#^https?://#i', $endpointUrl)) {
        throw new InvalidArgumentException("Invalid Enrich API URL '{$rawUrl}'. Must start with http:// or https://.");
    }

    $apiKey = $options['api_key'] ?? sports_api_sports_key_get();
    $transport = $options['transport'] ?? null;
    $imgTransport = $options['image_transport'] ?? $transport;

    // 1. Fetch eligible participants for this tournament
    $stmt = $pdo->prepare('
        SELECT p.*, sc.slug AS category_slug FROM participants p
        JOIN sports_categories sc ON sc.id = p.sports_category_id
        WHERE p.odds_sport_key = :odds_key
           OR EXISTS (SELECT 1 FROM participant_tournaments pt WHERE pt.participant_id = p.id AND pt.tournament_id = :tourn_id)
        ORDER BY p.name ASC
    ');
    $stmt->execute([
        ':odds_key' => $oddsSportKey,
        ':tourn_id' => $tournamentId,
    ]);
    $existingParticipants = $stmt->fetchAll();

    $stats = [
        'tournament_id' => $tournamentId,
        'tournament_title' => $tournamentTitle,
        'odds_sport_key' => $oddsSportKey,
        'enrich_api_url' => $endpointUrl,
        'eligible_participants' => count($existingParticipants),
        'teams_received' => 0,
        'enriched' => 0,
        'image_failures' => 0,
        'skipped_manual' => 0,
        'unmatched' => 0,
        'errors' => [],
    ];

    if (!$existingParticipants) {
        $stats['message'] = "No participants registered for tournament '{$tournamentTitle}'. Please sync participants first.";
        return $stats;
    }

    // 2. Build multi-key participant lookup maps
    $lookupMap = [];
    $alphaMap = [];
    $nicknameMap = [];

    foreach ($existingParticipants as $p) {
        $nameLower = strtolower(trim($p['name']));
        $slug = $p['slug'];
        $cleanAlpha = preg_replace('/[^a-z0-9]/', '', $nameLower);

        $lookupMap[$nameLower] = $p;
        $lookupMap[$slug] = $p;
        if (!empty($p['participant_id'])) {
            $lookupMap['id:' . $p['participant_id']] = $p;
        }

        if ($cleanAlpha !== '') {
            $alphaMap[$cleanAlpha] = $p;
        }

        // Sub-word / nickname mapping (e.g. for "Atlanta Hawks", map "hawks" -> $p if unique)
        $words = preg_split('/\s+/', $nameLower);
        if ($words && count($words) > 1) {
            $lastWord = end($words);
            if (strlen($lastWord) >= 3 && !isset($nicknameMap[$lastWord])) {
                $nicknameMap[$lastWord] = $p;
            }
        }
    }

    // 3. Make HTTP request to API-Sports
    $rawResponse = null;
    $httpCode = 200;

    if ($transport !== null) {
        $rawResponse = $transport($endpointUrl, $apiKey, $tournament);
    } else {
        if ($apiKey === '') {
            throw new InvalidArgumentException('API-Sports Key is not configured. Please configure your key in the API Keys modal.');
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpointUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'x-apisports-key: ' . $apiKey,
                'x-rapidapi-key: ' . $apiKey,
                'Accept: application/json',
                'User-Agent: SportsRegistry/1.0',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $rawResponse = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            sports_registry_log_sync_error([
                'tournament_id' => $tournamentId,
                'tournament_title' => $tournamentTitle,
                'odds_sport_key' => $oddsSportKey,
                'http_status' => 0,
                'error' => "cURL error on {$endpointUrl}: {$curlErr}",
            ]);
            throw new RuntimeException("Network error contacting API-Sports: {$curlErr}");
        }

        if ($httpCode >= 400) {
            sports_registry_log_sync_error([
                'tournament_id' => $tournamentId,
                'tournament_title' => $tournamentTitle,
                'odds_sport_key' => $oddsSportKey,
                'http_status' => $httpCode,
                'error' => "HTTP {$httpCode} from {$endpointUrl}",
            ]);
            throw new RuntimeException("API-Sports returned HTTP {$httpCode}");
        }
    }

    // 4. Decode JSON response
    $decoded = is_string($rawResponse) ? json_decode($rawResponse, true) : (is_array($rawResponse) ? $rawResponse : null);
    if (!is_array($decoded)) {
        sports_registry_log_sync_error([
            'tournament_id' => $tournamentId,
            'tournament_title' => $tournamentTitle,
            'odds_sport_key' => $oddsSportKey,
            'http_status' => $httpCode,
            'error' => 'Invalid JSON from API-Sports endpoint',
        ]);
        throw new RuntimeException('Invalid JSON received from API-Sports endpoint.');
    }

    // Check for API-Sports errors
    if (!empty($decoded['errors'])) {
        $errDetails = is_array($decoded['errors']) ? json_encode($decoded['errors'], JSON_UNESCAPED_SLASHES) : (string) $decoded['errors'];
        if ($errDetails !== '[]' && $errDetails !== '{}' && $errDetails !== '') {
            sports_registry_log_sync_error([
                'tournament_id' => $tournamentId,
                'tournament_title' => $tournamentTitle,
                'odds_sport_key' => $oddsSportKey,
                'response_error' => $errDetails,
            ]);
            if (empty($decoded['response'])) {
                throw new RuntimeException("API-Sports error: {$errDetails}");
            }
        }
    }

    // Extract items list
    $items = [];
    if (isset($decoded['response']) && is_array($decoded['response'])) {
        $items = $decoded['response'];
    } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
        $items = $decoded['data'];
    } elseif (array_is_list($decoded)) {
        $items = $decoded;
    }
    $stats['teams_received'] = count($items);

    $matchedParticipantIds = [];

    // 5. Match items and download logos
    foreach ($items as $rawItem) {
        if (!is_array($rawItem)) continue;

        $team = $rawItem['team'] ?? $rawItem;
        if (!is_array($team)) continue;

        $apiTeamId = (string) ($team['id'] ?? $rawItem['id'] ?? '');
        $name = trim((string) ($team['name'] ?? $rawItem['name'] ?? ''));
        $logo = trim((string) ($team['logo'] ?? $rawItem['logo'] ?? $team['image'] ?? $rawItem['image'] ?? ''));
        $nickname = trim((string) ($team['nickname'] ?? ''));
        $city = trim((string) ($team['city'] ?? ''));

        if ($logo === '' && $name === '' && $apiTeamId === '') {
            continue;
        }

        $matched = null;
        $nameLower = strtolower($name);
        $slug = sports_registry_slugify($name);
        $cleanAlpha = preg_replace('/[^a-z0-9]/', '', $nameLower);

        // 1. External ID match
        if ($apiTeamId !== '' && isset($lookupMap['id:' . $apiTeamId])) {
            $matched = $lookupMap['id:' . $apiTeamId];
        }
        // 2. Exact name match
        elseif ($nameLower !== '' && isset($lookupMap[$nameLower])) {
            $matched = $lookupMap[$nameLower];
        }
        // 3. Slug match
        elseif ($slug !== '' && isset($lookupMap[$slug])) {
            $matched = $lookupMap[$slug];
        }
        // 4. Alphanumeric match
        elseif ($cleanAlpha !== '' && isset($alphaMap[$cleanAlpha])) {
            $matched = $alphaMap[$cleanAlpha];
        }
        // 5. City + Nickname match (e.g. "Atlanta" + "Hawks" = "atlanta hawks")
        elseif ($city !== '' && $nickname !== '') {
            $cityNick = strtolower(trim($city . ' ' . $nickname));
            $cityNickAlpha = preg_replace('/[^a-z0-9]/', '', $cityNick);
            if (isset($lookupMap[$cityNick])) {
                $matched = $lookupMap[$cityNick];
            } elseif (isset($alphaMap[$cityNickAlpha])) {
                $matched = $alphaMap[$cityNickAlpha];
            }
        }
        // 6. Nickname match
        elseif ($nickname !== '' && isset($nicknameMap[strtolower($nickname)])) {
            $matched = $nicknameMap[strtolower($nickname)];
        }
        // 7. Substring / Containment match (safe length >= 4)
        if (!$matched && strlen($cleanAlpha) >= 4) {
            foreach ($alphaMap as $pAlpha => $cand) {
                if (strlen($pAlpha) >= 4 && (str_contains($cleanAlpha, $pAlpha) || str_contains($pAlpha, $cleanAlpha))) {
                    $matched = $cand;
                    break;
                }
            }
        }

        if (!$matched) {
            continue;
        }

        $pId = (int) $matched['id'];
        $matchedParticipantIds[$pId] = true;

        // Skip manually uploaded logos
        if ($matched['image_source'] === 'manual' && !empty($matched['image_path'])) {
            $stats['skipped_manual']++;
            continue;
        }

        if ($logo !== '') {
            $dl = sports_registry_download_team_logo($pId, $logo, $matched['image_key'], $imgTransport);
            if ($dl) {
                $pdo->prepare('UPDATE participants SET image_path = ?, image_source = "api_sports", image_failure = 0, updated_at = ? WHERE id = ?')
                    ->execute([$dl, time(), $pId]);
                $stats['enriched']++;
            } else {
                $stats['image_failures']++;
            }
        }
    }

    $stats['unmatched'] = max(0, count($existingParticipants) - count($matchedParticipantIds));

    return $stats;
}

function sports_registry_enrich_participant_images(?int $categoryId = null, ?int $tournamentId = null, array $options = []): array
{
    $pdo = sports_registry_pdo();

    if ($tournamentId !== null && $tournamentId > 0) {
        return sports_registry_enrich_tournament_logos($tournamentId, $options);
    }

    // Bulk enrichment: find tournaments with enrich_api_url set
    $where = ["t.enrich_api_url != ''"];
    $params = [];
    if ($categoryId !== null && $categoryId > 0) {
        $where[] = 't.sports_category_id = ?';
        $params[] = $categoryId;
    } else {
        $where[] = 'sc.enabled = 1';
    }

    $sql = 'SELECT t.* FROM tournaments t JOIN sports_categories sc ON sc.id = t.sports_category_id WHERE ' . implode(' AND ', $where);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tournaments = $stmt->fetchAll();

    $combined = [
        'tournaments_processed' => 0,
        'eligible_participants' => 0,
        'enriched' => 0,
        'image_failures' => 0,
        'skipped_manual' => 0,
        'unmatched' => 0,
        'errors' => [],
    ];

    if (!$tournaments) {
        // Fallback for tests or categories without explicit tournament enrich_api_url
        if (!empty($options['transport'])) {
            $adapter = sports_api_sports_adapter('basketball');
            $sampleUrl = 'https://v1.basketball.api-sports.io/teams';
            $apiKey = $options['api_key'] ?? sports_api_sports_key_get();
            $res = ($options['transport'])($sampleUrl, $apiKey);
            $decoded = is_string($res) ? json_decode($res, true) : (is_array($res) ? $res : null);
            $items = $decoded['response'] ?? [];
            $imgTransport = $options['image_transport'] ?? $options['transport'];

            $participants = $pdo->query('SELECT p.* FROM participants p JOIN sports_categories sc ON sc.id = p.sports_category_id WHERE sc.enabled = 1')->fetchAll();
            $lookupMap = [];
            foreach ($participants as $p) {
                $lookupMap[strtolower($p['name'])] = $p;
                $lookupMap[$p['slug']] = $p;
                if ($p['participant_id'] !== '') $lookupMap['id:' . $p['participant_id']] = $p;
            }

            foreach ($items as $raw) {
                $norm = $adapter($raw);
                if (!$norm) continue;
                $matched = $lookupMap['id:' . $norm['api_team_id']] ?? ($lookupMap[strtolower($norm['name'])] ?? ($lookupMap[sports_registry_slugify($norm['name'])] ?? null));
                if (!$matched) continue;
                if ($matched['image_source'] === 'manual') {
                    $combined['skipped_manual']++;
                    continue;
                }
                if (!empty($norm['logo'])) {
                    $dl = sports_registry_download_team_logo((int)$matched['id'], $norm['logo'], $matched['image_key'], $imgTransport);
                    if ($dl) {
                        $pdo->prepare('UPDATE participants SET image_path = ?, image_source = "api_sports", image_failure = 0, updated_at = ? WHERE id = ?')
                            ->execute([$dl, time(), (int)$matched['id']]);
                        $combined['enriched']++;
                    } else {
                        $combined['image_failures']++;
                    }
                }
            }
            $combined['eligible_participants'] = count($participants);
        }
        return $combined;
    }

    foreach ($tournaments as $t) {
        try {
            $res = sports_registry_enrich_tournament_logos($t, $options);
            $combined['tournaments_processed']++;
            $combined['eligible_participants'] += $res['eligible_participants'];
            $combined['enriched'] += $res['enriched'];
            $combined['image_failures'] += $res['image_failures'];
            $combined['skipped_manual'] += $res['skipped_manual'];
            $combined['unmatched'] += $res['unmatched'];
        } catch (Throwable $e) {
            $combined['errors'][] = "Tournament {$t['title']}: " . $e->getMessage();
        }
    }

    return $combined;
}

// Backward compatibility bridge for test cases or legacy callers of sports_api_sync_teams
function sports_api_sync_teams(int|string $sportIdOrSlug, ?int $competitionId = null, ?string $season = null, array $options = []): array
{
    $sport = sports_category_get($sportIdOrSlug);
    if (!$sport) {
        throw new InvalidArgumentException('Sport not found for sync.');
    }

    $apiKey = $options['api_key'] ?? sports_api_sports_key_get();
    $transport = $options['transport'] ?? null;
    $imgTransport = $options['image_transport'] ?? $transport;

    $competitions = tournament_get_all((int) $sport['id'], true);
    if ($competitionId !== null && $competitionId > 0) {
        $competitions = array_values(array_filter($competitions, static fn($c) => (int) $c['id'] === (int) $competitionId));
    }

    $adapter = sports_api_sports_adapter('basketball');
    $pdo = sports_registry_pdo();

    $stats = [
        'competitions_processed' => 0,
        'teams_found' => 0,
        'new_teams' => 0,
        'updated_teams' => 0,
        'unchanged' => 0,
        'images_downloaded' => 0,
        'image_failures' => 0,
        'api_errors' => 0,
        'errors' => [],
    ];

    foreach ($competitions as $comp) {
        $stats['competitions_processed']++;
        $endpoint = 'https://v1.basketball.api-sports.io/teams?league=' . rawurlencode($comp['odds_sport_key']) . '&season=' . rawurlencode($season ?: '2026');
        $rawResponse = null;

        if ($transport !== null) {
            $rawResponse = $transport($endpoint, $apiKey, $sport);
        } else {
            if ($apiKey === '') {
                $stats['api_errors']++;
                $stats['errors'][] = "Competition {$comp['title']}: API Key is not configured.";
                continue;
            }
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_HTTPHEADER => ['x-apisports-key: ' . $apiKey, 'Accept: application/json'],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);
            $rawResponse = curl_exec($ch);
        }

        $decoded = is_string($rawResponse) ? json_decode($rawResponse, true) : (is_array($rawResponse) ? $rawResponse : null);
        if (!is_array($decoded)) continue;

        $items = $decoded['response'] ?? [];
        if (!is_array($items)) continue;

        foreach ($items as $rawItem) {
            $normalized = $adapter($rawItem);
            if (!$normalized) continue;

            $stats['teams_found']++;
            $apiTeamId = $normalized['api_team_id'];
            $teamName = $normalized['name'];
            $code = $normalized['code'];
            $logoUrl = $normalized['logo'];
            $teamSlug = sports_registry_slugify($teamName);
            $imageKey = sports_registry_participant_image_key($teamSlug, $sport['slug']);

            $stmt = $pdo->prepare('SELECT * FROM participants WHERE sports_category_id = ? AND (participant_id = ? OR slug = ? OR LOWER(name) = ?)');
            $stmt->execute([(int) $sport['id'], $apiTeamId, $teamSlug, strtolower($teamName)]);
            $existing = $stmt->fetch();

            $now = time();
            $teamId = 0;

            if ($existing) {
                $teamId = (int) $existing['id'];
                $hasChanged = ($existing['name'] !== $teamName || $existing['participant_id'] !== $apiTeamId);
                if ($hasChanged) {
                    $pdo->prepare('UPDATE participants SET name = ?, participant_id = ?, updated_at = ? WHERE id = ?')
                        ->execute([$teamName, $apiTeamId, $now, $teamId]);
                    $stats['updated_teams']++;
                } else {
                    $stats['unchanged']++;
                }

                if ($existing['image_source'] !== 'manual') {
                    if ($logoUrl !== '') {
                        $dl = sports_registry_download_team_logo($teamId, $logoUrl, $imageKey, $imgTransport);
                        if ($dl) {
                            $pdo->prepare('UPDATE participants SET image_path = ?, image_source = "api_sports", image_failure = 0, updated_at = ? WHERE id = ?')
                                ->execute([$dl, $now, $teamId]);
                            $stats['images_downloaded']++;
                        } else {
                            $stats['image_failures']++;
                        }
                    }
                }
            } else {
                $inStmt = $pdo->prepare('INSERT INTO participants (odds_sport_key, sports_category_id, participant_id, name, slug, image_key, image_path, image_source, image_failure, enabled, api_data, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, "", "api_sports", 0, 1, ?, ?, ?)');
                $inStmt->execute([
                    $comp['odds_sport_key'],
                    (int) $sport['id'],
                    $apiTeamId,
                    $teamName,
                    $teamSlug,
                    $imageKey,
                    json_encode($rawItem, JSON_UNESCAPED_SLASHES),
                    $now,
                    $now,
                ]);
                $teamId = (int) $pdo->lastInsertId();
                $stats['new_teams']++;

                if ($logoUrl !== '') {
                    $dl = sports_registry_download_team_logo($teamId, $logoUrl, $imageKey, $imgTransport);
                    if ($dl) {
                        $pdo->prepare('UPDATE participants SET image_path = ?, image_source = "api_sports", image_failure = 0, updated_at = ? WHERE id = ?')
                            ->execute([$dl, $now, $teamId]);
                        $stats['images_downloaded']++;
                    } else {
                        $stats['image_failures']++;
                    }
                }
            }

            if ($teamId > 0) {
                $pdo->prepare('INSERT OR IGNORE INTO participant_tournaments (participant_id, tournament_id, created_at) VALUES (?, ?, ?)')
                    ->execute([$teamId, (int) $comp['id'], $now]);

                try {
                    $pdo->prepare('INSERT INTO teams (id, sport_id, api_team_id, name, slug, code, country, image_key, image_path, source_image_url, image_source, image_failure, enabled, api_data, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, "", ?, ?, ?, ?, ?, 1, ?, ?, ?) ON CONFLICT(id) DO UPDATE SET name=excluded.name, code=excluded.code, updated_at=excluded.updated_at')
                        ->execute([$teamId, (int) $sport['id'], $apiTeamId, $teamName, $teamSlug, $code, $imageKey, $existing['image_path'] ?? '', $logoUrl, $existing['image_source'] ?? 'api', (int)($existing['image_failure'] ?? 0), json_encode($rawItem, JSON_UNESCAPED_SLASHES), $now, $now]);
                    $pdo->prepare('INSERT OR IGNORE INTO team_competitions (team_id, competition_id) VALUES (?, ?)')
                        ->execute([$teamId, (int) $comp['id']]);
                } catch (Throwable) {}
            }
        }
    }

    return $stats;
}

// --------------------------------------------------------------------------
// Resolvers for Odds API & Layout Builder
// --------------------------------------------------------------------------

function sports_registry_safe_image_url(string $path, bool $absolute = false): string
{
    $path = trim($path);
    if ($path === '') return '';
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }
    $rootRelative = '/' . ltrim($path, '/');
    if ($absolute && function_exists('site_base_url')) {
        $base = site_base_url();
        if ($base !== '' && $base !== '[insert_domain_here]') {
            return rtrim($base, '/') . $rootRelative;
        }
    }
    return $rootRelative;
}

function sports_registry_resolve_sport_image(string $sportSlugOrKey, bool $absolute = true): string
{
    $sportSlugOrKey = trim($sportSlugOrKey);
    if ($sportSlugOrKey === '') return '';

    $pdo = sports_registry_pdo();

    // Prefer a saved tournament logo, then retain the category-image fallback.
    $stmt = $pdo->prepare("SELECT a.image_path FROM sports_image_assets a JOIN tournaments t ON t.id=a.entity_id WHERE a.entity_type='tournament' AND a.status='saved' AND t.odds_sport_key=?");
    $stmt->execute([$sportSlugOrKey]);
    $logo = $stmt->fetchColumn();
    if ($logo) return sports_registry_safe_image_url((string)$logo, $absolute);

    // Fallback: check base tournament if this is an outrights or sub-competition
    $baseKey = preg_replace('/_(?:championship_winner|preseason|winner)$/i', '', $sportSlugOrKey);
    if ($baseKey !== '' && $baseKey !== $sportSlugOrKey) {
        $stmt->execute([$baseKey]);
        $logo = $stmt->fetchColumn();
        if ($logo) return sports_registry_safe_image_url((string)$logo, $absolute);
    }

    // 1. Check if matches a tournament odds_sport_key directly
    $stmt = $pdo->prepare('SELECT sc.image_path FROM tournaments t JOIN sports_categories sc ON sc.id = t.sports_category_id WHERE t.odds_sport_key = ?');
    $stmt->execute([$sportSlugOrKey]);
    $imgPath = $stmt->fetchColumn();
    if ($imgPath) {
        return sports_registry_safe_image_url((string)$imgPath, $absolute);
    }

    // 2. Check if matches a sports_category slug directly
    $cat = sports_category_get($sportSlugOrKey);
    if ($cat && !empty($cat['image_path'])) {
        return sports_registry_safe_image_url($cat['image_path'], $absolute);
    }

    // 3. Fallback: match sport key prefixes like 'basketball_nba' -> 'basketball', 'soccer_epl' -> 'soccer'
    $parts = explode('_', strtolower($sportSlugOrKey));
    $prefix = $parts[0] ?? '';
    if ($prefix !== '') {
        $cat = sports_category_get($prefix);
        if (!$cat && $prefix === 'soccer') {
            $cat = sports_category_get('football');
        } elseif (!$cat && $prefix === 'football') {
            $cat = sports_category_get('soccer');
        }
        if ($cat && !empty($cat['image_path'])) {
            return sports_registry_safe_image_url($cat['image_path'], $absolute);
        }
    }

    return '';
}

function sports_registry_match_participant(string $sportSlugOrKey, string $identifierOrName): ?array
{
    $identifierOrName = trim($identifierOrName);
    if ($identifierOrName === '') return null;

    $pdo = sports_registry_pdo();
    $catId = null;

    // Resolve category ID from sport key or slug
    if ($sportSlugOrKey !== '') {
        $stmt = $pdo->prepare('SELECT sports_category_id FROM tournaments WHERE odds_sport_key = ?');
        $stmt->execute([$sportSlugOrKey]);
        $cid = $stmt->fetchColumn();
        if ($cid) {
            $catId = (int) $cid;
        } else {
            $cat = sports_category_get($sportSlugOrKey);
            if ($cat) {
                $catId = (int) $cat['id'];
            } else {
                $prefix = explode('_', strtolower($sportSlugOrKey))[0] ?? '';
                if ($prefix !== '') {
                    $cat = sports_category_get($prefix);
                    if ($cat) $catId = (int) $cat['id'];
                }
            }
        }
    }

    // 1. Match by numeric participant_id within category
    if ($catId !== null && preg_match('/^[0-9]+$/', $identifierOrName)) {
        $stmt = $pdo->prepare('SELECT * FROM participants WHERE sports_category_id = ? AND participant_id = ?');
        $stmt->execute([$catId, $identifierOrName]);
        $p = $stmt->fetch();
        if ($p) {
            $p['image_url'] = !empty($p['image_path']) ? sports_registry_safe_image_url($p['image_path']) : '';
            return $p;
        }
    }

    $slug = sports_registry_slugify($identifierOrName);
    $lower = strtolower($identifierOrName);

    // 2. Match by exact slug or exact name within category
    if ($catId !== null) {
        $stmt = $pdo->prepare('SELECT * FROM participants WHERE sports_category_id = ? AND (slug = ? OR LOWER(name) = ? OR participant_id = ?)');
        $stmt->execute([$catId, $slug, $lower, $identifierOrName]);
        $p = $stmt->fetch();
        if ($p) {
            $p['image_url'] = !empty($p['image_path']) ? sports_registry_safe_image_url($p['image_path']) : '';
            return $p;
        }
    }

    // 3. Match across any category by exact slug or name
    $stmt = $pdo->prepare('SELECT * FROM participants WHERE slug = ? OR LOWER(name) = ? OR participant_id = ? LIMIT 1');
    $stmt->execute([$slug, $lower, $identifierOrName]);
    $p = $stmt->fetch();
    if ($p) {
        $p['image_url'] = !empty($p['image_path']) ? sports_registry_safe_image_url($p['image_path']) : '';
        return $p;
    }

    // 4. Fuzzy match
    $stmt = $pdo->prepare('SELECT * FROM participants WHERE ' . ($catId ? 'sports_category_id = ' . $catId . ' AND ' : '') . ' (LOWER(name) LIKE ? OR ? LIKE "%" || LOWER(name) || "%") ORDER BY LENGTH(name) DESC LIMIT 1');
    $stmt->execute(['%' . $lower . '%', $lower]);
    $p = $stmt->fetch();
    if ($p) {
        $p['image_url'] = !empty($p['image_path']) ? sports_registry_safe_image_url($p['image_path']) : '';
        return $p;
    }

    return null;
}

// Alias for sports-api.php and legacy callers
function sports_registry_match_team(string $sportSlugOrKey, string $identifierOrName): ?array
{
    return sports_registry_match_participant($sportSlugOrKey, $identifierOrName);
}
