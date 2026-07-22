<?php
function json_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) {
        respond(false, 'Invalid request body', [], 400);
    }
    return $data;
}

function respond(bool $ok, string $message = '', array $data = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ok, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function ensure_interview_recordings_schema(PDO $pdo): void
{
    if (!table_exists($pdo, 'candidate_interview_recordings')) {
        return;
    }
    $columns = [
        'question_id' => 'ADD COLUMN question_id INT UNSIGNED NULL AFTER candidate_id',
        'question_text' => 'ADD COLUMN question_text TEXT NULL AFTER question_id',
        'question_type' => 'ADD COLUMN question_type VARCHAR(40) NULL AFTER question_text',
        'audio_mime_type' => 'ADD COLUMN audio_mime_type VARCHAR(120) NOT NULL DEFAULT "audio/webm" AFTER audio_object_key',
        'audio_size' => 'ADD COLUMN audio_size INT UNSIGNED NOT NULL DEFAULT 0 AFTER audio_mime_type',
        'audio_seconds' => 'ADD COLUMN audio_seconds INT UNSIGNED NULL AFTER audio_size',
        'oss_etag' => 'ADD COLUMN oss_etag VARCHAR(120) NULL AFTER audio_seconds',
        'transcript_status' => 'ADD COLUMN transcript_status ENUM("pending","processing","completed","failed") NOT NULL DEFAULT "pending" AFTER oss_etag',
        'transcript_text' => 'ADD COLUMN transcript_text MEDIUMTEXT NULL AFTER transcript_status',
    ];
    foreach ($columns as $column => $alter) {
        if (!column_exists($pdo, 'candidate_interview_recordings', $column)) {
            $pdo->exec('ALTER TABLE candidate_interview_recordings ' . $alter);
        }
    }
}

function ai_report_text_value($value, string $fallback = ''): string
{
    if ($value === null) {
        return $fallback;
    }
    if (is_string($value)) {
        $text = trim($value);
        if ($text === '' || strcasecmp($text, 'array') === 0) {
            return $fallback;
        }
        return $text;
    }
    if (is_bool($value)) {
        return $value ? '是' : '否';
    }
    if (is_int($value) || is_float($value)) {
        return trim((string)$value);
    }
    if (is_array($value)) {
        $parts = [];
        foreach ($value as $key => $item) {
            $text = ai_report_text_value($item, '');
            if ($text === '') {
                continue;
            }
            if (!is_int($key) && !ctype_digit((string)$key)) {
                $text = $key . '：' . $text;
            }
            $parts[] = $text;
        }
        return $parts ? implode('；', $parts) : $fallback;
    }
    return $fallback;
}

function ai_report_keywords_value($value): string
{
    $text = ai_report_text_value($value, '');
    $text = preg_replace('/\s+/u', ' ', $text);
    return mb_substr(trim((string)$text), 0, 500, 'UTF-8');
}

function ai_report_recommendation_value($value, string $fallback = 'hold'): string
{
    $text = strtolower(ai_report_text_value($value, $fallback));
    return in_array($text, ['continue', 'hold', 'reject'], true) ? $text : $fallback;
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(false, 'POST method required', [], 405);
    }
}

function validate_phone(string $phone): bool
{
    return (bool)preg_match('/^1[3-9]\d{9}$/', $phone);
}

