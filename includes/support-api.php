<?php
declare(strict_types=1);
require_once __DIR__ . '/support-storage.php';
require_once __DIR__ . '/api-rate-limit.php';

function support_json(array $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function support_input(): array
{
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 20000) support_json(['ok'=>false,'error'=>'Request too large.'],413);
    $raw = file_get_contents('php://input', false, null, 0, 20001);
    if (strlen($raw ?: '') > 20000) support_json(['ok'=>false,'error'=>'Request too large.'],413);
    if (str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''),'application/json')) {
        $input = json_decode($raw ?: '',true);
        if (!is_array($input)) throw new InvalidArgumentException('Invalid JSON request.');
        return $input;
    }
    return $_POST;
}

function support_rate(string $action, int $limit): void
{
    $key = hash('sha256','support|' . $action . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    if (api_rate_limit_exceeded($key,$limit)) {
        header('Retry-After: 60');
        support_json(['ok'=>false,'error'=>'Too many requests. Please try again later.'],429);
    }
}

function support_cursor(mixed $value): int
{
    if (!is_scalar($value) || !preg_match('/^\d{1,15}$/D',(string)$value)) throw new InvalidArgumentException('Invalid message cursor.');
    return (int)$value;
}

function support_api_error(Throwable $error): never
{
    if ($error instanceof InvalidArgumentException) support_json(['ok'=>false,'error'=>$error->getMessage()],422);
    if ($error instanceof DomainException) support_json(['ok'=>false,'error'=>$error->getMessage()],409);
    error_log('Support API storage operation failed (' . get_class($error) . ').');
    support_json(['ok'=>false,'error'=>'Service unavailable. Please try again later.'],500);
}
