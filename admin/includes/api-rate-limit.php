<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

const BLOG_API_RATE_LIMIT_WINDOW = 60;
const BLOG_API_RATE_LIMIT_MAX_REQUESTS = 60;
const BLOG_API_TOKEN_RATE_LIMIT_MAX_REQUESTS = 240;

function api_rate_limit_path(): string
{
    $storageDir = env_value('APP_STORAGE_DIR');
    if (!is_string($storageDir) || $storageDir === '' || $storageDir === '/absolute/path/outside/public/storage') {
        $storageDir = __DIR__ . '/../storage';
    }

    return rtrim($storageDir, '/\\') . '/blog-api-rate-limit.min.json';
}

function api_rate_limit_key(string $ip, array $claims): string
{
    $subject = isset($claims['sub']) && is_string($claims['sub']) ? $claims['sub'] : 'public';
    $issuedAt = isset($claims['iat']) && is_int($claims['iat']) ? (string) $claims['iat'] : '0';

    return hash('sha256', $ip . '|' . $subject . '|' . $issuedAt);
}

function api_rate_limit_exceeded(string $key, int $maxRequests = BLOG_API_RATE_LIMIT_MAX_REQUESTS): bool
{
    $path = api_rate_limit_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return true;
    }

    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return true;
    }

    try {
        if (!flock($handle, LOCK_EX)) return true;
        $raw = stream_get_contents($handle);
        $data = json_decode($raw !== false ? $raw : '', true);
        if (!is_array($data)) {
            $data = [];
        }

        $now = time();
        foreach ($data as $storedKey => $requests) {
            if (!is_array($requests)) {
                unset($data[$storedKey]);
                continue;
            }

            $data[$storedKey] = array_values(array_filter(
                $requests,
                static fn ($timestamp): bool => is_int($timestamp) && ($now - $timestamp) < BLOG_API_RATE_LIMIT_WINDOW
            ));

            if ($data[$storedKey] === []) {
                unset($data[$storedKey]);
            }
        }

        $requests = $data[$key] ?? [];
        if (count($requests) >= $maxRequests) {
            $limited = true;
        } else {
            $requests[] = $now;
            $data[$key] = $requests;
            $limited = false;
        }

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        rewind($handle);
        if (!is_string($encoded) || fwrite($handle,$encoded) !== strlen($encoded) || !ftruncate($handle,strlen($encoded)) || !fflush($handle)) return true;
        flock($handle, LOCK_UN);

        return $limited;
    } finally {
        fclose($handle);
    }
}

function require_blog_api_rate_limit(array $claims): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = api_rate_limit_key($ip, $claims);

    if (!api_rate_limit_exceeded($key)) {
        return;
    }

    http_response_code(429);
    header('Content-Type: application/json; charset=UTF-8');
    header('Retry-After: ' . BLOG_API_RATE_LIMIT_WINDOW);
    echo json_encode(['ok' => false, 'error' => 'Too many requests.'], JSON_UNESCAPED_SLASHES);
    exit;
}

function require_blog_api_token_rate_limit(): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = hash('sha256', 'token|' . $ip);

    if (!api_rate_limit_exceeded($key, BLOG_API_TOKEN_RATE_LIMIT_MAX_REQUESTS)) {
        return;
    }

    http_response_code(429);
    header('Content-Type: application/json; charset=UTF-8');
    header('Retry-After: ' . BLOG_API_RATE_LIMIT_WINDOW);
    echo json_encode(['ok' => false, 'error' => 'Too many requests.'], JSON_UNESCAPED_SLASHES);
    exit;
}
