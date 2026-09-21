<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not found.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/blog-storage.php';
require_once __DIR__ . '/../includes/game-images.php';

const SLOTSLAUNCH_DEFAULT_GAMES_URL = 'https://slotslaunch.com/api/games';

function cli_option(string $name): ?string
{
    global $argv;

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === $name) {
            return '1';
        }

        if (str_starts_with($arg, $name . '=')) {
            return substr($arg, strlen($name) + 1);
        }
    }

    return null;
}

function slotslaunch_token(): string
{
    foreach (['SLOTSLAUNCH_API_TOKEN', 'SLOTSLAUNCH_TOKEN', 'GAMES_API_TOKEN'] as $name) {
        $value = env_value($name);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    fwrite(STDERR, "Missing SlotsLaunch token. Add SLOTSLAUNCH_API_TOKEN to .env.\n");
    exit(1);
}

function slotslaunch_games_url(string $token): string
{
    $url = cli_option('--url') ?: env_value('SLOTSLAUNCH_GAMES_API_URL') ?: SLOTSLAUNCH_DEFAULT_GAMES_URL;
    if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
        return $url;
    }

    $separator = str_contains($url, '?') ? '&' : '?';

    if (!str_contains($url, 'token=')) {
        $url .= $separator . 'token=' . rawurlencode($token);
    }

    $page = int_or_null(cli_option('--page') ?: env_value('SLOTSLAUNCH_START_PAGE'));
    if ($page !== null && $page > 0 && !preg_match('/[?&]page=/', $url)) {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'page=' . $page;
    }

    return $url;
}

function slotslaunch_max_pages(): int
{
    $maxPages = int_or_null(cli_option('--max-pages') ?: env_value('SLOTSLAUNCH_MAX_PAGES'));
    return $maxPages !== null && $maxPages > 0 ? $maxPages : 10000;
}

function http_json(string $url): array
{
    for ($attempt = 0; ; $attempt++) {
        try {
            return slotslaunch_http_json_once($url);
        } catch (RuntimeException $error) {
            if ($attempt >= 4 || !in_array($error->getCode(), [-1, 408, 429, 500, 502, 503, 504], true)) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $page = max(1, (int) ($query['page'] ?? 1));
                throw new RuntimeException($error->getMessage() . " Stopped at page {$page}; completed pages remain saved. Resume with --all --page={$page} (keep any original --url setting).", 0, $error);
            }
            $delay = 2 ** ($attempt + 1);
            fwrite(STDERR, $error->getMessage() . " Retrying the same page in {$delay}s (retry " . ($attempt + 1) . "/4).\n");
            sleep($delay);
        }
    }
}

function slotslaunch_http_json_once(string $url): array
{
    $origin = env_value('SLOTSLAUNCH_ORIGIN') ?: 'https://slotslaunch.com';
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Accept-Encoding: application/json',
        'Origin: ' . $origin,
        'Referer: ' . rtrim($origin, '/') . '/',
        'User-Agent: SlotsLaunch Importer',
    ];

    if (function_exists('curl_init') && (str_starts_with($url, 'http://') || str_starts_with($url, 'https://'))) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($status >= 400) {
            throw new RuntimeException('SlotsLaunch API returned HTTP ' . $status . '.', $status);
        }

        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('SlotsLaunch API returned an empty response or a network failure.', -1);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('SlotsLaunch API returned invalid JSON.');
        }

        if (isset($decoded['error']) && is_scalar($decoded['error'])) {
            throw new RuntimeException('SlotsLaunch API error: ' . (string) $decoded['error']);
        }

        return $decoded;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 45,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headers),
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    $responseHeaders = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
    foreach ($responseHeaders ?? [] as $header) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $match)) $status = (int) $match[1];
    }
    if ($status >= 400) throw new RuntimeException('SlotsLaunch API returned HTTP ' . $status . '.', $status);
    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException('SlotsLaunch API returned an empty response or a network failure.', -1);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('SlotsLaunch API returned invalid JSON.');
    }

    if (isset($decoded['error']) && is_scalar($decoded['error'])) {
        throw new RuntimeException('SlotsLaunch API error: ' . (string) $decoded['error']);
    }

    return $decoded;
}

