<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=1800');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../admin/includes/api-rate-limit.php';

function chat_preview_error(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['ok'=>false,'error'=>$message], JSON_UNESCAPED_SLASHES);
    exit;
}

function chat_preview_url(string $url): array
{
    if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
        throw new InvalidArgumentException('Invalid URL.');
    }
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
        || !in_array(strtolower($parts['scheme']), ['http','https'], true)
        || isset($parts['user']) || isset($parts['pass'])) {
        throw new InvalidArgumentException('Invalid URL.');
    }
    $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
    if (!in_array($port, [80,443], true)) throw new InvalidArgumentException('Unsupported URL port.');

    $host = trim($parts['host'], '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new InvalidArgumentException('URL host is not public.');
        }
        return [$url, null];
    }
    if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/iD', $host)) {
        throw new InvalidArgumentException('Invalid URL host.');
    }

    $addresses = gethostbynamel($host);
    if (!$addresses) throw new RuntimeException('URL host could not be resolved.');
    foreach ($addresses as $address) {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new InvalidArgumentException('URL host is not public.');
        }
    }

    return [$url, $host . ':' . $port . ':' . $addresses[0]];
}

function chat_preview_text(string $text, int $limit): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    return function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit);
}

function chat_preview_metadata(string $html): array
{
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $metadata = [];
    foreach ($document->getElementsByTagName('meta') as $meta) {
        $key = strtolower($meta->getAttribute('property') ?: $meta->getAttribute('name'));
        $content = chat_preview_text($meta->getAttribute('content'), 500);
        if ($content !== '') $metadata[$key] ??= $content;
    }
    $title = '';
    $titles = $document->getElementsByTagName('title');
    if ($titles->length > 0) $title = chat_preview_text($titles->item(0)->textContent, 160);

    return [
        'title'=>chat_preview_text($metadata['og:title'] ?? $metadata['twitter:title'] ?? $title, 160),
        'description'=>chat_preview_text($metadata['og:description'] ?? $metadata['twitter:description'] ?? $metadata['description'] ?? '', 300),
        'image'=>$metadata['og:image'] ?? $metadata['og:image:url'] ?? $metadata['twitter:image'] ?? '',
    ];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    chat_preview_error(405, 'Method not allowed.');
}

$url = $_GET['url'] ?? '';
if (!is_string($url) || trim($url) === '') chat_preview_error(422, 'A URL is required.');
try {
    [$url, $resolve] = chat_preview_url($url);
} catch (InvalidArgumentException $e) {
    chat_preview_error(422, $e->getMessage());
} catch (Throwable $e) {
    chat_preview_error(502, 'Unable to resolve the link.');
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (api_rate_limit_exceeded(hash('sha256', 'chat-link-preview|' . $ip), 30)) {
    header('Retry-After: 60');
    chat_preview_error(429, 'Too many preview requests.');
}
if (!function_exists('curl_init') || !class_exists(DOMDocument::class)) {
    chat_preview_error(503, 'Link previews are unavailable.');
}

$body = '';
$curl = curl_init($url);
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER=>false,
    CURLOPT_FOLLOWLOCATION=>false,
    CURLOPT_PROTOCOLS=>CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_PROXY=>'',
    CURLOPT_CONNECTTIMEOUT=>4,
    CURLOPT_TIMEOUT=>8,
    CURLOPT_MAXFILESIZE=>1048576,
    CURLOPT_SSL_VERIFYPEER=>true,
    CURLOPT_SSL_VERIFYHOST=>2,
    CURLOPT_USERAGENT=>'ByGameChatPreview/1.0',
    CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml'],
    CURLOPT_WRITEFUNCTION=>static function($handle, string $chunk) use (&$body): int {
        if (strlen($body) + strlen($chunk) > 1048576) return 0;
        $body .= $chunk;
        return strlen($chunk);
    },
]);
if ($resolve !== null) curl_setopt($curl, CURLOPT_RESOLVE, [$resolve]);
$ok = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$contentType = strtolower((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE));
curl_close($curl);
if ($ok === false || $status !== 200 || !str_contains($contentType, 'text/html')) {
    chat_preview_error(502, 'Unable to load link details.');
}

try {
    echo json_encode(['ok'=>true] + chat_preview_metadata($body), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    chat_preview_error(502, 'Unable to read link details.');
}
