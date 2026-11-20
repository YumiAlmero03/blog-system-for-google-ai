<?php
declare(strict_types=1);
// Warnings belong in the PHP log, never before this endpoint's JSON response.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
require_once __DIR__ . '/../../includes/auth.php';
require_admin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST'); http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Method not allowed.']); exit;
}
require_valid_csrf();
require_once __DIR__ . '/includes/ph-availability.php';
try {
    $values = [];
    foreach (['slot_id','after_id','max_id'] as $field) {
        if (!isset($_POST[$field])) { $values[$field] = null; continue; }
        $value = filter_var($_POST[$field], FILTER_VALIDATE_INT, ['options'=>['min_range'=>$field === 'slot_id' ? 1 : 0]]);
        if ($value === false) throw new InvalidArgumentException('Invalid game selection.');
        $values[$field] = $value;
    }
    $pdo = blogs_pdo();
    $result = games_ph_recheck($pdo, $values['after_id'] ?? 0, $values['max_id'], $values['slot_id']);
    // Finish by rebuilding even if publication was already updated by an earlier run.
    // This also repairs stale sitemap entries left by an interrupted/failed refresh.
    if ($result['changed'] > 0 || $result['done']) {
        try { game_visibility_refresh(); }
        catch (Throwable $error) {
            error_log('PH recheck sitemap refresh failed: ' . ($error->getPrevious() ?? $error)->getMessage());
            $result['warning'] = 'Availability was saved, but sitemap refresh failed. Save Games Settings to retry the refresh.';
        }
    }
    echo json_encode(['ok'=>true] + $result);
} catch (InvalidArgumentException $error) {
    http_response_code(422); echo json_encode(['ok'=>false,'error'=>$error->getMessage()]);
} catch (GamePhCheckException $error) {
    http_response_code(502); echo json_encode(['ok'=>false,'error'=>$error->getMessage()]);
} catch (Throwable $error) {
    error_log('Game PH recheck failed.');
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Availability could not be rechecked. Please retry.']);
}
