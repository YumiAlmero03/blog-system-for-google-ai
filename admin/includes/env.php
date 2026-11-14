<?php
declare(strict_types=1);

function app_root_path(): string { return dirname(__DIR__); }
function site_root_path(): string { return dirname(__DIR__, 2); }

function load_env_file(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $equalsPosition = strpos($line, '=');
        if ($equalsPosition === false) {
            continue;
        }

        $name = trim(substr($line, 0, $equalsPosition));
        $value = trim(substr($line, $equalsPosition + 1));

        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $name)) {
            continue;
        }

        if ($value !== '' && (
            ($value[0] === '"' && substr($value, -1) === '"')
            || ($value[0] === "'" && substr($value, -1) === "'")
        )) {
            $value = substr($value, 1, -1);
        }

        if (env_value($name) !== null) {
            continue;
        }

        if (function_exists('putenv')) {
            putenv($name . '=' . $value);
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

load_env_file(__DIR__ . '/../.env');

function env_value(string $name): ?string
{
    if (function_exists('getenv')) {
        $value = getenv($name);
        if (is_string($value) && $value !== '') {
            return $value;
        }
    }

    if (isset($_ENV[$name]) && is_string($_ENV[$name]) && $_ENV[$name] !== '') {
        return $_ENV[$name];
    }

    if (isset($_SERVER[$name]) && is_string($_SERVER[$name]) && $_SERVER[$name] !== '') {
        return $_SERVER[$name];
    }

    return null;
}

function site_base_url(): string
{
    $configured = env_value('SITE_BASE_URL');
    if (is_string($configured) && trim($configured) !== '') {
        return rtrim(trim($configured), '/');
    }

    require_once __DIR__ . '/seo-settings.php';
    $configured = seo_stored_settings()['site_base_url'] ?? '';
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) && trim($_SERVER['HTTP_HOST']) !== '') {
        $scheme = ((!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') ? 'https' : 'http';
        return $scheme . '://' . trim($_SERVER['HTTP_HOST']);
    }

    return '';
}

function site_legacy_domains(): array
{
    return [
        'https://[insert_domain_here]',
        'http://[insert_domain_here]',
        '//[insert_domain_here]',
        '[insert_domain_here]',
        'https://thechikin.chickenkiller.com',
        'http://thechikin.chickenkiller.com',
        'https://freecasinogames.ph',
        'http://freecasinogames.ph',
        'https://freeonlinegames.info',
        'http://freeonlinegames.info',
        'https://bygame.ph',
        'http://bygame.ph',
    ];
}

// Resolve legacy template domains using the deployment's configured URL.
function site_replace_domain_placeholders(string $value, ?string $baseUrl = null): string
{
    $baseUrl = rtrim($baseUrl ?? site_base_url(), '/');
    return strtr($value, array_fill_keys(site_legacy_domains(), $baseUrl));
}

function public_normalize_upload_path(string $url, string $baseUrl = ''): ?string
{
    $trimmed = trim($url);
    if ($trimmed === '') {
        return null;
    }

    if (preg_match('#^/?(uploads/[^\s"\'<>]+)$#i', $trimmed, $m) === 1) {
        return '/' . ltrim($m[1], '/');
    }

    $parsedHost = parse_url($trimmed, PHP_URL_HOST);
    $parsedPath = parse_url($trimmed, PHP_URL_PATH);
    if (!is_string($parsedHost) || $parsedHost === '' || !is_string($parsedPath) || $parsedPath === '') {
        return null;
    }

    $knownHosts = [
        'thechikin.chickenkiller.com' => [''],
        'freecasinogames.ph' => [''],
        'freeonlinegames.info' => [''],
        'bygame.ph' => [''],
    ];
    foreach ([$baseUrl, site_base_url(), env_value('FRONTEND_URL')] as $origin) {
        if (!is_string($origin) || trim($origin) === '') {
            continue;
        }
        $host = parse_url(trim($origin), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            continue;
        }
        $hostKey = strtolower($host);
        $knownHosts[$hostKey] ??= [''];
        $originPath = rtrim((string) (parse_url(trim($origin), PHP_URL_PATH) ?? ''), '/');
        if ($originPath !== '' && !in_array($originPath, $knownHosts[$hostKey], true)) {
            $knownHosts[$hostKey][] = $originPath;
        }
    }
    if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '') {
        $reqHost = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) ?? $_SERVER['HTTP_HOST']);
        $knownHosts[$reqHost] ??= [''];
    }

    $hostKey = strtolower($parsedHost);
    if (!isset($knownHosts[$hostKey])) {
        return null;
    }

    $uploadPath = null;
    foreach ($knownHosts[$hostKey] as $prefix) {
        if (preg_match('#^' . preg_quote($prefix, '#') . '(/uploads/.*)$#i', $parsedPath, $m) === 1) {
            $uploadPath = $m[1];
            break;
        }
    }
    if ($uploadPath === null) {
        return null;
    }

    $query = parse_url($trimmed, PHP_URL_QUERY);
    return $uploadPath . (is_string($query) && $query !== '' ? '?' . $query : '');
}

