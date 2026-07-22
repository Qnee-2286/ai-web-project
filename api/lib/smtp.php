<?php
function smtp_read($socket): string
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

function smtp_expect($socket, array $codes, string $step): string
{
    $response = smtp_read($socket);
    $code = substr($response, 0, 3);
    if (!in_array($code, $codes, true)) {
        throw new RuntimeException('SMTP ' . $step . ' failed: ' . trim($response));
    }
    return $response;
}

function smtp_command($socket, string $command, array $codes, string $step): string
{
    fwrite($socket, $command . "\r\n");
    return smtp_expect($socket, $codes, $step);
}

function smtp_header_text(string $text): string
{
    if (preg_match('/^[\x20-\x7E]*$/', $text)) {
        return $text;
    }
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

function smtp_address(string $email, string $name = ''): string
{
    if ($name === '') {
        return '<' . $email . '>';
    }
    return smtp_header_text($name) . ' <' . $email . '>';
}

function send_email_via_smtp(array $email, string $to, string $code): void
{
    $host = $email['smtp_host'] ?? '';
    $port = (int)($email['smtp_port'] ?? 465);
    $user = $email['smtp_user'] ?? '';
    $password = $email['smtp_password'] ?? '';
    $from = $email['from'] ?? $user;
    $fromName = $email['from_name'] ?? ($email['from_alias'] ?? 'Hongze Digital');
    $subject = $email['subject'] ?? 'Hongze Digital verification code';
    $secure = strtolower((string)($email['smtp_secure'] ?? 'ssl'));

    if ($host === '' || $user === '' || $password === '' || $from === '') {
        throw new RuntimeException('SMTP email is not configured');
    }

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $socket = stream_socket_client($remote, $errno, $errstr, 12, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        throw new RuntimeException('SMTP connection failed: ' . $errstr);
    }
    stream_set_timeout($socket, 12);

    try {
        smtp_expect($socket, ['220'], 'connect');
        smtp_command($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), ['250'], 'ehlo');
        if ($secure === 'tls') {
            smtp_command($socket, 'STARTTLS', ['220'], 'starttls');
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP TLS enable failed');
            }
            smtp_command($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), ['250'], 'ehlo tls');
        }
        smtp_command($socket, 'AUTH LOGIN', ['334'], 'auth');
        smtp_command($socket, base64_encode($user), ['334'], 'username');
        smtp_command($socket, base64_encode($password), ['235'], 'password');
        smtp_command($socket, 'MAIL FROM:<' . $from . '>', ['250'], 'mail from');
        smtp_command($socket, 'RCPT TO:<' . $to . '>', ['250', '251'], 'rcpt to');
        smtp_command($socket, 'DATA', ['354'], 'data');

        $htmlBody = '<p>您的验证码为：<strong>' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</strong></p><p>验证码10分钟内有效，请勿泄露给他人。</p>';
        $textBody = "您的验证码为：{$code}\r\n验证码10分钟内有效，请勿泄露给他人。";
        $boundary = '=_hi_interview_' . bin2hex(random_bytes(8));
        $headers = [
            'Date: ' . date('r'),
            'From: ' . smtp_address($from, $fromName),
            'To: <' . $to . '>',
            'Subject: ' . smtp_header_text($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $message = implode("\r\n", $headers) . "\r\n\r\n";
        $message .= '--' . $boundary . "\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($textBody));
        $message .= '--' . $boundary . "\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($htmlBody));
        $message .= '--' . $boundary . "--\r\n";
        $message = str_replace("\r\n.", "\r\n..", $message);

        fwrite($socket, $message . "\r\n.\r\n");
        try {
            smtp_expect($socket, ['250'], 'send');
        } catch (Throwable $e) {
            error_log('SMTP send acknowledgement warning: ' . $e->getMessage());
        }
        try {
            smtp_command($socket, 'QUIT', ['221'], 'quit');
        } catch (Throwable $e) {
            error_log('SMTP quit warning: ' . $e->getMessage());
        }
    } finally {
        fclose($socket);
    }
}

/**
 * 通用邮件发送（自定义主题和正文）
 * 供订单通知、发票通知等业务场景使用。
 */
function send_notification_email(array $email, string $to, string $subject, string $htmlBody, string $textBody = ''): void
{
    $host = $email['smtp_host'] ?? '';
    $port = (int)($email['smtp_port'] ?? 465);
    $user = $email['smtp_user'] ?? '';
    $password = $email['smtp_password'] ?? '';
    $from = $email['from'] ?? $user;
    $fromName = $email['from_name'] ?? ($email['from_alias'] ?? '泓泽数字');
    $secure = strtolower((string)($email['smtp_secure'] ?? 'ssl'));

    if ($host === '' || $user === '' || $password === '' || $from === '') {
        throw new RuntimeException('SMTP email is not configured');
    }

    if ($textBody === '') {
        $textBody = strip_tags($htmlBody);
    }

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $socket = stream_socket_client($remote, $errno, $errstr, 12, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        throw new RuntimeException('SMTP connection failed: ' . $errstr);
    }
    stream_set_timeout($socket, 12);

    try {
        smtp_expect($socket, ['220'], 'connect');
        smtp_command($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), ['250'], 'ehlo');
        if ($secure === 'tls') {
            smtp_command($socket, 'STARTTLS', ['220'], 'starttls');
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP TLS enable failed');
            }
            smtp_command($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), ['250'], 'ehlo tls');
        }
        smtp_command($socket, 'AUTH LOGIN', ['334'], 'auth');
        smtp_command($socket, base64_encode($user), ['334'], 'username');
        smtp_command($socket, base64_encode($password), ['235'], 'password');
        smtp_command($socket, 'MAIL FROM:<' . $from . '>', ['250'], 'mail from');
        smtp_command($socket, 'RCPT TO:<' . $to . '>', ['250', '251'], 'rcpt to');
        smtp_command($socket, 'DATA', ['354'], 'data');

        $boundary = '=_hi_interview_' . bin2hex(random_bytes(8));
        $headers = [
            'Date: ' . date('r'),
            'From: ' . smtp_address($from, $fromName),
            'To: <' . $to . '>',
            'Subject: ' . smtp_header_text($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $message = implode("\r\n", $headers) . "\r\n\r\n";
        $message .= '--' . $boundary . "\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($textBody));
        $message .= '--' . $boundary . "\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($htmlBody));
        $message .= '--' . $boundary . "--\r\n";
        $message = str_replace("\r\n.", "\r\n..", $message);

        fwrite($socket, $message . "\r\n.\r\n");
        try {
            smtp_expect($socket, ['250'], 'send');
        } catch (Throwable $e) {
            error_log('SMTP send acknowledgement warning: ' . $e->getMessage());
        }
        try {
            smtp_command($socket, 'QUIT', ['221'], 'quit');
        } catch (Throwable $e) {
            error_log('SMTP quit warning: ' . $e->getMessage());
        }
    } finally {
        fclose($socket);
    }
}
