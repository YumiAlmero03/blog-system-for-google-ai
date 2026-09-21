<?php
declare(strict_types=1);

function smtp_env(string $name, ?string $default = null): ?string
{
    $value = env_value($name);
    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
}

function smtp_is_configured(): bool
{
    return smtp_env('SMTP_HOST') !== null;
}

function smtp_read_line($socket): string
{
    $line = fgets($socket, 515);
    if ($line === false) {
        throw new RuntimeException('SMTP server closed the connection.');
    }

    return $line;
}

function smtp_read_response($socket): array
{
    $response = '';
    do {
        $line = smtp_read_line($socket);
        $response .= $line;
        $code = substr($line, 0, 3);
        $more = isset($line[3]) && $line[3] === '-';
    } while ($more);

    return [(int) $code, trim($response)];
}

function smtp_command($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");
    [$code, $response] = smtp_read_response($socket);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP command failed: ' . $response);
    }

    return $response;
}

function smtp_format_address(string $email, string $name = ''): string
{
    $email = trim($email);
    $name = trim(str_replace(["\r", "\n", '"'], ' ', $name));
    if ($name === '') {
        return '<' . $email . '>';
    }

    return '"' . $name . '" <' . $email . '>';
}

function smtp_dot_stuff(string $message): string
{
    $message = preg_replace("/\r\n|\r|\n/", "\r\n", $message) ?? $message;
    return preg_replace('/^\./m', '..', $message) ?? $message;
}

function smtp_subject_header(string $subject): string
{
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    }

    return '=?UTF-8?B?' . base64_encode($subject) . '?=';
}

function smtp_send_mail(string $to, string $subject, string $body, string $fromEmail, string $fromName = 'Ticket'): array
{
    $host = smtp_env('SMTP_HOST');
    if ($host === null) {
        return ['ok' => false, 'error' => 'SMTP_HOST is not configured.'];
    }

    $port = (int) (smtp_env('SMTP_PORT', '587') ?? '587');
    $username = smtp_env('SMTP_USERNAME');
    $password = smtp_env('SMTP_PASSWORD');
    $encryption = strtolower(smtp_env('SMTP_ENCRYPTION', $port === 465 ? 'ssl' : 'tls') ?? 'tls');
    $timeout = (int) (smtp_env('SMTP_TIMEOUT', '15') ?? '15');
    $fromEmail = smtp_env('SMTP_FROM_EMAIL', $fromEmail) ?? $fromEmail;
    $fromName = smtp_env('SMTP_FROM_NAME', $fromName) ?? $fromName;
    $replyTo = smtp_env('SMTP_REPLY_TO', $to) ?? $to;
    $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $transportHost = $encryption === 'ssl' ? 'ssl://' . $host : $host;

    $socket = @fsockopen($transportHost, $port, $errno, $errstr, $timeout);
    if (!is_resource($socket)) {
        return ['ok' => false, 'error' => 'SMTP connection failed: ' . $errstr . ' (' . $errno . ')'];
    }

    stream_set_timeout($socket, $timeout);

    try {
        [$code, $response] = smtp_read_response($socket);
        if ($code !== 220) {
            throw new RuntimeException('SMTP greeting failed: ' . $response);
        }

        smtp_command($socket, 'EHLO ' . $serverName, [250]);

        if ($encryption === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP STARTTLS negotiation failed.');
            }
            smtp_command($socket, 'EHLO ' . $serverName, [250]);
        }

        if ($username !== null && $password !== null) {
            smtp_command($socket, 'AUTH LOGIN', [334]);
            smtp_command($socket, base64_encode($username), [334]);
            smtp_command($socket, base64_encode($password), [235]);
        }

        smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);

        $headers = [
            'From: ' . smtp_format_address($fromEmail, $fromName),
            'To: <' . $to . '>',
            'Reply-To: <' . $replyTo . '>',
            'Subject: ' . smtp_subject_header($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Date: ' . date(DATE_RFC2822),
        ];

        fwrite($socket, smtp_dot_stuff(implode("\r\n", $headers) . "\r\n\r\n" . $body) . "\r\n.\r\n");
        [$code, $response] = smtp_read_response($socket);
        if (!in_array($code, [250], true)) {
            throw new RuntimeException('SMTP message was rejected: ' . $response);
        }

        smtp_command($socket, 'QUIT', [221, 250]);
        fclose($socket);

        return ['ok' => true, 'error' => null];
    } catch (Throwable $exception) {
        @fwrite($socket, "QUIT\r\n");
        fclose($socket);
        return ['ok' => false, 'error' => $exception->getMessage()];
    }
}
