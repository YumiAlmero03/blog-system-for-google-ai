<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/blog-storage.php';

const PROVIDER_LIST_CACHE_TTL = 300;

function provider_list_cache_dir(): string
{
    return blog_storage_dir() . '/api-cache';
}

function provider_list_count_limit(): ?int
{
    $rawLimit = $_GET['count'] ?? $_GET['limit'] ?? null;
    if ($rawLimit === null || $rawLimit === '') {
        return null;
    }

    $limit = filter_var($rawLimit, FILTER_VALIDATE_INT, [
        'options' => [
            'min_range' => 1,
            'max_range' => 1000,
        ],
    ]);

    return is_int($limit) ? $limit : null;
}

function provider_list_bool_param(string $key): bool
{
    $value = $_GET[$key] ?? null;
    if (!is_scalar($value)) {
        return false;
    }

    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

function provider_list_use_sample(): bool
{
    return provider_list_bool_param('sample') || provider_list_bool_param('dev');
}

function provider_list_cache_key(?int $limit, bool $sample): string
{
    $dbMtime = is_file(blogs_db_path()) ? (int) @filemtime(blogs_db_path()) : 0;
    return hash('sha256', 'provider-list-v5:' . $dbMtime . ':count=' . ($limit ?? 'all') . ':sample=' . ($sample ? '1' : '0'));
}

function provider_list_cache_path(string $key): string
{
    return provider_list_cache_dir() . '/' . $key . '.json';
}

function provider_list_cache_read(string $key): ?string
{
    $path = provider_list_cache_path($key);
    if (!is_file($path) || (time() - (int) @filemtime($path)) > PROVIDER_LIST_CACHE_TTL) {
        return null;
    }

    $cached = @file_get_contents($path);
    return is_string($cached) && $cached !== '' ? $cached : null;
}

function provider_list_cache_write(string $key, string $payload): void
{
    $dir = provider_list_cache_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return;
    }

    $denyFile = $dir . '/.htaccess';
    if (!is_file($denyFile)) {
        @file_put_contents($denyFile, "Require all denied\n", LOCK_EX);
    }

    @file_put_contents(provider_list_cache_path($key), $payload, LOCK_EX);
}

function provider_list_item(array $provider): array
{
    $name = (string) ($provider['name'] ?? '');
    $slug = (string) ($provider['slug'] ?? '');
    if ($slug === '') {
        $slug = normalize_slug($name);
    }

    return [
        'id' => $slug !== '' ? $slug : 'provider-' . (int) ($provider['id'] ?? 0),
        'apiId' => (int) ($provider['api_id'] ?? 0),
        'name' => $name,
        'slug' => $slug,
        'thumbnail' => (string) ($provider['thumbnail'] ?? ''),
        'gameCount' => (int) ($provider['game_count'] ?? 0),
        'updatedAt' => (int) ($provider['updated_at'] ?? 0),
    ];
}

function provider_list_rows(PDO $pdo): array
{
    $providersBySlug = [];

    $eligible = game_public_eligibility_sql('g');
    $providerStmt = $pdo->query(
        'SELECT gp.id, gp.api_id, gp.name, "" AS slug, gp.thumbnail, gp.updated_at,
                COUNT(g.id) AS game_count
         FROM game_providers gp
         LEFT JOIN games g ON (' . $eligible . ') AND (
            (g.provider_id = gp.id OR LOWER(g.provider) = LOWER(gp.name))
            AND g.published = 1
         )
         WHERE gp.name <> ""
         GROUP BY gp.id HAVING COUNT(g.id) > 0'
    );

    foreach ($providerStmt->fetchAll() as $provider) {
        $item = provider_list_item($provider);
        if ($item['name'] === '' || $item['slug'] === '') {
            continue;
        }

        $providersBySlug[$item['slug']] = $item;
    }

    $gameProviderStmt = $pdo->query(
        'SELECT MIN(id) AS id, 0 AS api_id, provider AS name, provider_slug AS slug, "" AS thumbnail,
                MAX(updated_at) AS updated_at, COUNT(id) AS game_count
         FROM games
         WHERE (' . game_public_eligibility_sql() . ') AND provider <> ""
         GROUP BY provider_slug, provider'
    );

    foreach ($gameProviderStmt->fetchAll() as $provider) {
        $item = provider_list_item($provider);
        if ($item['name'] === '' || $item['slug'] === '') {
            continue;
        }

        if (!isset($providersBySlug[$item['slug']])) {
            $providersBySlug[$item['slug']] = $item;
            continue;
        }

        $existing = $providersBySlug[$item['slug']];
        $providersBySlug[$item['slug']] = [
            ...$existing,
            'gameCount' => max((int) $existing['gameCount'], (int) $item['gameCount']),
            'updatedAt' => max((int) $existing['updatedAt'], (int) $item['updatedAt']),
        ];
    }

    $providers = array_values($providersBySlug);
    usort($providers, static function (array $a, array $b): int {
        $countCompare = ((int) $b['gameCount']) <=> ((int) $a['gameCount']);
        if ($countCompare !== 0) {
            return $countCompare;
        }

        return strcasecmp((string) $a['name'], (string) $b['name']);
    });

    return $providers;
}

function provider_list_sample_rows(): array
{
    $names = [
        'JILI',
        'PG Soft',
        'Pragmatic Play',
        'KA Gaming',
        'Evolution',
        'Ezugi',
        'Spadegaming',
        'Playtech',
        'CQ9',
        'Hacksaw',
        'NoLimit City',
        'Relax Gaming',
        'NetEnt',
        'Microgaming',
        'Spribe',
        'Kingmaker',
        'Big Time Gaming',
        'Playstar',
        'SimplePlay',
        'Yggdrasil',
    ];

    return array_map(static function (string $name, int $index): array {
        $slug = normalize_slug($name);
        $label = rawurlencode($name);

        return [
            'id' => $slug,
            'apiId' => 900000 + $index,
            'name' => $name,
            'slug' => $slug,
            'thumbnail' => 'https://placehold.co/360x160/120b06/f59e0b?text=' . $label,
            'gameCount' => max(12, 240 - ($index * 9)),
            'updatedAt' => time(),
        ];
    }, $names, array_keys($names));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    header('X-Robots-Tag: noindex, nofollow');
    exit;
}

header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=' . PROVIDER_LIST_CACHE_TTL);

try {
    $limit = provider_list_count_limit();
    $sample = provider_list_use_sample();
    $cacheKey = provider_list_cache_key($limit, $sample);
    $cachedPayload = provider_list_cache_read($cacheKey);
    if ($cachedPayload !== null) {
        header('X-API-Cache: HIT');
        echo $cachedPayload;
        exit;
    }

    $providers = $sample ? provider_list_sample_rows() : provider_list_rows(blogs_pdo());
    $total = count($providers);
    if ($limit !== null) {
        $providers = array_slice($providers, 0, $limit);
    }

    $payload = json_encode([
        'ok' => true,
        'providers' => $providers,
        'total' => $total,
        'count' => count($providers),
        'limit' => $limit,
        'sample' => $sample,
    ], JSON_UNESCAPED_SLASHES);

    if (!is_string($payload)) {
        throw new RuntimeException('Provider response encoding failed.');
    }

    provider_list_cache_write($cacheKey, $payload);
    header('X-API-Cache: MISS');
    echo $payload;
} catch (Throwable $exception) {
    error_log('Provider list storage error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Provider list is unavailable.'], JSON_UNESCAPED_SLASHES);
}