function validate_email(string $email): bool
{
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function validate_cn_id_card(string $id): bool
{
    return (bool)preg_match('/^\d{17}[\dXx]$/', $id);
}

function mask_phone(string $phone): string
{
    return preg_replace('/(\d{3})\d{4}(\d{4})/', '$1****$2', $phone);
}

function mask_id_card(string $id): string
{
    $len = strlen($id);
    if ($len < 8) {
        return '***';
    }
    return substr($id, 0, 3) . str_repeat('*', max(0, $len - 7)) . substr($id, -4);
}

function channel_provider(array $config, string $channel): string
{
    if ($channel === 'sms') {
        return $config['sms']['provider'] ?? 'mock';
    }
    if ($channel === 'email') {
        return $config['email']['provider'] ?? 'mock';
    }
    return 'mock';
}

function random_code(array $config, string $channel = ''): string
{
    if (!empty($config['app']['dev_mode']) || channel_provider($config, $channel) === 'mock') {
        return (string)($config['app']['dev_code'] ?? '123456');
    }
    return (string)random_int(100000, 999999);
}

function create_verification_code(PDO $pdo, array $config, string $channel, string $target, string $purpose): array
{
    $code = random_code($config, $channel);
    $stmt = $pdo->prepare('INSERT INTO verification_codes(channel, target, purpose, code_hash, expires_at, created_at) VALUES(?,?,?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE),NOW())');
    $stmt->execute([$channel, $target, $purpose, verification_code_hash($code)]);
    remember_session_verification_code($channel, $target, $purpose, $code);
    remember_cookie_verification_code($channel, $target, $purpose, $code);
    return ['code' => $code, 'expires_in' => 600];
}

function verification_session_key(string $channel, string $target, string $purpose): string
{
    return $channel . '|' . strtolower(trim($target)) . '|' . $purpose;
}

function remember_session_verification_code(string $channel, string $target, string $purpose, string $code): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION['verification_codes'] = $_SESSION['verification_codes'] ?? [];
    $_SESSION['verification_codes'][verification_session_key($channel, $target, $purpose)] = [
        'hash' => verification_code_hash($code),
        'expires_at' => time() + 600,
    ];
}

function verification_code_hash(string $code): string
{
    return 'sha1:' . sha1($code);
}

function verification_cookie_secret(): string
{
    global $config;
    return (string)(
        $config['app']['key']
        ?? $config['app']['session_name']
        ?? $config['db']['password']
        ?? 'hi-interview'
    );
}

function verification_cookie_signature(array $payload): string
{
    $base = implode('|', [
        $payload['channel'] ?? '',
        $payload['target'] ?? '',
        $payload['purpose'] ?? '',
        $payload['hash'] ?? '',
        $payload['expires_at'] ?? '',
    ]);
    return hash_hmac('sha256', $base, verification_cookie_secret());
}

function verification_cookie_name(): string
{
    return 'HI_VERIFY_CODE';
}

function remember_cookie_verification_code(string $channel, string $target, string $purpose, string $code): void
{
    $payload = [
        'channel' => $channel,
        'target' => strtolower(trim($target)),
        'purpose' => $purpose,
        'hash' => verification_code_hash($code),
        'expires_at' => time() + 600,
    ];
    $payload['sig'] = verification_cookie_signature($payload);
    $value = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(verification_cookie_name(), $value, [
        'expires' => $payload['expires_at'],
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function verification_code_matches(string $code, string $storedHash): bool
{
    $stableHash = verification_code_hash($code);
    if (hash_equals($stableHash, $storedHash)) {
        return true;
    }
    return password_verify($code, $storedHash);
}

function verify_session_code(string $channel, string $target, string $purpose, string $code): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['verification_codes'])) {
        return false;
    }
    $key = verification_session_key($channel, $target, $purpose);
    $record = $_SESSION['verification_codes'][$key] ?? null;
    if (!$record || (int)($record['expires_at'] ?? 0) < time()) {
        unset($_SESSION['verification_codes'][$key]);
        return false;
    }
    if (!verification_code_matches($code, (string)$record['hash'])) {
        return false;
    }
    unset($_SESSION['verification_codes'][$key]);
    return true;
}

