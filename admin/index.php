<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_auth();

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$path = (string) parse_url($requestUri, PHP_URL_PATH);

// Tournament edit route: /admin/plugin/odds-api/registry/tournaments/{id}/edit/
if (preg_match('#^/admin/(?:plugin/odds-api/registry|settings/sports)/tournaments/([0-9]+)(?:/edit)?/?$#', $path, $matches)) {
    $_GET['id'] = (int) $matches[1];
    require __DIR__ . '/plugin/odds-api/registry/tournament-edit.php';
    exit;
}

// Sport registration / tournament list route: /admin/plugin/odds-api/registry/{sport}/registration/
if (preg_match('#^/admin/(?:plugin/odds-api/registry|settings/sports)/([A-Za-z0-9_-]+)/registration/?$#', $path, $matches)) {
    $_GET['sport'] = $matches[1];
    require __DIR__ . '/plugin/odds-api/registry/registration.php';
    exit;
}

// Sport teams / participants edit route: /admin/plugin/odds-api/registry/{sport}/teams/{id}/
if (preg_match('#^/admin/(?:plugin/odds-api/registry|settings/sports)/([A-Za-z0-9_-]+)/teams/([0-9]+)/?$#', $path, $matches)) {
    $_GET['sport'] = $matches[1];
    $_GET['id'] = (int) $matches[2];
    require __DIR__ . '/plugin/odds-api/registry/team-edit.php';
    exit;
}

// Sport teams / participants list route: /admin/plugin/odds-api/registry/{sport}/teams/
if (preg_match('#^/admin/(?:plugin/odds-api/registry|settings/sports)/([A-Za-z0-9_-]+)/teams/?$#', $path, $matches)) {
    $_GET['sport'] = $matches[1];
    require __DIR__ . '/plugin/odds-api/registry/teams.php';
    exit;
}

// Sport edit route: /admin/plugin/odds-api/registry/{sport}/edit/
if (preg_match('#^/admin/(?:plugin/odds-api/registry|settings/sports)/([A-Za-z0-9_-]+)/edit/?$#', $path, $matches)) {
    $_GET['sport'] = $matches[1];
    require __DIR__ . '/plugin/odds-api/registry/edit.php';
    exit;
}

// Sports registry root route: /admin/settings/sports/
if (preg_match('#^/admin/settings/sports/?$#', $path)) {
    require __DIR__ . '/plugin/odds-api/registry/index.php';
    exit;
}

header('Location: /admin/blogs.php', true, 302);
exit;
