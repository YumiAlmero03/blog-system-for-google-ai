<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_auth();

$query = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: /admin/blog-publish.php' . $query, true, 302);
exit;
