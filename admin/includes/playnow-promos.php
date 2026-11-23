<?php
declare(strict_types=1);

require_once __DIR__ . '/blog-storage.php';

function playnow_promo_path(): string
{
    return blog_storage_dir() . '/playnow-clicks.json';
}

function playnow_promo_code_valid(string $code): bool
{
    return preg_match('/^[A-Za-z0-9_-]{8,64}$/', $code) === 1;
}

function playnow_promo_normalize_code(string $code): string
{
    return strtolower(trim($code));
}

function playnow_promo_clean(mixed $value, int $maxLength): string
{
    $value = trim((string) $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
}

function playnow_promo_target(mixed $value): string
{
    $target = playnow_promo_clean($value, 500);
    return preg_match('/^(?:https?:\/\/|\/)[^\s<>"\']+$/i', $target) === 1 ? $target : '';
}

function playnow_promo_data(): array
{
    $path = playnow_promo_path();
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw !== false ? $raw : '', true);
    return is_array($data) ? $data : [];
}

function playnow_promo_write(callable $mutator): array
{
    $path = playnow_promo_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Promo storage is unavailable.');
    }
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Promo storage is unavailable.');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Promo storage is busy.');
        }
        rewind($handle);
        $raw = stream_get_contents($handle);
        $data = json_decode($raw !== false ? $raw : '', true);
        $data = is_array($data) ? $data : [];
        $result = $mutator($data);
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($handle);
        flock($handle, LOCK_UN);
        return is_array($result) ? $result : [];
    } finally {
        fclose($handle);
    }
}

function playnow_promo_find(string $code): ?array
{
    if (!playnow_promo_code_valid($code)) {
        return null;
    }
    $data = playnow_promo_data();
    $promo = $data['promos'][$code] ?? null;
    return is_array($promo) ? $promo : null;
}

function playnow_promo_create(string $targetUrl, string $ogTitle, string $ogDescription, string $ogImage, string $requestedCode = ''): array
{
    $targetUrl = playnow_promo_target($targetUrl);
    if ($targetUrl === '') {
        throw new InvalidArgumentException('Enter a valid destination URL or site path.');
    }
    $requestedCode = playnow_promo_normalize_code($requestedCode);
    if ($requestedCode !== '' && !playnow_promo_code_valid($requestedCode)) {
        throw new InvalidArgumentException('Promo code must be 8-64 characters using only letters, numbers, hyphens, or underscores.');
    }
    return playnow_promo_write(static function (array &$data) use ($targetUrl, $ogTitle, $ogDescription, $ogImage, $requestedCode): array {
        $data['promos'] ??= [];
        $code = $requestedCode;
        if ($code !== '' && isset($data['promos'][$code])) {
            throw new InvalidArgumentException('That promo code already exists.');
        }
        do {
            $code = $code !== '' ? $code : strtolower(bin2hex(random_bytes(6)));
        } while (isset($data['promos'][$code]));
        $data['promos'][$code] = [
            'code' => $code,
            'targetUrl' => $targetUrl,
            'ogTitle' => playnow_promo_clean($ogTitle, 180),
            'ogDescription' => playnow_promo_clean($ogDescription, 300),
            'ogImage' => playnow_promo_clean($ogImage, 500),
            'clicks' => 0,
            'lastClickAt' => '',
            'createdAt' => gmdate('c'),
        ];
        return $data['promos'][$code];
    });
}

