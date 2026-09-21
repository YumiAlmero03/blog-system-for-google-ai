<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not found.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/jwt.php';

$ttl = isset($argv[1]) && ctype_digit($argv[1]) ? (int) $argv[1] : 86400;
$ttl = max(300, min(31536000, $ttl));
$now = time();

echo jwt_sign([
    'aud' => 'blog-post-list',
    'scope' => 'blog:read',
    'iat' => $now,
    'nbf' => $now,
    'exp' => $now + $ttl,
]) . "\n";
