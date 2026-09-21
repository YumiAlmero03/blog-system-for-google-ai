<?php
declare(strict_types=1);
require_once __DIR__ . '/../../admin/includes/seo-settings.php';
require_once __DIR__ . '/../../admin/includes/indexnow.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
    if ($method === 'GET') {
        echo json_encode(['ok'=>true,'settings'=>seo_settings()+indexnow_public_settings()],JSON_UNESCAPED_SLASHES); exit;
    }
    if ($method !== 'POST') { header('Allow: GET, POST'); http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Method not allowed.']); exit; }
    require_once __DIR__ . '/../../admin/includes/auth.php';
    require_admin();
    require_valid_csrf();
    $input = $_POST;
    unset($input['csrf_token']);
    $settings = seo_settings_save($input);
    csrf_rotate();
    echo json_encode(['ok'=>true,'settings'=>$settings+indexnow_public_settings(),'csrfToken'=>csrf_token()],JSON_UNESCAPED_SLASHES);
} catch (DomainException $e) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
} catch (InvalidArgumentException $e) {
    http_response_code(422); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('SEO settings request failed.');
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'SEO settings are unavailable.']);
}