function public_url(string $url, ?string $baseUrl = null): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $url = site_replace_domain_placeholders($url, $baseUrl);

    if (
        str_starts_with($url, '#')
        || str_starts_with($url, '//')
        || preg_match('/^(?:https?:|mailto:|tel:|javascript:|data:|blob:)/i', $url) === 1
    ) {
        return $url;
    }

    $baseUrl = rtrim($baseUrl ?? site_base_url(), '/');
    return $baseUrl . '/' . ltrim($url, '/');
}

function public_html_absolute_urls(string $html, ?string $baseUrl = null): string
{
    $baseUrl = rtrim($baseUrl ?? site_base_url(), '/');
    $html = site_replace_domain_placeholders($html, $baseUrl);

    $absoluteAttribute = static function (array $matches) use ($baseUrl): string {
        $attribute = $matches[1];
        $quote = $matches[2];
        $value = html_entity_decode($matches[3], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (str_starts_with($value, '/api/') || str_starts_with($value, '/@')) {
            return $matches[0];
        }

        // Keep /uploads/ URLs root-relative so consuming frontends resolve them cleanly.
        $uploadPath = public_normalize_upload_path($value, $baseUrl);
        if ($uploadPath !== null) {
            return $attribute . '=' . $quote . htmlspecialchars($uploadPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $quote;
        }

        if (
            str_starts_with($value, '/')
            || preg_match('~^(?:assets|blog|game|slots|arcade|perya-games|fishing-games|live-casino|card-games|sports-betting|virtual-sports-betting|promos|playnow|payments|vip|invite|app|contact|about-us|responsible-gaming|terms-and-conditions|privacy-policy|affiliate-sponsor-disclosure|disclaimer|become-a-partner|search)(?:/|$|\?|#)~i', $value) === 1
        ) {
            return $attribute . '=' . $quote . htmlspecialchars(public_url($value, $baseUrl), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $quote;
        }

        return $matches[0];
    };

    $html = preg_replace_callback('/\b(href|src|poster|content)=([\'"])([^\'"]+)\2/i', $absoluteAttribute, $html) ?? $html;

    $html = preg_replace_callback('/\bsrcset=([\'"])([^\'"]+)\1/i', static function (array $matches) use ($baseUrl): string {
        $items = array_map(static function (string $item) use ($baseUrl): string {
            $parts = preg_split('/\s+/', trim($item), 2);
            if ($parts === false || $parts === [] || $parts[0] === '' || str_starts_with($parts[0], '/api/')) {
                return $item;
            }
            $uploadPath = public_normalize_upload_path($parts[0], $baseUrl);
            if ($uploadPath !== null) {
                $parts[0] = $uploadPath;
            } elseif (str_starts_with($parts[0], '/') || preg_match('#^assets/#i', $parts[0]) === 1) {
                $parts[0] = public_url($parts[0], $baseUrl);
            }
            return implode(' ', $parts);
        }, explode(',', $matches[2]));
        return 'srcset=' . $matches[1] . htmlspecialchars(implode(', ', $items), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $matches[1];
    }, $html) ?? $html;

    return preg_replace_callback('/url\((["\']?)((?:https?:\/\/[^)\'"]+\/)?(?:uploads\/[^)\'"]+)|\/(?!api\/|@)[^)\'"]+|assets\/[^)\'"]+)\1\)/i', static function (array $matches) use ($baseUrl): string {
        $uploadPath = public_normalize_upload_path($matches[2], $baseUrl);
        if ($uploadPath !== null) {
            return 'url(' . $matches[1] . $uploadPath . $matches[1] . ')';
        }
        return 'url(' . $matches[1] . public_url($matches[2], $baseUrl) . $matches[1] . ')';
    }, $html) ?? $html;
}
