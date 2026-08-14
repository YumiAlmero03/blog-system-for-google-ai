<?php
declare(strict_types=1);

$slug = '';

if (isset($_GET['slug']) && is_string($_GET['slug'])) {
    $slug = $_GET['slug'];
} else {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (is_string($path) && preg_match('#^/game/([A-Za-z0-9-]+)/?$#', $path, $matches) === 1) {
        $slug = $matches[1];
    }
}

if ($slug === '') {
    header('Location: /slots/', true, 302);
    exit;
}

$_GET['slug'] = $slug;
require __DIR__ . '/view.php';