function list_from_response(array $response): array
{
    if (array_is_list($response)) {
        return $response;
    }

    foreach (['games', 'data', 'items', 'results'] as $key) {
        if (isset($response[$key]) && is_array($response[$key]) && array_is_list($response[$key])) {
            return $response[$key];
        }
    }

    if (isset($response['data']) && is_array($response['data'])) {
        foreach (['games', 'items', 'results'] as $key) {
            if (isset($response['data'][$key]) && is_array($response['data'][$key]) && array_is_list($response['data'][$key])) {
                return $response['data'][$key];
            }
        }
    }

    throw new RuntimeException('Could not find a games list in the SlotsLaunch API response.');
}

function next_response_url(array $response, string $currentUrl): ?string
{
    $meta = isset($response['meta']) && is_array($response['meta']) ? $response['meta'] : $response;
    $currentPage = int_or_null(value_at($meta, ['current_page', 'page']));
    $lastPage = int_or_null(value_at($meta, ['last_page', 'total_pages', 'pages']));
    if ($currentPage !== null && $lastPage !== null) {
        if ($currentPage >= $lastPage) {
            return null;
        }

        return url_with_page($currentUrl, $currentPage + 1);
    }

    $next = value_at($response, ['next', 'next_url', 'next_page_url']);
    if (!is_string($next) && isset($response['links']) && is_array($response['links'])) {
        $next = value_at($response['links'], ['next']);
    }

    if (is_string($next) && trim($next) !== '') {
        $next = trim($next);
        if (str_starts_with($next, 'http://') || str_starts_with($next, 'https://')) {
            return $next;
        }

        $parts = parse_url($currentUrl);
        if (($parts['scheme'] ?? '') !== '' && ($parts['host'] ?? '') !== '' && str_starts_with($next, '/')) {
            return $parts['scheme'] . '://' . $parts['host'] . $next;
        }
    }

    return null;
}

function url_with_page(string $url, int $page): string
{
    if (preg_match('/([?&]page=)\d+/', $url) === 1) {
        return (string) preg_replace('/([?&]page=)\d+/', '${1}' . $page, $url);
    }

    return $url . (str_contains($url, '?') ? '&' : '?') . 'page=' . $page;
}

function fetch_all_games(string $url, ?int $limit = null, int $maxPages = 10000): array
{
    $games = [];
    $seenUrls = [];
    $currentUrl = $url;

    for ($page = 1; $page <= $maxPages && $currentUrl !== null; $page++) {
        if (isset($seenUrls[$currentUrl])) {
            break;
        }

        $seenUrls[$currentUrl] = true;
        $response = http_json($currentUrl);
        $meta = isset($response['meta']) && is_array($response['meta']) ? $response['meta'] : $response;
        $currentPageNumber = int_or_null(value_at($meta, ['current_page', 'page']));
        $lastPageNumber = int_or_null(value_at($meta, ['last_page', 'total_pages', 'pages']));
        if ($currentPageNumber !== null && $lastPageNumber !== null) {
            echo "Fetched page {$currentPageNumber} of {$lastPageNumber}.\n";
        }
        $games = array_merge($games, list_from_response($response));
        if ($limit !== null && $limit > 0 && count($games) >= $limit) {
            return array_slice($games, 0, $limit);
        }
        $currentUrl = next_response_url($response, $currentUrl);
    }

    return $games;
}

