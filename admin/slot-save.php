<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
require_capability('slots.edit'); require_post(); require_valid_csrf();
require_once __DIR__ . '/includes/slot-content.php';
header('Content-Type: application/json; charset=UTF-8');
try {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>220000) throw new InvalidArgumentException('Request too large.');
    slot_content_save($_POST);
    echo json_encode(['ok'=>true,'message'=>'Game content saved.']);
} catch (InvalidArgumentException $e) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
catch (GameSitemapRefreshException $e) { error_log($e->getPrevious()?->getMessage() ?? $e->getMessage()); http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
catch (Throwable $e) { error_log('Slot content save failed.'); http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Unable to save game content.']); }
