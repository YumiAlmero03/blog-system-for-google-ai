<?php
declare(strict_types=1);

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

load_env_file(dirname(__DIR__) . '/.env');

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
        $configured = str_replace(
            ['http://freecasinogames.ph', 'https://freeonlinegames.info', 'http://freeonlinegames.info'],
            ['https://freecasinogames.ph', 'https://freecasinogames.ph', 'https://freecasinogames.ph'],
            trim($configured)
        );

        return rtrim($configured, '/');
    }

    require_once __DIR__ . '/seo-settings.php';
    $configured = seo_stored_settings()['site_base_url'] ?? '';
    return $configured !== '' ? rtrim($configured,'/') : 'https://freecasinogames.ph';
}

function public_url(string $url, ?string $baseUrl = null): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (
        str_starts_with($url, '#')
        || str_starts_with($url, '//')
        || preg_match('/^(?:https?:|mailto:|tel:|javascript:|data:|blob:)/i', $url) === 1
    ) {
        return str_replace(
            ['http://freecasinogames.ph', 'https://freeonlinegames.info', 'http://freeonlinegames.info'],
            ['https://freecasinogames.ph', 'https://freecasinogames.ph', 'https://freecasinogames.ph'],
            $url
        );
    }

    $baseUrl = rtrim($baseUrl ?? site_base_url(), '/');
    return $baseUrl . '/' . ltrim($url, '/');
}

function public_html_absolute_urls(string $html, ?string $baseUrl = null): string
{
    $baseUrl = rtrim($baseUrl ?? site_base_url(), '/');
    $html = str_replace(
        ['http://freecasinogames.ph', 'https://freeonlinegames.info', 'http://freeonlinegames.info'],
        [$baseUrl, $baseUrl, $baseUrl],
        $html
    );

    $absoluteAttribute = static function (array $matches) use ($baseUrl): string {
        $attribute = $matches[1];
        $quote = $matches[2];
        $value = html_entity_decode($matches[3], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (str_starts_with($value, '/api/') || str_starts_with($value, '/@')) {
            return $matches[0];
        }

        if (
            str_starts_with($value, '/')
            || preg_match('~^(?:assets|uploads|blog|game|slots|arcade|perya-games|fishing-games|live-casino|card-games|sports-betting|virtual-sports-betting|promos|playnow|payments|vip|invite|app|contact|about-us|responsible-gaming|terms-and-conditions|privacy-policy|affiliate-sponsor-disclosure|disclaimer|become-a-partner|search)(?:/|$|\?|#)~i', $value) === 1
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
            if (str_starts_with($parts[0], '/') || preg_match('#^(?:assets|uploads)/#i', $parts[0]) === 1) {
                $parts[0] = public_url($parts[0], $baseUrl);
            }
            return implode(' ', $parts);
        }, explode(',', $matches[2]));
        return 'srcset=' . $matches[1] . htmlspecialchars(implode(', ', $items), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $matches[1];
    }, $html) ?? $html;

    return preg_replace_callback('/url\((["\']?)(\/(?!api\/|@)[^)\'"]+|(?:assets|uploads)\/[^)\'"]+)\1\)/i', static function (array $matches) use ($baseUrl): string {
        return 'url(' . $matches[1] . public_url($matches[2], $baseUrl) . $matches[1] . ')';
    }, $html) ?? $html;
}
