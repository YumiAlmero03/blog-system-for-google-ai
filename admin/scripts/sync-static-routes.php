<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not found.\n";
    exit(1);
}

$root = dirname(__DIR__);
$shellPath = $root . '/index.html';
$dbPath = $root . '/storage/blogs.sqlite';

if (!is_file($shellPath)) {
    fwrite(STDERR, "Missing index.html app shell.\n");
    exit(1);
}

$shell = file_get_contents($shellPath);
if (!is_string($shell) || $shell === '') {
    fwrite(STDERR, "Unable to read index.html app shell.\n");
    exit(1);
}

function route_sync_clean_slug(mixed $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9-]+/', '-', $value) ?? '';
    $value = preg_replace('/-+/', '-', $value) ?? '';

    return trim($value, '-');
}

function route_sync_write(string $root, string $route, string $shell): bool
{
    $route = trim($route, '/');
    if ($route === '' || str_contains($route, '..')) {
        return false;
    }

    $dir = $root . '/' . $route;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    return file_put_contents($dir . '/index.html', $shell, LOCK_EX) !== false;
}

$routes = [
    'app',
    'blog',
    'blogs',
    'contact',
    'invite',
    'payments',
    'support',
    'vip',
];

if (is_file($dbPath)) {
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    foreach ($pdo->query('SELECT slug FROM games WHERE published = 1 AND slug <> ""') as $row) {
        $slug = route_sync_clean_slug($row['slug'] ?? '');
        if ($slug !== '') {
            $routes[] = 'games/' . $slug;
        }
    }

    foreach ($pdo->query('SELECT slug FROM blog_posts WHERE status = "published" AND slug <> ""') as $row) {
        $slug = route_sync_clean_slug($row['slug'] ?? '');
        if ($slug !== '') {
            $routes[] = 'blogs/' . $slug;
        }
    }
}

$routes = array_values(array_unique($routes));
sort($routes, SORT_NATURAL);

$written = 0;
$failed = [];
foreach ($routes as $route) {
    if (route_sync_write($root, $route, $shell)) {
        $written++;
        continue;
    }

    $failed[] = $route;
}

echo "Synced {$written} static route HTML files.\n";
if ($failed !== []) {
    fwrite(STDERR, "Failed routes:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}
