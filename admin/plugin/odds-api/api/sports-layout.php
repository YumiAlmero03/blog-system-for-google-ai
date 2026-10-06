<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/sports-api.php';
require_once __DIR__ . '/../../../includes/api-rate-limit.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try {
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){http_response_code(405);header('Allow: GET');echo json_encode(['ok'=>false,'error'=>'Use GET.']);exit;}
    if(api_rate_limit_exceeded(hash('sha256','sports-layout|'.($_SERVER['REMOTE_ADDR']??'unknown')),60)){http_response_code(429);header('Retry-After: 60');echo json_encode(['ok'=>false,'error'=>'Too many requests.']);exit;}
    $id=$_GET['id']??'1';$sport=$_GET['sport']??'';
    if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,9}$/D',$id)||!is_string($sport)||strlen($sport)>100)throw new InvalidArgumentException();
    echo json_encode(sports_api_output((int)$id,$sport),JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(OutOfBoundsException $e){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Layout not found.']);}
catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Invalid layout, sport or data path.']);}
catch(Throwable $e){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Data temporarily unavailable. Please try again shortly.']);}
