<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not found.\n";
    exit(1);
}

echo "Game route syncing is no longer needed. /game/{slug}/ is handled by game/index.php and server rewrites.\n";
