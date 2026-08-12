<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

const LOGIN_RATE_LIMIT_WINDOW = 900;
const LOGIN_RATE_LIMIT_MAX_FAILURES = 5;

function login_client_ip(): string
{
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR',
    ];

    foreach ($headers as $header) {
        $value = $_SERVER[$header] ?? '';
        if (!is_string($value) || $value === '') {
            continue;
        }

        $ip = trim(explode(',', $value)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return 'unknown';
}

function login_attempts_path(): string
{
    $storageDir = env_value('APP_STORAGE_DIR');
    if (!is_string($storageDir) || $storageDir === '' || $storageDir === '/absolute/path/outside/public/storage') {
        $storageDir = dirname(__DIR__) . '/storage';
    }

    return rtrim($storageDir, '/\\') . '/login-attempts.min.json';
}

function login_rate_key(string $username, string $ip): string
{
    return hash('sha256', strtolower(trim($username)) . '|' . $ip);
}

function login_attempts_update(string $key, callable $callback): mixed
{
    $path = login_attempts_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }

    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return false;
    }

    try {
        flock($handle, LOCK_EX);
        $raw = stream_get_contents($handle);
        $data = json_decode($raw !== false ? $raw : '', true);
        if (!is_array($data)) {
            $data = [];
        }

        $now = time();
        foreach ($data as $storedKey => $attempts) {
            if (!is_array($attempts)) {
                unset($data[$storedKey]);
                continue;
            }
            $data[$storedKey] = array_values(array_filter($attempts, static fn ($ts): bool => is_int($ts) && ($now - $ts) <= LOGIN_RATE_LIMIT_WINDOW));
            if ($data[$storedKey] === []) {
                unset($data[$storedKey]);
            }
        }

        $result = $callback($data);

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($handle);
        flock($handle, LOCK_UN);

        return $result;
    } finally {
        fclose($handle);
    }
}

function login_rate_limited(string $username, string $ip): bool
{
    $key = login_rate_key($username, $ip);
    return (bool) login_attempts_update($key, static function (array &$data) use ($key): bool {
        return count($data[$key] ?? []) >= LOGIN_RATE_LIMIT_MAX_FAILURES;
    });
}

function login_retry_after_seconds(string $username, string $ip): int
{
    $key = login_rate_key($username, $ip);
    return (int) login_attempts_update($key, static function (array &$data) use ($key): int {
        $attempts = $data[$key] ?? [];
        if (count($attempts) < LOGIN_RATE_LIMIT_MAX_FAILURES) {
            return 0;
        }

        $oldest = min($attempts);
        return max(1, LOGIN_RATE_LIMIT_WINDOW - (time() - $oldest));
    });
}

function login_record_failure(string $username, string $ip): void
{
    $key = login_rate_key($username, $ip);
    login_attempts_update($key, static function (array &$data) use ($key): void {
        $data[$key] ??= [];
        $data[$key][] = time();
        $data[$key] = array_slice($data[$key], -LOGIN_RATE_LIMIT_MAX_FAILURES);
    });
}

function login_clear_failures(string $username, string $ip): void
{
    $key = login_rate_key($username, $ip);
    login_attempts_update($key, static function (array &$data) use ($key): void {
        unset($data[$key]);
    });
}
