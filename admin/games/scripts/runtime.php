<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../includes/blog-storage.php';
blogs_pdo();
echo json_encode(['blogs'=>blogs_db_path(),'games'=>games_db_path(),'enabled'=>games_enabled()], JSON_THROW_ON_ERROR);
