<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not found.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/blog-storage.php';

$count = blogs_sync_routes();
echo "Synced {$count} blog route(s).\n";
