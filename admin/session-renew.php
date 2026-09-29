<?php
declare(strict_types=1);
require_once __DIR__.'/includes/auth.php';
$_SERVER['HTTP_ACCEPT']='application/json';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
require_post();
// A heartbeat may renew a valid token, but is not itself user activity.
require_valid_csrf();
$idle=filter_var($_POST['idle_seconds'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>AUTH_INACTIVITY_TIMEOUT]]);
if ($idle===false || $idle===null) {
    http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Invalid activity time.']); exit;
}
$lastActivity=(int)($_SESSION['last_activity'] ?? 0);
require_auth('session.renew');
$_SESSION['last_activity']=max($lastActivity,time()-$idle);
echo json_encode(['ok'=>true,'csrfToken'=>csrf_token(),'idleTimeout'=>AUTH_INACTIVITY_TIMEOUT,
    'absoluteExpiresAt'=>$_SESSION['login_time']+AUTH_ABSOLUTE_LIFETIME]);
