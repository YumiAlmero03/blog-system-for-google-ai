<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

require_auth();
require_post();
require_valid_csrf();

auth_destroy_session();
header('Location: /admin/login.php', true, 302);
exit;