function import_fetched_games(PDO $pdo, string $url, ?int $limit = null, int $maxPages = 10000, bool $all = false): array
{
    $limit = $all ? null : max(1, min(100, $limit ?? 100));
    $seenUrls = [];
    $currentUrl = $url;
    $imported = 0;
    $skipped = 0;

    for ($page = 1; $page <= $maxPages && $currentUrl !== null; $page++) {
        if (isset($seenUrls[$currentUrl])) {
            break;
        }

        $seenUrls[$currentUrl] = true;
        $response = http_json($currentUrl);
        $meta = isset($response['meta']) && is_array($response['meta']) ? $response['meta'] : $response;
        $currentPageNumber = int_or_null(value_at($meta, ['current_page', 'page']));
        $lastPageNumber = int_or_null(value_at($meta, ['last_page', 'total_pages', 'pages']));
        if ($currentPageNumber !== null && $lastPageNumber !== null) {
            echo "Fetched page {$currentPageNumber} of {$lastPageNumber}.\n";
        }

        $games = list_from_response($response);
        if ($limit !== null && $limit > 0) {
            $remaining = $limit - ($imported + $skipped);
            if ($remaining <= 0) {
                break;
            }
            $games = array_slice($games, 0, $remaining);
        }

        $pageImported = 0;
        $pageSkipped = 0;
        $pdo->beginTransaction();
        try {
            foreach ($games as $game) {
                if (is_array($game) && import_game($pdo, $game)) {
                    $imported++;
                    $pageImported++;
                } else {
                    $skipped++;
                    $pageSkipped++;
                }
            }
            $pdo->commit();
            blog_clear_api_cache();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        echo "Saved {$pageImported} game(s) from this page.";
        if ($pageSkipped > 0) {
            echo " Skipped {$pageSkipped}.";
        }
        echo "\n";

        if ($limit !== null && $limit > 0 && ($imported + $skipped) >= $limit) {
            break;
        }

        $currentUrl = next_response_url($response, $currentUrl);
    }

    return ['imported' => $imported, 'skipped' => $skipped];
}

function value_at(array $data, array $keys, mixed $default = null): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
            return $data[$key];
        }
    }

    return $default;
}

function int_or_null(mixed $value): ?int
{
    if (is_int($value)) {
        return $value;
    }

    if (is_float($value)) {
        return (int) $value;
    }

    if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
        return (int) trim($value);
    }

    return null;
}

function float_or_null(mixed $value): ?float
{
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }

    if (is_string($value)) {
        $clean = trim(str_replace(['%', ','], '', $value));
        if (is_numeric($clean)) {
            return (float) $clean;
        }
    }

    return null;
}

function bool_int(mixed $value): int
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    if (is_int($value) || is_float($value)) {
        return ((int) $value) !== 0 ? 1 : 0;
    }

    if (is_string($value)) {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'on'], true) ? 1 : 0;
    }

    return 0;
}

function string_value(mixed $value): string
{
    if (is_scalar($value)) {
        return trim((string) $value);
    }

    return '';
}

function list_value(mixed $value): array
{
    if (!is_array($value)) {
        $text = string_value($value);
        return $text === '' ? [] : [$text];
    }

    $items = [];
    foreach ($value as $item) {
        if (is_array($item)) {
            $name = string_value(value_at($item, ['name', 'title', 'slug', 'id']));
            if ($name !== '') {
                $items[] = $name;
            }
            continue;
        }

        $name = string_value($item);
        if ($name !== '') {
            $items[] = $name;
        }
    }

    return array_values(array_unique($items));
}

