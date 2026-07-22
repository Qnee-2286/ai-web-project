<?php

function ensure_hr_realname_orders_table(PDO $pdo): void
{
    try {
        $pdo->exec('ALTER TABLE hr_accounts ADD COLUMN realname_order_no VARCHAR(40) NULL AFTER realname_verified_at');
    } catch (Throwable $e) {
        // Column already exists or current DB user cannot alter; the order table remains the source of truth.
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS hr_realname_orders (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_no VARCHAR(40) NOT NULL UNIQUE,
        hr_id INT UNSIGNED NOT NULL,
        biz_token VARCHAR(160) NULL,
        redirect_url TEXT NULL,
        status ENUM("pending","verified","failed","expired") NOT NULL DEFAULT "pending",
        id_card_hash CHAR(64) NULL,
        tencent_request_id VARCHAR(120) NULL,
        tencent_rule_id VARCHAR(80) NULL,
        fail_reason VARCHAR(500) NULL,
        state VARCHAR(64) NOT NULL,
        started_at DATETIME NULL,
        verified_at DATETIME NULL,
        expires_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_hr_realname_hr (hr_id, status),
        INDEX idx_hr_realname_biz (biz_token),
        INDEX idx_hr_realname_state (state)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function generate_realname_order_no(PDO $pdo): string
{
    for ($i = 0; $i < 5; $i++) {
        $orderNo = 'RN' . date('YmdHis') . random_int(100, 999);
        $stmt = $pdo->prepare('SELECT id FROM hr_realname_orders WHERE order_no=? LIMIT 1');
        $stmt->execute([$orderNo]);
        if (!$stmt->fetch()) {
            return $orderNo;
        }
    }
    return 'RN' . date('YmdHis') . bin2hex(random_bytes(3));
}

function realname_id_card_hash(array $config, string $idCard): string
{
    $secret = $config['realname']['callback_secret']
        ?? $config['app']['session_name']
        ?? 'hi_interview_realname';
    return hash_hmac('sha256', strtoupper($idCard), (string)$secret);
}

function latest_hr_realname_order(PDO $pdo, int $hrId): ?array
{
    ensure_hr_realname_orders_table($pdo);
    $stmt = $pdo->prepare('SELECT * FROM hr_realname_orders WHERE hr_id=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$hrId]);
    return $stmt->fetch() ?: null;
}

function parse_tencent_faceid_text(array $response): array
{
    $text = $response['Text'] ?? [];
    if (is_string($text)) {
        $decoded = json_decode($text, true);
        $text = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($text)) {
        $text = [];
    }
    $errCode = $text['ErrCode'] ?? null;
    $liveStatus = $text['LiveStatus'] ?? null;
    $compareStatus = $text['Comparestatus'] ?? ($text['CompareStatus'] ?? null);
    $success = (string)$errCode === '0' && (string)$liveStatus === '0' && (string)$compareStatus === '0';
    $hasResult = $errCode !== null || $liveStatus !== null || $compareStatus !== null;
    $failReason = $text['ErrMsg'] ?? $text['LiveMsg'] ?? $text['Comparemsg'] ?? $text['CompareMsg'] ?? null;
    return [
        'has_result' => $hasResult,
        'success' => $success,
        'fail_reason' => $failReason,
        'safe_raw' => [
            'request_id' => $response['RequestId'] ?? '',
            'err_code' => $errCode,
            'err_msg' => $text['ErrMsg'] ?? null,
            'live_status' => $liveStatus,
            'live_msg' => $text['LiveMsg'] ?? null,
            'compare_status' => $compareStatus,
            'compare_msg' => $text['Comparemsg'] ?? ($text['CompareMsg'] ?? null),
        ],
    ];
}

function update_hr_realname_from_tencent(PDO $pdo, array $config, array $order): array
{
    if (($order['status'] ?? '') !== 'pending') {
        return $order;
    }
    if (!empty($order['expires_at']) && strtotime($order['expires_at']) < time()) {
        $stmt = $pdo->prepare('UPDATE hr_realname_orders SET status="expired", fail_reason="实名核身链接已过期", updated_at=NOW() WHERE id=?');
        $stmt->execute([$order['id']]);
        $order['status'] = 'expired';
        $order['fail_reason'] = '实名核身链接已过期';
        return $order;
    }
    if (empty($order['biz_token'])) {
        return $order;
    }
    try {
        $response = tencent_get_detect_info($config['realname'], $order['biz_token']);
        $parsed = parse_tencent_faceid_text($response);
        if (!$parsed['has_result']) {
            return $order;
        }
        $status = $parsed['success'] ? 'verified' : 'failed';
        $failReason = $parsed['success'] ? null : ($parsed['fail_reason'] ?: '腾讯云实名核身未通过');
        $stmt = $pdo->prepare('UPDATE hr_realname_orders SET status=?, fail_reason=?, verified_at=IF(?="verified",NOW(),verified_at), updated_at=NOW() WHERE id=?');
        $stmt->execute([$status, $failReason, $status, $order['id']]);
        if ($parsed['success']) {
            try {
                $hrUpdate = $pdo->prepare('UPDATE hr_accounts SET realname_status="verified", realname_verified_at=NOW(), realname_order_no=?, updated_at=NOW() WHERE id=?');
                $hrUpdate->execute([$order['order_no'], $order['hr_id']]);
            } catch (Throwable $e) {
                $hrUpdate = $pdo->prepare('UPDATE hr_accounts SET realname_status="verified", realname_verified_at=NOW(), updated_at=NOW() WHERE id=?');
                $hrUpdate->execute([$order['hr_id']]);
            }
        } else {
            $hrUpdate = $pdo->prepare('UPDATE hr_accounts SET realname_status="failed", updated_at=NOW() WHERE id=?');
            $hrUpdate->execute([$order['hr_id']]);
        }
        $order['status'] = $status;
        $order['fail_reason'] = $failReason;
        return $order;
    } catch (Throwable $e) {
        $message = $e->getMessage();
        if (stripos($message, 'expired') !== false || stripos($message, 'BizToken') !== false) {
            $stmt = $pdo->prepare('UPDATE hr_realname_orders SET fail_reason=?, updated_at=NOW() WHERE id=?');
            $stmt->execute([substr($message, 0, 500), $order['id']]);
        }
        return $order;
    }
}