function verify_cookie_code(string $channel, string $target, string $purpose, string $code): bool
{
    $raw = $_COOKIE[verification_cookie_name()] ?? '';
    if ($raw === '') {
        return false;
    }
    $base64 = strtr($raw, '-_', '+/');
    $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
    $json = base64_decode($base64, true);
    $payload = json_decode($json ?: '', true);
    if (!is_array($payload)) {
        return false;
    }
    $expected = [
        'channel' => $channel,
        'target' => strtolower(trim($target)),
        'purpose' => $purpose,
    ];
    if (($payload['channel'] ?? '') !== $expected['channel'] || ($payload['target'] ?? '') !== $expected['target'] || ($payload['purpose'] ?? '') !== $expected['purpose']) {
        return false;
    }
    if ((int)($payload['expires_at'] ?? 0) < time()) {
        return false;
    }
    if (!hash_equals((string)($payload['sig'] ?? ''), verification_cookie_signature($payload))) {
        return false;
    }
    if (!verification_code_matches($code, (string)($payload['hash'] ?? ''))) {
        return false;
    }
    setcookie(verification_cookie_name(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    return true;
}

function dispatch_verification_code(array $config, string $channel, string $target, string $code): void
{
    if (!empty($config['app']['dev_mode'])) {
        return;
    }
    $provider = channel_provider($config, $channel);
    if ($provider === 'mock') {
        return;
    }
    if ($channel === 'sms' && $provider === 'aliyun') {
        send_sms_via_aliyun($config['sms'], $target, $code);
        return;
    }
    if ($channel === 'email' && $provider === 'aliyun') {
        send_email_via_aliyun_directmail($config['email'], $target, $code);
        return;
    }
    if ($channel === 'email' && $provider === 'smtp') {
        send_email_via_smtp($config['email'], $target, $code);
        return;
    }
    throw new RuntimeException(strtoupper($channel) . ' provider is not configured: ' . $provider);
}

function verify_code(PDO $pdo, string $channel, string $target, string $purpose, string $code): bool
{
    if (verify_session_code($channel, $target, $purpose, $code)) {
        return true;
    }
    if (verify_cookie_code($channel, $target, $purpose, $code)) {
        return true;
    }
    $stmt = $pdo->prepare('SELECT id, code_hash FROM verification_codes WHERE channel=? AND target=? AND purpose=? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 10');
    $stmt->execute([$channel, $target, $purpose]);
    while ($row = $stmt->fetch()) {
        if (verification_code_matches($code, $row['code_hash'])) {
            $update = $pdo->prepare('UPDATE verification_codes SET used_at=NOW() WHERE id=? AND used_at IS NULL');
            $update->execute([$row['id']]);
            return true;
        }
    }
    return false;
}

function verification_code_debug(PDO $pdo, string $channel, string $target, string $purpose, string $code): array
{
    $key = verification_session_key($channel, $target, $purpose);
    $sessionRecord = $_SESSION['verification_codes'][$key] ?? null;
    $sessionState = 'missing';
    if ($sessionRecord) {
        $sessionState = ((int)($sessionRecord['expires_at'] ?? 0) >= time()) ? 'present' : 'expired';
        if ($sessionState === 'present' && verification_code_matches($code, (string)$sessionRecord['hash'])) {
            $sessionState = 'matched';
        }
    }

    $cookieState = verify_cookie_code($channel, $target, $purpose, $code) ? 'matched' : (empty($_COOKIE[verification_cookie_name()]) ? 'missing' : 'present');
    try {
        $stmt = $pdo->prepare('SELECT id, code_hash, expires_at, used_at FROM verification_codes WHERE channel=? AND target=? AND purpose=? ORDER BY id DESC LIMIT 5');
        $stmt->execute([$channel, $target, $purpose]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
    }
    $dbMatches = 0;
    foreach ($rows as $row) {
        if (empty($row['used_at']) && verification_code_matches($code, $row['code_hash'])) {
            $dbMatches += 1;
        }
    }
    $latest = $rows[0] ?? [];
    return [
        'session' => $sessionState,
        'cookie' => $cookieState,
        'db_rows' => count($rows),
        'db_matches' => $dbMatches,
        'latest_id' => $latest['id'] ?? null,
        'latest_used' => empty($latest['used_at']) ? 'no' : 'yes',
        'latest_created' => null,
        'latest_expires' => $latest['expires_at'] ?? null,
    ];
}

function current_hr(PDO $pdo): ?array
{
    $id = $_SESSION['hr_id'] ?? null;
    if (!$id) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM hr_accounts WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function require_hr(PDO $pdo): array
{
    $hr = current_hr($pdo);
    if (!$hr) {
        respond(false, 'HR login required', ['need_login' => true], 401);
    }
    return $hr;
}

function candidate_by_token(PDO $pdo, string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM candidates WHERE invite_token=? LIMIT 1');
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

function ensure_candidate(PDO $pdo, string $token, string $phone): array
{
    $candidate = candidate_by_token($pdo, $token);
    if ($candidate) {
        $stmt = $pdo->prepare('UPDATE candidates SET phone=?, phone_verified_at=NOW(), updated_at=NOW() WHERE id=?');
        $stmt->execute([$phone, $candidate['id']]);
        $candidate['phone'] = $phone;
        return $candidate;
    }
    $stmt = $pdo->prepare('INSERT INTO candidates(invite_token, phone, phone_verified_at, realname_status, created_at, updated_at) VALUES(?,?,NOW(),"pending",NOW(),NOW())');
    $stmt->execute([$token, $phone]);
    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM candidates WHERE id=?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function generate_token(): string
{
    return bin2hex(random_bytes(16));
}

function next_hr_job_serial(PDO $pdo, int $hrId): int
{
    if (!table_exists($pdo, 'hr_job_number_sequences')) {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(job_serial), 0) + 1 FROM hr_jobs WHERE hr_id=?');
        $stmt->execute([$hrId]);
        return max(1, (int)$stmt->fetchColumn());
    }

    $insert = $pdo->prepare('INSERT IGNORE INTO hr_job_number_sequences(hr_id, next_serial, updated_at) VALUES(?, 1, NOW())');
    $insert->execute([$hrId]);

    $stmt = $pdo->prepare('SELECT next_serial FROM hr_job_number_sequences WHERE hr_id=? FOR UPDATE');
    $stmt->execute([$hrId]);
    $serial = max(1, (int)$stmt->fetchColumn());

    $update = $pdo->prepare('UPDATE hr_job_number_sequences SET next_serial=?, updated_at=NOW() WHERE hr_id=?');
    $update->execute([$serial + 1, $hrId]);

    return $serial;
}

function job_serial_for_interview(PDO $pdo, int $hrId, int $jobId): int
{
    if (!column_exists($pdo, 'hr_jobs', 'job_serial')) {
        return $jobId;
    }

    $stmt = $pdo->prepare('SELECT job_serial FROM hr_jobs WHERE id=? AND hr_id=? LIMIT 1');
    $stmt->execute([$jobId, $hrId]);
    $serial = (int)($stmt->fetchColumn() ?: 0);
    if ($serial > 0) {
        return $serial;
    }

    $serial = next_hr_job_serial($pdo, $hrId);
    $update = $pdo->prepare('UPDATE hr_jobs SET job_serial=?, updated_at=NOW() WHERE id=? AND hr_id=? AND (job_serial IS NULL OR job_serial=0)');
    $update->execute([$serial, $jobId, $hrId]);
    return $serial;
}

function next_interview_no(PDO $pdo, int $jobSerial): string
{
    $date = date('Y-m-d');
    $datePart = date('Ymd');

    if (!table_exists($pdo, 'interview_daily_sequences')) {
        return 'HI' . $datePart . '-J' . str_pad((string)$jobSerial, 3, '0', STR_PAD_LEFT) . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
    }

    $insert = $pdo->prepare('INSERT IGNORE INTO interview_daily_sequences(seq_date, next_serial, updated_at) VALUES(?, 1, NOW())');
    $insert->execute([$date]);

    $stmt = $pdo->prepare('SELECT next_serial FROM interview_daily_sequences WHERE seq_date=? FOR UPDATE');
    $stmt->execute([$date]);
    $seq = max(1, (int)$stmt->fetchColumn());

    $update = $pdo->prepare('UPDATE interview_daily_sequences SET next_serial=?, updated_at=NOW() WHERE seq_date=?');
    $update->execute([$seq + 1, $date]);

    return 'HI' . $datePart . '-J' . str_pad((string)$jobSerial, 3, '0', STR_PAD_LEFT) . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

function audit_log(PDO $pdo, ?int $hrId, string $event, string $status): void
{
    $stmt = $pdo->prepare('INSERT INTO login_logs(hr_id, event, status, ip_address, user_agent, created_at) VALUES(?,?,?,?,?,NOW())');
    $stmt->execute([$hrId, $event, $status, $_SERVER['REMOTE_ADDR'] ?? '', substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
}