function json_list(mixed $value): string
{
    return json_encode(list_value($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function derived_api_id(string $name): int
{
    return (int) sprintf('%u', crc32(strtolower($name)));
}

function upsert_lookup(PDO $pdo, string $table, mixed $source, array $idKeys = ['id', 'api_id'], array $nameKeys = ['name', 'title']): ?int
{
    if (is_string($source)) {
        $name = trim($source);
        $apiId = $name !== '' ? derived_api_id($name) : null;
    } elseif (is_array($source)) {
        $name = string_value(value_at($source, $nameKeys));
        $apiId = int_or_null(value_at($source, $idKeys));
        if ($apiId === null && $name !== '') {
            $apiId = derived_api_id($name);
        }
    } else {
        return null;
    }

    if ($name === '' || $apiId === null) {
        return null;
    }

    $now = time();
    $stmt = $pdo->prepare(
        "INSERT INTO {$table} (api_id, name, created_at, updated_at)
         VALUES (:api_id, :name, :created_at, :updated_at)
         ON CONFLICT(api_id) DO UPDATE SET
            name = excluded.name,
            updated_at = excluded.updated_at"
    );
    $stmt->execute([
        ':api_id' => $apiId,
        ':name' => $name,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return (int) $pdo->query("SELECT id FROM {$table} WHERE api_id = " . (int) $apiId)->fetchColumn();
}

function normalize_provider(array $game): array
{
    $provider = value_at($game, ['provider']);
    if (is_array($provider)) {
        $name = string_value(value_at($provider, ['name', 'title']));
        return [
            'source' => $provider,
            'name' => $name,
            'slug' => string_value(value_at($provider, ['slug'])) ?: normalize_slug($name),
        ];
    }

    $name = string_value(value_at($game, ['provider', 'provider_name']));
    return [
        'source' => [
            'id' => value_at($game, ['provider_id', 'provider_api_id']),
            'name' => $name,
        ],
        'name' => $name,
        'slug' => string_value(value_at($game, ['provider_slug'])) ?: normalize_slug($name),
    ];
}

function normalize_type(array $game): array
{
    $type = value_at($game, ['type', 'category']);
    if (is_array($type)) {
        $name = string_value(value_at($type, ['name', 'title']));
        return [
            'source' => $type,
            'name' => $name,
            'slug' => string_value(value_at($type, ['slug'])) ?: normalize_slug($name),
        ];
    }

    $name = string_value(value_at($game, ['type', 'type_name', 'category']));
    return [
        'source' => [
            'id' => value_at($game, ['type_id', 'type_api_id', 'category_id']),
            'name' => $name,
        ],
        'name' => $name,
        'slug' => string_value(value_at($game, ['type_slug'])) ?: normalize_slug($name),
    ];
}

function normalize_themes(array $game): array
{
    $rawThemes = value_at($game, ['themes', 'theme'], []);
    if (!is_array($rawThemes)) {
        $rawThemes = string_value($rawThemes) === '' ? [] : [$rawThemes];
    }

    $themes = [];
    foreach ($rawThemes as $theme) {
        if (is_array($theme)) {
            $name = string_value(value_at($theme, ['name', 'title']));
            if ($name !== '') {
                $themes[] = [
                    'source' => $theme,
                    'name' => $name,
                ];
            }
            continue;
        }

        $name = string_value($theme);
        if ($name !== '') {
            $themes[] = [
                'source' => ['id' => derived_api_id($name), 'name' => $name],
                'name' => $name,
            ];
        }
    }

    return $themes;
}

function import_game(PDO $pdo, array $game, ?callable $imageDownload = null): bool
{
    $apiId = int_or_null(value_at($game, ['id', 'api_id', 'game_id']));
    $name = string_value(value_at($game, ['name', 'title']));

    if ($apiId === null || $name === '') {
        return false;
    }

    $source = string_value(value_at($game, ['thumb', 'thumbnail', 'image', 'icon']));
    $existing = $pdo->prepare('SELECT thumb FROM games WHERE api_id=?'); $existing->execute([$apiId]);
    $oldThumb = (string)($existing->fetchColumn() ?: '');
    $imageFailed = false;
    try { $localThumb = game_image_localize($apiId, $source, $imageDownload); }
    catch (Throwable $error) {
        $localThumb = game_image_local_valid($oldThumb) ? $oldThumb : '';
        $imageFailed = true;
        game_image_failure_log($apiId, $name, $source, $error->getMessage());
    }

    $provider = normalize_provider($game);
    $type = normalize_type($game);
    $providerId = upsert_lookup($pdo, 'game_providers', $provider['source']);
    $typeId = upsert_lookup($pdo, 'game_types', $type['source']);
    $themeRows = normalize_themes($game);
    $themeNames = array_map(static fn (array $theme): string => $theme['name'], $themeRows);
    $now = time();

    $data = [
        ':api_id' => $apiId,
        ':name' => $name,
        ':slug' => string_value(value_at($game, ['slug'])) ?: normalize_slug($name . '-' . $apiId),
        ':url' => string_value(value_at($game, ['url', 'game_url', 'launch_url', 'iframe_url'])),
        ':thumb' => $localThumb,
        ':short_description' => string_value(value_at($game, ['short_description', 'description', 'excerpt', 'summary'])),
        ':long_description' => string_value(value_at($game, ['long_description', 'full_description', 'content', 'overview'])),
        ':provider_id' => $providerId,
        ':provider' => $provider['name'],
        ':provider_slug' => $provider['slug'],
        ':type_id' => $typeId,
        ':type' => $type['name'],
        ':type_slug' => $type['slug'],
        ':themes' => json_encode(array_values(array_unique($themeNames)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':megaways' => bool_int(value_at($game, ['megaways'])),
        ':bonus_buy' => bool_int(value_at($game, ['bonus_buy', 'buy_bonus'])),
        ':progressive' => bool_int(value_at($game, ['progressive', 'jackpot'])),
        ':featured' => bool_int(value_at($game, ['featured'])),
        ':release' => string_value(value_at($game, ['release', 'released', 'release_date'])),
        ':reels' => string_value(value_at($game, ['reels'])),
        ':rtp' => float_or_null(value_at($game, ['rtp'])),
        ':volatility' => string_value(value_at($game, ['volatility', 'variance'])),
        ':currencies' => json_list(value_at($game, ['currencies'])),
        ':languages' => json_list(value_at($game, ['languages', 'langs'])),
        ':land_based' => bool_int(value_at($game, ['land_based'])),
        ':markets' => json_list(value_at($game, ['markets'])),
        ':paylines' => string_value(value_at($game, ['paylines', 'lines'])),
        ':max_exposure' => string_value(value_at($game, ['max_exposure'])),
        ':min_bet' => float_or_null(value_at($game, ['min_bet', 'minimum_bet'])),
        ':max_bet' => float_or_null(value_at($game, ['max_bet', 'maximum_bet'])),
        ':max_win_per_spin' => float_or_null(value_at($game, ['max_win_per_spin', 'max_win'])),
        ':autoplay' => bool_int(value_at($game, ['autoplay'])),
        ':quickspin' => bool_int(value_at($game, ['quickspin', 'quick_spin'])),
        ':tumbling_reels' => bool_int(value_at($game, ['tumbling_reels', 'tumble'])),
        ':increasing_multipliers' => bool_int(value_at($game, ['increasing_multipliers', 'increasing_multiplier'])),
        ':orientation' => string_value(value_at($game, ['orientation'])),
        ':restrictions' => json_list(value_at($game, ['restrictions'])),
        ':upcoming' => bool_int(value_at($game, ['upcoming'])),
        ':published' => bool_int(value_at($game, ['published', 'active', 'enabled'])),
        ':created_at' => $now,
        ':updated_at' => $now,
    ];

    $sql = 'INSERT INTO games (
            api_id, name, slug, url, thumb, short_description, long_description, provider_id, provider, provider_slug,
            type_id, type, type_slug, themes, megaways, bonus_buy, progressive, featured,
            release, reels, rtp, volatility, currencies, languages, land_based, markets,
            paylines, max_exposure, min_bet, max_bet, max_win_per_spin, autoplay, quickspin,
            tumbling_reels, increasing_multipliers, orientation, restrictions, upcoming,
            published, created_at, updated_at
        ) VALUES (
            :api_id, :name, :slug, :url, :thumb, :short_description, :long_description, :provider_id, :provider, :provider_slug,
            :type_id, :type, :type_slug, :themes, :megaways, :bonus_buy, :progressive, :featured,
            :release, :reels, :rtp, :volatility, :currencies, :languages, :land_based, :markets,
            :paylines, :max_exposure, :min_bet, :max_bet, :max_win_per_spin, :autoplay, :quickspin,
            :tumbling_reels, :increasing_multipliers, :orientation, :restrictions, :upcoming,
            :published, :created_at, :updated_at
        )
        ON CONFLICT(api_id) DO UPDATE SET
            name = excluded.name,
            slug = excluded.slug,
            url = excluded.url,
            thumb = excluded.thumb,
            short_description = COALESCE(NULLIF(excluded.short_description, ""), games.short_description),
            long_description = COALESCE(NULLIF(excluded.long_description, ""), games.long_description),
            provider_id = excluded.provider_id,
            provider = excluded.provider,
            provider_slug = excluded.provider_slug,
            type_id = excluded.type_id,
            type = excluded.type,
            type_slug = excluded.type_slug,
            themes = excluded.themes,
            megaways = excluded.megaways,
            bonus_buy = excluded.bonus_buy,
            progressive = excluded.progressive,
            featured = excluded.featured,
            release = excluded.release,
            reels = excluded.reels,
            rtp = COALESCE(excluded.rtp, games.rtp),
            volatility = COALESCE(NULLIF(excluded.volatility, ""), games.volatility),
            currencies = excluded.currencies,
            languages = excluded.languages,
            land_based = excluded.land_based,
            markets = excluded.markets,
            paylines = excluded.paylines,
            max_exposure = excluded.max_exposure,
            min_bet = excluded.min_bet,
            max_bet = excluded.max_bet,
            max_win_per_spin = excluded.max_win_per_spin,
            autoplay = excluded.autoplay,
            quickspin = excluded.quickspin,
            tumbling_reels = excluded.tumbling_reels,
            increasing_multipliers = excluded.increasing_multipliers,
            orientation = excluded.orientation,
            restrictions = excluded.restrictions,
            upcoming = excluded.upcoming,
            published = excluded.published,
            updated_at = excluded.updated_at';

    $pdo->prepare($sql)->execute($data);
    if ($imageFailed) $pdo->prepare('UPDATE games SET done_processing=0 WHERE api_id=?')->execute([$apiId]);

    $gameId = (int) $pdo->query('SELECT id FROM games WHERE api_id = ' . (int) $apiId)->fetchColumn();
    $pdo->prepare('DELETE FROM game_theme_links WHERE game_id = :game_id')->execute([':game_id' => $gameId]);
    $linkStmt = $pdo->prepare('INSERT OR IGNORE INTO game_theme_links (game_id, theme_id) VALUES (:game_id, :theme_id)');
    foreach ($themeRows as $theme) {
        $themeId = upsert_lookup($pdo, 'game_themes', $theme['source']);
        if ($themeId !== null) {
            $linkStmt->execute([':game_id' => $gameId, ':theme_id' => $themeId]);
        }
    }

    return true;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;

$dryRun = cli_option('--dry-run') !== null;
$all = cli_option('--all') !== null;
if ($all && cli_option('--limit') !== null) { fwrite(STDERR, "Use either --all or --limit, not both.\n"); exit(1); }
$limit = $all ? null : max(1, min(100, int_or_null(cli_option('--limit')) ?? 100));
$token = slotslaunch_token();
$url = slotslaunch_games_url($token);
$maxPages = slotslaunch_max_pages();

echo "Fetching SlotsLaunch games...\n";
try {
if ($dryRun) {
    $games = fetch_all_games($url, $limit, $maxPages);
    echo 'Found ' . count($games) . " game(s).\n";
    echo "Dry run complete. No database changes were made.\n";
    exit(0);
}

$pdo = blogs_pdo();
$result = import_fetched_games($pdo, $url, $limit, $maxPages, $all);
$imported = $result['imported'];
$skipped = $result['skipped'];

echo "Imported {$imported} game(s).";
if ($skipped > 0) {
    echo " Skipped {$skipped} invalid row(s).";
}
echo "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Import stopped: ' . $error->getMessage() . "\n");
    if (isset($pdo)) {
        try { game_visibility_refresh(); }
        catch (Throwable $refreshError) { fwrite(STDERR, $refreshError->getMessage() . "\n"); }
    }
    exit(1);
}
if (isset($pdo)) {
    try { game_visibility_refresh(); }
    catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
