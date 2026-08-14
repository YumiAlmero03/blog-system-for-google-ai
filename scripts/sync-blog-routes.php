<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not found.\n";
    exit(1);
}

echo "Blog route syncing is no longer needed. /blog/{slug}/ is handled by blog/view.php and server rewrites.\n";
