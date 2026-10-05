<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/odds-api/includes/service.php';
require_once __DIR__ . '/../admin/includes/api-rate-limit.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        http_response_code(405); header('Allow: GET');
        echo json_encode(['ok'=>false,'error'=>'Use GET.']); exit;
    }
    if (api_rate_limit_exceeded(hash('sha256', 'odds-api|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')), 60)) {
        http_response_code(429); header('Retry-After: 60');
        echo json_encode(['ok'=>false,'error'=>'Too many requests. Please try again shortly.']); exit;
    }
    $action = $_GET['action'] ?? 'sports'; $sport = $_GET['sport'] ?? '';
    $iframeId = $_GET['iframe'] ?? '1';
    if (!is_string($iframeId) || !preg_match('/^[1-9][0-9]{0,9}$/D', $iframeId)) throw new InvalidArgumentException('Invalid iframe ID.');
    if (!is_string($action) || !is_string($sport)) throw new InvalidArgumentException('Invalid request.');
    echo json_encode(odds_api_result($action, $sport, null, (int)$iframeId), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (OutOfBoundsException $e) {
    http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Iframe not found.']);
} catch (InvalidArgumentException $e) {
    http_response_code(422); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(503); echo json_encode(['ok'=>false,'error'=>'Odds are temporarily unavailable. Please try again shortly.']);
}
