<?php
declare(strict_types=1);

if (!is_file(__DIR__ . '/../admin/plugin/games/api/provider-list.php')) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Plugin unavailable.']);
    exit;
}

require __DIR__ . '/../admin/plugin/games/api/provider-list.php';
