<?php
declare(strict_types=1);

$query = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: /admin/blog-publish.php' . $query, true, 302);
exit;
