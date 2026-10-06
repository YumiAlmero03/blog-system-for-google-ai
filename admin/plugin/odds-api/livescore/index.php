<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/auth.php';
require_admin();
header('Location: /admin/plugin/odds-api/sports/',true,303);exit;
