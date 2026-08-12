<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/smtp-mailer.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.'], JSON_UNESCAPED_SLASHES);
    exit;
}

function ticket_storage_dir(): string
{
    $storageDir = env_value('APP_STORAGE_DIR');
    if (!is_string($storageDir) || $storageDir === '' || $storageDir === '/absolute/path/outside/public/storage') {
        $storageDir = dirname(__DIR__) . '/storage';
    }

    return rtrim($storageDir, '/\\');
}

function ticket_clean_string(mixed $value, int $maxLength): string
{
    $value = trim((string) $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    $value = str_replace(["\r", "\n"], ' ', $value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }

    return substr($value, 0, $maxLength);
}

function ticket_clean_message(mixed $value, int $maxLength): string
{
    $value = trim((string) $value);
    $value = preg_replace("/\r\n|\r/", "\n", $value) ?? $value;
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }

    return substr($value, 0, $maxLength);
}

function ticket_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $header) {
        $value = $_SERVER[$header] ?? '';
        if (!is_string($value) || $value === '') {
            continue;
        }

        $ip = trim(explode(',', $value)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return 'unknown';
}

function ticket_send_email(string $recipient, string $subject, string $body, array $headers, string $fromEmail): bool
{
    $headerText = implode("\r\n", $headers);
    $safeFromEmail = filter_var($fromEmail, FILTER_VALIDATE_EMAIL) ? $fromEmail : '';

    if ($safeFromEmail !== '' && @mail($recipient, $subject, $body, $headerText, '-f' . $safeFromEmail)) {
        return true;
    }

    if (@mail($recipient, $subject, $body, $headerText)) {
        return true;
    }

    return @mail($recipient, $subject, $body, implode("\n", $headers));
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw !== false ? $raw : '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$fullName = ticket_clean_string($payload['full_name'] ?? '', 120);
$contact = ticket_clean_string($payload['contact'] ?? '', 120);
$topic = ticket_clean_string($payload['topic'] ?? '', 120);
$problem = ticket_clean_message($payload['problem'] ?? '', 5000);
$pageUrl = ticket_clean_string($payload['page_url'] ?? ($_SERVER['HTTP_REFERER'] ?? ''), 300);
$topicLabels = [
    'login' => 'Login & Forgotten Password',
    'deposit' => 'GCash / Maya Deposit Delay',
    'withdrawal' => 'Cashout & Withdrawal Inquiry',
    'apk' => 'APK App Installation Issue',
    'kyc' => 'KYC Verification Check',
    'promo' => 'Promotion & Redemption Bonus',
    'other' => 'General Question',
];
$topicLabel = $topicLabels[$topic] ?? $topic;

if ($fullName === '' || $contact === '' || $topic === '' || $problem === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please complete all ticket fields.'], JSON_UNESCAPED_SLASHES);
    exit;
}

$recipient = env_value('TICKET_RECIPIENT_EMAIL') ?? 'lanialmerogphi@gmail.com';
$subject = 'Gperya ticket';
$body = "Gperya ticket\n\n"
    . "Fullname: {$fullName}\n"
    . "Gcash/Mobile No: {$contact}\n"
    . "Inquiry topic: {$topicLabel}\n"
    . "Problem:\n{$problem}\n\n"
    . "Submitted: " . gmdate('c') . "\n"
    . "Page: {$pageUrl}\n"
    . "IP: " . ticket_client_ip() . "\n";

$fromEmail = env_value('TICKET_FROM_EMAIL') ?? 'noreply@gperya-apk.com';
$headers = [
    'From: Gperya Ticket <' . $fromEmail . '>',
    'Reply-To: ' . $recipient,
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: PHP/' . PHP_VERSION,
];

$ticket = [
    'time' => gmdate('c'),
    'fullName' => $fullName,
    'contact' => $contact,
    'topic' => $topicLabel,
    'problem' => $problem,
    'pageUrl' => $pageUrl,
    'ip' => ticket_client_ip(),
    'userAgent' => ticket_clean_string($_SERVER['HTTP_USER_AGENT'] ?? '', 300),
];

$storageDir = ticket_storage_dir();
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0700, true);
}

if (is_dir($storageDir)) {
    $path = $storageDir . '/customer-tickets.json';
    $handle = @fopen($path, 'c+');
    if ($handle !== false) {
        try {
            flock($handle, LOCK_EX);
            $existing = stream_get_contents($handle);
            $data = json_decode($existing !== false ? $existing : '', true);
            if (!is_array($data)) {
                $data = ['totalTickets' => 0, 'recent' => []];
            }
            $data['totalTickets'] = (int) ($data['totalTickets'] ?? 0) + 1;
            $data['updatedAt'] = $ticket['time'];
            $data['recent'] ??= [];
            $data['recent'][] = $ticket;
            if (count($data['recent']) > 500) {
                $data['recent'] = array_slice($data['recent'], -500);
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }
}

$smtpResult = ['ok' => false, 'error' => 'SMTP is not configured.'];
if (smtp_is_configured()) {
    $smtpResult = smtp_send_mail($recipient, $subject, $body, $fromEmail, 'Gperya Ticket');
}

$sent = (bool) ($smtpResult['ok'] ?? false);
if (!$sent) {
    $sent = ticket_send_email($recipient, $subject, $body, $headers, $fromEmail);
}
if (!$sent) {
    $lastError = error_get_last();
    $smtpError = is_string($smtpResult['error'] ?? null) ? $smtpResult['error'] : '';
    error_log('Customer ticket mail failed for ' . $recipient . ($smtpError !== '' ? ': ' . $smtpError : '') . ($lastError && isset($lastError['message']) ? ' | ' . $lastError['message'] : ''));
    echo json_encode(['ok' => true, 'emailSent' => false, 'warning' => 'Ticket was saved, but email delivery is unavailable on this server.'], JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(['ok' => true, 'emailSent' => true, 'mailer' => smtp_is_configured() ? 'smtp' : 'mail'], JSON_UNESCAPED_SLASHES);
