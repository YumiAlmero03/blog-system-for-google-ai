<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not found.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/blog-storage.php';

const SLOTSLAUNCH_DEFAULT_PROVIDERS_URL = 'https://slotslaunch.com/api/providers';

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

function providers_token(): string
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

function providers_url(string $token): string
{
    $url = cli_option('--url') ?: env_value('SLOTSLAUNCH_PROVIDERS_API_URL') ?: SLOTSLAUNCH_DEFAULT_PROVIDERS_URL;
    if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
        return $url;
    }

    if (!str_contains($url, 'token=')) {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'token=' . rawurlencode($token);
    }

    $page = int_or_null(cli_option('--page'));
    if ($page !== null && $page > 0 && !preg_match('/[?&]page=/', $url)) {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'page=' . $page;
    }

    return $url;
}

function http_json(string $url): array
{
    $origin = env_value('SLOTSLAUNCH_ORIGIN') ?: 'https://slotslaunch.com';
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Accept-Encoding: application/json',
        'Origin: ' . $origin,
        'Referer: ' . rtrim($origin, '/') . '/',
        'User-Agent: SlotsLaunch Provider Importer',
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
        $error = curl_error($ch);

        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('SlotsLaunch providers API returned an empty response.' . ($error !== '' ? ' cURL error: ' . $error : ''));
        }

        if ($status >= 400) {
            throw new RuntimeException('SlotsLaunch providers API returned HTTP ' . $status . ': ' . substr($raw, 0, 300));
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 45,
                'header' => implode("\r\n", $headers),
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('SlotsLaunch providers API returned an empty response.');
        }
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('SlotsLaunch providers API returned invalid JSON: ' . substr($raw, 0, 300));
    }

    if (isset($decoded['error']) && is_scalar($decoded['error'])) {
        throw new RuntimeException('SlotsLaunch providers API error: ' . (string) $decoded['error']);
    }

    return $decoded;
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

function string_value(mixed $value): string
{
    return is_scalar($value) ? trim((string) $value) : '';
}

function providers_from_response(array $response): array
{
    if (array_is_list($response)) {
        return $response;
    }

    foreach (['providers', 'data', 'items', 'results'] as $key) {
        if (isset($response[$key]) && is_array($response[$key]) && array_is_list($response[$key])) {
            return $response[$key];
        }
    }

    throw new RuntimeException('Could not find providers list in API response.');
}

function url_with_page(string $url, int $page): string
{
    if (preg_match('/([?&]page=)\d+/', $url) === 1) {
        return (string) preg_replace('/([?&]page=)\d+/', '${1}' . $page, $url);
    }

    return $url . (str_contains($url, '?') ? '&' : '?') . 'page=' . $page;
}

function next_response_url(array $response, string $currentUrl): ?string
{
    $meta = isset($response['meta']) && is_array($response['meta']) ? $response['meta'] : $response;
    $currentPage = int_or_null(value_at($meta, ['current_page', 'page']));
    $lastPage = int_or_null(value_at($meta, ['last_page', 'total_pages', 'pages']));
    if ($currentPage !== null && $lastPage !== null) {
        return $currentPage >= $lastPage ? null : url_with_page($currentUrl, $currentPage + 1);
    }

    $next = value_at($response, ['next', 'next_url', 'next_page_url']);
    if (!is_string($next) && isset($response['links']) && is_array($response['links'])) {
        $next = value_at($response['links'], ['next']);
    }
    if (is_string($next) && trim($next) !== '') {
        return trim($next);
    }

    return null;
}

function import_provider(PDO $pdo, array $provider): bool
{
    $apiId = int_or_null(value_at($provider, ['id', 'api_id', 'provider_id']));
    $name = string_value(value_at($provider, ['name', 'title']));
    if ($apiId === null || $name === '') {
        return false;
    }

    $thumbnail = string_value(value_at($provider, ['thumbnail', 'thumb', 'image', 'logo', 'icon']));
    $now = time();
    $stmt = $pdo->prepare(
        'INSERT INTO game_providers (api_id, name, thumbnail, created_at, updated_at)
         VALUES (:api_id, :name, :thumbnail, :created_at, :updated_at)
         ON CONFLICT(api_id) DO UPDATE SET
            name = excluded.name,
            thumbnail = COALESCE(NULLIF(excluded.thumbnail, ""), game_providers.thumbnail),
            updated_at = excluded.updated_at'
    );
    $stmt->execute([
        ':api_id' => $apiId,
        ':name' => $name,
        ':thumbnail' => $thumbnail,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return true;
}

function import_providers(PDO $pdo, string $url): array
{
    $seenUrls = [];
    $imported = 0;
    $skipped = 0;
    $currentUrl = $url;

    while ($currentUrl !== null && !isset($seenUrls[$currentUrl])) {
        $seenUrls[$currentUrl] = true;
        $response = http_json($currentUrl);
        $providers = providers_from_response($response);

        $pageImported = 0;
        $pageSkipped = 0;
        $pdo->beginTransaction();
        try {
            foreach ($providers as $provider) {
                if (is_array($provider) && import_provider($pdo, $provider)) {
                    $imported++;
                    $pageImported++;
                } else {
                    $skipped++;
                    $pageSkipped++;
                }
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        echo 'Saved ' . $pageImported . ' provider(s).';
        if ($pageSkipped > 0) {
            echo ' Skipped ' . $pageSkipped . '.';
        }
        echo "\n";

        $currentUrl = next_response_url($response, $currentUrl);
    }

    return ['imported' => $imported, 'skipped' => $skipped];
}

$token = providers_token();
$url = providers_url($token);

echo "Fetching SlotsLaunch providers...\n";
$result = import_providers(blogs_pdo(), $url);
echo 'Imported ' . $result['imported'] . ' provider(s).';
if ($result['skipped'] > 0) {
    echo ' Skipped ' . $result['skipped'] . ' invalid row(s).';
}
echo "\n";
