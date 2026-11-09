<?php
declare(strict_types=1);

require_once __DIR__ . '/blog-storage.php';

const SETTINGS_REPLACE_COLUMNS = [
    'title' => 'Title',
    'seo_title' => 'SEO title',
    'category' => 'Category',
    'author' => 'Author',
    'excerpt' => 'Short excerpt',
    'content' => 'Content',
    'featured_image' => 'Featured image path',
    'focus_keyphrase' => 'Focus keyphrase',
    'date_label' => 'Date label',
];

/** Explicit content allowlist; never accept SQL identifiers or file paths from POST. */
function settings_replace_sources(): array
{
    return [
        'blogs' => ['label' => 'Blogs (blogs.sqlite)', 'file' => 'blogs.sqlite', 'tables' => [
            'blog_posts' => array_keys(SETTINGS_REPLACE_COLUMNS),
            'blog_categories' => ['name', 'description'],
            'tags' => ['name'],
        ]],
        'games' => ['label' => 'Games (games.sqlite)', 'file' => 'games.sqlite', 'tables' => [
            'games' => ['name', 'url', 'thumb', 'short_description', 'long_description', 'provider', 'type', 'release', 'reels', 'volatility', 'paylines', 'max_exposure', 'orientation'],
            'game_providers' => ['name', 'thumbnail'],
            'game_types' => ['name'],
            'game_themes' => ['name'],
        ]],
        'sports' => ['label' => 'Sports (sports-registry.sqlite)', 'file' => 'sports-registry.sqlite', 'tables' => [
            'sports_categories' => ['name', 'image_path'],
            'tournaments' => ['title', 'source_title', 'custom_title', 'description'],
            'participants' => ['name', 'image_path'],
            'sports' => ['name', 'image_path'],
            'competitions' => ['name', 'season', 'country'],
            'teams' => ['name', 'code', 'country', 'image_path', 'source_image_url'],
        ]],
        'odds' => ['label' => 'Odds (odds-api.sqlite)', 'file' => 'odds-api.sqlite', 'tables' => [
            'iframe_settings' => ['title'],
        ]],
        'livescore' => ['label' => 'Livescore / Odds iframes (sports-livescore.sqlite)', 'file' => 'sports-livescore.sqlite', 'tables' => [
            'matches' => ['sport', 'competition', 'home_name', 'away_name'],
            'iframes' => ['title', 'layout_config'],
        ]],
    ];
}

/** Decode layout JSON so escaped slashes and quotes match the actual button URL. */
function settings_replace_layout_urls(string $json, string $find, ?string $replace): array
{
    if ($json === '') return [0, $json];
    $nodes = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
    if (!is_array($nodes)) throw new UnexpectedValueException('Invalid iframe layout.');
    $matches = 0;
    $walk = static function (array $list) use (&$walk, &$matches, $find, $replace): void {
        foreach ($list as $node) {
            if (!is_object($node)) continue;
            if (in_array($node->type ?? '', ['button', 'link'], true) && is_string($node->url ?? null)) {
                $count = substr_count($node->url, $find);
                $matches += $count;
                if ($count && $replace !== null) $node->url = str_replace($find, $replace, $node->url);
            }
            foreach (['children', 'blocks'] as $children) {
                if (isset($node->$children) && is_array($node->$children)) $walk($node->$children);
            }
        }
    };
    $walk($nodes);
    return [$matches, $matches && $replace !== null ? json_encode($nodes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) : $json];
}

function settings_find_matches(string $find, string $database = 'blogs'): array
{
    return settings_replace_run($find, null, $database);
}

function settings_apply_replace(string $find, string $replace, string $database = 'blogs'): array
{
    $result = settings_replace_run($find, $replace, $database);
    if ($result['totalMatches'] > 0 && ($database === 'blogs' || $database === 'all')) {
        foreach (glob(blog_storage_dir() . '/api-cache/*.json') ?: [] as $path) {
            if (is_file($path)) @unlink($path);
        }
    }
    return $result;
}

function settings_replace_run(string $find, ?string $replace, string $database): array
{
    $sources = settings_replace_sources();
    if ($database !== 'all' && !isset($sources[$database])) {
        throw new InvalidArgumentException('Unknown content database.');
    }
    if ($find === '') throw new InvalidArgumentException('Find text is required.');
    if ($database !== 'all') $sources = [$database => $sources[$database]];

    // One connection and transaction allow a failure in any selected database to roll back all updates.
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec('PRAGMA foreign_keys=ON');
    foreach ($sources as $key => $source) {
        $path = blog_storage_dir() . '/' . $source['file'];
        if (!is_file($path)) { unset($sources[$key]); continue; }
        $pdo->exec('ATTACH DATABASE ' . $pdo->quote($path) . ' AS "' . $key . '"');
    }
    $result = ['summary' => [], 'matchedRows' => [], 'totalMatches' => 0, 'totalRows' => 0, 'changedColumns' => 0];
    $pdo->beginTransaction();
    try {
        foreach ($sources as $key => $source) {
            foreach ($source['tables'] as $table => $allowed) {
                $schema = $pdo->query('PRAGMA "' . $key . '".table_info("' . $table . '")')->fetchAll();
                $names = array_column($schema, 'name');
                if (!in_array('id', $names, true)) continue;
                $columns = array_values(array_intersect($allowed, $names));
                if (!$columns) continue;
                $qualified = '"' . $key . '"."' . $table . '"';
                $label = $source['file'] . ' / ' . $table;
                foreach ($columns as $column) {
                    $result['summary'][$key . '.' . $table . '.' . $column] = ['label' => $label . ' / ' . ($column === 'layout_config' ? 'Button / link URLs' : $column), 'matches' => 0, 'rows' => 0];
                }
                $select = $pdo->query('SELECT * FROM ' . $qualified . ' ORDER BY id');
                while ($row = $select->fetch()) {
                    $matches = 0;
                    $updates = [];
                    $params = [];
                    foreach ($columns as $column) {
                        $value = (string) ($row[$column] ?? '');
                        if ($column === 'layout_config') {
                            [$count, $replacement] = settings_replace_layout_urls($value, $find, $replace);
                        } else {
                            $count = substr_count($value, $find);
                            $replacement = str_replace($find, $replace ?? '', $value);
                        }
                        if (!$count) continue;
                        $summaryKey = $key . '.' . $table . '.' . $column;
                        $result['summary'][$summaryKey]['matches'] += $count;
                        $result['summary'][$summaryKey]['rows']++;
                        $matches += $count;
                        $updates[] = '"' . $column . '" = ?';
                        $params[] = $replacement;
                    }
                    if (!$matches) continue;
                    $result['totalMatches'] += $matches;
                    $result['totalRows']++;
                    if (count($result['matchedRows']) < 50) {
                        $result['matchedRows'][] = ['id' => (string) $row['id'], 'title' => (string) ($row['title'] ?? $row['name'] ?? $row['home_name'] ?? $row['id']), 'source' => $label, 'matches' => $matches];
                    }
                    if ($replace !== null) {
                        if (in_array('updated_at', $names, true)) { $updates[] = 'updated_at = ?'; $params[] = time(); }
                        $params[] = $row['id'];
                        $pdo->prepare('UPDATE ' . $qualified . ' SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
                    }
                }
                $select->closeCursor();
            }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    $result['changedColumns'] = count(array_filter($result['summary'], static fn (array $item): bool => $item['matches'] > 0));
    return $result;
}
