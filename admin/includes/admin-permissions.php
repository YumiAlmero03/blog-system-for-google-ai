<?php
declare(strict_types=1);

function auth_route_capability(): string
{
    $path = $_SERVER['SCRIPT_NAME'] ?? '';
    $routes = [
        '/admin/index.php'=>'blogs.view', '/admin/blogs.php'=>'blogs.view',
        '/admin/blog-list.php'=>'blogs.view', '/admin/blog-edit.php'=>'blogs.edit',
        '/admin/blog-publish.php'=>'blogs.edit', '/admin/blog-create.php'=>'blogs.edit',
        '/admin/blog-save.php'=>'blogs.publish', '/admin/blog-delete.php'=>'blogs.edit',
        '/admin/blog-category-list.php'=>'blogs.view', '/admin/blog-tag-list.php'=>'blogs.view', '/admin/upload-image.php'=>'blogs.edit',
        '/admin/games/index.php'=>(($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? 'slots.edit' : 'slots.view'),
        '/admin/games/'=>(($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? 'slots.edit' : 'slots.view'), '/admin/games/edit.php'=>'slots.edit', '/admin/games/save.php'=>'slots.edit',
        '/admin/slots.php'=>(($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? 'slots.edit' : 'slots.view'),
        '/admin/slot-edit.php'=>'slots.edit', '/admin/slot-save.php'=>'slots.edit',
        '/admin/contacts.php'=>'contacts.view', '/admin/live-chat.php'=>'chat.view',
        '/api/chat-session.php'=>(($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? 'chat.reply' : 'chat.view'),
        '/logout.php'=>'session.logout',
    ];
    // New/unlisted admin areas are administrator-only by default.
    return $routes[$path] ?? 'admin';
}

function auth_can(string $capability): bool
{
    $role = $_SESSION['user']['role'] ?? '';
    if ($role === 'super_user') return true;
    if ($role === 'admin') return $capability !== 'super_user';
    return $role === 'editor' && in_array($capability,[
        'blogs.view','blogs.edit','blogs.publish','slots.view','slots.edit',
        'contacts.view','contacts.manage','chat.view','chat.reply','session.logout',
    ],true);
}

function auth_deny(int $status = 403, string $message = 'Access denied.'): never
{
    http_response_code($status);
    header('Cache-Control: no-store');
    if (expects_json()) { header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['ok'=>false,'error'=>$message]); }
    else echo h($message);
    exit;
}

function require_capability(string $capability): void { require_auth($capability); }
function require_admin(): void { require_auth('admin'); }

function auth_rate_limit(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    require_once __DIR__ . '/api-rate-limit.php';
    $path = $_SERVER['SCRIPT_NAME'] ?? '';
    $write = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $readPosts = ['/admin/blog-list.php','/admin/blog-edit.php','/admin/blog-category-list.php','/admin/blog-tag-list.php'];
    $group = $write && !in_array($path,$readPosts,true) ? 'write' : 'read';
    $limit = $group === 'write' ? 30 : 120;
    if ($path === '/api/chat-session.php') $group = $write ? 'chat-send' : 'chat-poll';
    $key = hash('sha256','admin|' . ($_SESSION['user']['id'] ?? '') . '|' . $group);
    if (api_rate_limit_exceeded($key,$limit)) { header('Retry-After: 60'); auth_deny(429,'Too many requests. Please try again shortly.'); }
}