function playnow_promo_update(string $code, string $targetUrl, string $ogTitle, string $ogDescription, string $ogImage, string $newCode = ''): bool
{
    $code = playnow_promo_normalize_code($code);
    $newCode = playnow_promo_normalize_code($newCode);
    $targetUrl = playnow_promo_target($targetUrl);
    if (!playnow_promo_code_valid($code)) {
        throw new InvalidArgumentException('Promo code must be 8-64 characters using only letters, numbers, hyphens, or underscores.');
    }
    if ($newCode !== '' && !playnow_promo_code_valid($newCode)) {
        throw new InvalidArgumentException('Promo code must be 8-64 characters using only letters, numbers, hyphens, or underscores.');
    }
    if ($targetUrl === '') {
        throw new InvalidArgumentException('Enter a valid destination URL or site path.');
    }
    playnow_promo_write(static function (array &$data) use ($code, $newCode, $targetUrl, $ogTitle, $ogDescription, $ogImage): array {
        if (!isset($data['promos'][$code]) || !is_array($data['promos'][$code])) {
            return [];
        }
        if ($newCode !== '' && $newCode !== $code && isset($data['promos'][$newCode])) {
            throw new InvalidArgumentException('That promo code already exists.');
        }
        $data['promos'][$code]['targetUrl'] = $targetUrl;
        $data['promos'][$code]['ogTitle'] = playnow_promo_clean($ogTitle, 180);
        $data['promos'][$code]['ogDescription'] = playnow_promo_clean($ogDescription, 300);
        $data['promos'][$code]['ogImage'] = playnow_promo_clean($ogImage, 500);
        if ($newCode !== '' && $newCode !== $code) {
            $data['promos'][$newCode] = $data['promos'][$code];
            $data['promos'][$newCode]['code'] = $newCode;
            unset($data['promos'][$code]);
            return $data['promos'][$newCode];
        }
        return $data['promos'][$code];
    });
    return true;
}

function playnow_promo_record_click(string $code): ?array
{
    if (!playnow_promo_code_valid($code)) {
        return null;
    }
    $ip = function_exists('blog_request_ip') ? blog_request_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip !== '' && function_exists('blog_ip_is_ignored') && blog_ip_is_ignored($ip)) {
        return playnow_promo_find($code);
    }
    return playnow_promo_write(static function (array &$data) use ($code, $ip): array {
        if (!isset($data['promos'][$code]) || !is_array($data['promos'][$code])) {
            return [];
        }
        $time = gmdate('c');
        $promo =& $data['promos'][$code];
        $promo['clicks'] = (int) ($promo['clicks'] ?? 0) + 1;
        $promo['lastClickAt'] = $time;
        $targetUrl = (string) ($promo['targetUrl'] ?? '');
        $data['totalClicks'] = (int) ($data['totalClicks'] ?? 0) + 1;
        $data['updatedAt'] = $time;
        $data['byPage'] ??= [];
        $data['byButton'] ??= [];
        $data['byTarget'] ??= [];
        $data['recent'] ??= [];
        $data['byLocation'] ??= [];
        $data['byIp'] ??= [];
        $data['byPage']['/promo-code/' . $code] = (int) ($data['byPage']['/promo-code/' . $code] ?? 0) + 1;
        $data['byButton']['promo-link'] = (int) ($data['byButton']['promo-link'] ?? 0) + 1;
        $data['byTarget'][$targetUrl] = (int) ($data['byTarget'][$targetUrl] ?? 0) + 1;
        $data['byLocation']['/promo-code/' . $code] = (int) ($data['byLocation']['/promo-code/' . $code] ?? 0) + 1;
        $recordedIp = $ip !== '' ? $ip : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ($recordedIp !== 'unknown') {
            $data['byIp'][$recordedIp] = (int) ($data['byIp'][$recordedIp] ?? 0) + 1;
        }
        $data['recent'][] = [
            'time' => $time,
            'timestamp' => time(),
            'ip' => $recordedIp,
            'pagePath' => '/promo-code/' . $code,
            'pageUrl' => '',
            'pageTitle' => $promo['ogTitle'] ?? '',
            'referrer' => $_SERVER['HTTP_REFERER'] ?? '',
            'targetUrl' => $targetUrl,
            'buttonText' => 'promo-link',
            'section' => 'promo-code',
            'location' => '/promo-code/' . $code,
            'promoCode' => $code,
            'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ];
        if (count($data['recent']) > 2000) {
            $data['recent'] = array_slice($data['recent'], -2000);
        }
        return $promo;
    });
}

