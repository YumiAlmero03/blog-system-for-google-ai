<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../api/chat-link-preview.php';

function preview_check(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
}

try {
    if (!class_exists(DOMDocument::class)) throw new RuntimeException('DOM extension is required.');
    $metadata = chat_preview_metadata('<html><head><meta property="og:title" content="ByBet &amp; Rewards">'
        . '<meta property="og:description" content="Join the official community">'
        . '<meta property="og:image" content="/images/community.jpg"><title>Fallback</title></head></html>');
    preview_check($metadata === [
        'title'=>'ByBet & Rewards',
        'description'=>'Join the official community',
        'image'=>'/images/community.jpg',
    ], 'Open Graph fields parsed and entities decoded');
    $fallback = chat_preview_metadata('<html><head><meta name="description" content="Page summary">'
        . '<title>Page title</title></head></html>');
    preview_check($fallback['title'] === 'Page title' && $fallback['description'] === 'Page summary',
        'Title and description fallbacks parsed');

    foreach (['http://127.0.0.1/private', 'http://localhost/private', 'https://user@example.com/'] as $url) {
        try {
            chat_preview_url($url);
            throw new RuntimeException('Unsafe preview URL was accepted: ' . $url);
        } catch (InvalidArgumentException $e) {
            // Expected for private destinations and URLs containing credentials.
        }
    }
    echo "PASS: Open Graph metadata, fallbacks, and private/credentialed URL rejection.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
