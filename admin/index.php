<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_auth();

header('Location: /admin/blogs.php', true, 302);
exit;