function playnow_tracker_prune_data(array &$data): bool
{
    if (!function_exists('blog_ip_is_ignored')) {
        return false;
    }

    $modified = false;

    // 1. Identify all ignored IPs in byIp
    $ignoredIpsInByIp = [];
    $totalIgnoredClicksInByIp = 0;
    if (isset($data['byIp']) && is_array($data['byIp'])) {
        foreach ($data['byIp'] as $ip => $count) {
            $ipStr = (string) $ip;
            if ($ipStr !== '' && blog_ip_is_ignored($ipStr)) {
                $ignoredIpsInByIp[$ipStr] = (int) $count;
                $totalIgnoredClicksInByIp += (int) $count;
            }
        }
    }

    // 2. Identify ignored events in recent
    $recent = isset($data['recent']) && is_array($data['recent']) ? $data['recent'] : [];
    $newRecent = [];
    $removedRecentCount = 0;

    foreach ($recent as $event) {
        $ip = (string) ($event['ip'] ?? '');
        if ($ip !== '' && blog_ip_is_ignored($ip)) {
            $modified = true;
            $removedRecentCount++;

            $loc = (string) ($event['location'] ?? '');
            if ($loc !== '' && isset($data['byLocation'][$loc])) {
                $data['byLocation'][$loc] = ((int) $data['byLocation'][$loc]) - 1;
                if ($data['byLocation'][$loc] <= 0) {
                    unset($data['byLocation'][$loc]);
                }
            }
            $page = (string) ($event['pagePath'] ?? ($event['pageUrl'] ?? ''));
            if ($page !== '' && isset($data['byPage'][$page])) {
                $data['byPage'][$page] = ((int) $data['byPage'][$page]) - 1;
                if ($data['byPage'][$page] <= 0) {
                    unset($data['byPage'][$page]);
                }
            }
            $btn = (string) ($event['buttonKey'] ?? ($event['buttonText'] ?? ''));
            if ($btn !== '' && isset($data['byButton'][$btn])) {
                $data['byButton'][$btn] = ((int) $data['byButton'][$btn]) - 1;
                if ($data['byButton'][$btn] <= 0) {
                    unset($data['byButton'][$btn]);
                }
            }
            $tgt = (string) ($event['targetUrl'] ?? '');
            if ($tgt !== '' && isset($data['byTarget'][$tgt])) {
                $data['byTarget'][$tgt] = ((int) $data['byTarget'][$tgt]) - 1;
                if ($data['byTarget'][$tgt] <= 0) {
                    unset($data['byTarget'][$tgt]);
                }
            }
            $promoCode = (string) ($event['promoCode'] ?? '');
            if ($promoCode !== '' && isset($data['promos'][$promoCode]['clicks'])) {
                $data['promos'][$promoCode]['clicks'] = max(0, ((int) $data['promos'][$promoCode]['clicks']) - 1);
            }
        } else {
            $newRecent[] = $event;
        }
    }

    if ($removedRecentCount > 0) {
        $data['recent'] = $newRecent;
    }

    if (!empty($ignoredIpsInByIp)) {
        $modified = true;
        foreach ($ignoredIpsInByIp as $ip => $count) {
            unset($data['byIp'][$ip]);
        }
        $data['totalClicks'] = max(0, (int) ($data['totalClicks'] ?? 0) - $totalIgnoredClicksInByIp);
    } elseif ($removedRecentCount > 0) {
        $data['totalClicks'] = max(0, (int) ($data['totalClicks'] ?? 0) - $removedRecentCount);
    }

    return $modified;
}

function playnow_tracker_sync_ignored(): bool
{
    $path = playnow_promo_path();
    if (!is_file($path)) {
        return false;
    }
    try {
        $result = playnow_promo_write(static function (array &$data): array {
            $changed = playnow_tracker_prune_data($data);
            return ['changed' => $changed];
        });
        return !empty($result['changed']);
    } catch (Throwable) {
        return false;
    }
}
