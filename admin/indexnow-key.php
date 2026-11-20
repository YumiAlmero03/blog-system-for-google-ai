<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/indexnow.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

$requested = trim((string) ($_GET['key'] ?? ''));
$settings = indexnow_settings();
$configured = (string) ($settings['key'] ?? '');

if (
    $requested === ''
    || $configured === ''
    || !indexnow_key_valid($requested)
    || !indexnow_key_valid($configured)
    || !hash_equals($configured, $requested)
) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/plain; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=300');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    echo $configured;
}
