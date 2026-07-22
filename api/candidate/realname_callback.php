<?php
require_once __DIR__ . '/../bootstrap.php';
$token = trim($_GET['token'] ?? ($_SESSION['candidate_token'] ?? ''));
$candidate = candidate_by_token($pdo, $token);
$redirectBase = rtrim($config['realname']['redirect_base'] ?? 'https://hi.hongzedigital.com', '/');
$authUrl = $redirectBase . '/candidate/auth.html?token=' . urlencode($token);
$resumeUrl = $redirectBase . '/candidate/resume.html?token=' . urlencode($token);

if (!$candidate) {
    header('Location: ' . $redirectBase . '/candidate/auth.html?realname=invalid');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM authentication_records WHERE subject_type="candidate" AND subject_id=? AND provider="tencent" AND auth_type="wechat_h5_realname" AND status="pending" ORDER BY id DESC LIMIT 1');
$stmt->execute([$candidate['id']]);
$record = $stmt->fetch();
if (!$record) {
    header('Location: ' . $authUrl . '&realname=not_found');
    exit;
}

try {
    $response = tencent_get_detect_info($config['realname'], $record['transaction_id']);
    $text = $response['Text'] ?? [];
    $success = (int)($text['ErrCode'] ?? -1) === 0
        && (int)($text['LiveStatus'] ?? 0) === 0
        && (int)($text['Comparestatus'] ?? 0) === 0;
    $status = $success ? 'success' : 'failed';
    $idCardMask = !empty($text['IdCard']) ? mask_id_card((string)$text['IdCard']) : ($record['id_card_mask'] ?? null);
    $safeRaw = [
        'request_id' => $response['RequestId'] ?? '',
        'err_code' => $text['ErrCode'] ?? null,
        'err_msg' => $text['ErrMsg'] ?? null,
        'live_status' => $text['LiveStatus'] ?? null,
        'live_msg' => $text['LiveMsg'] ?? null,
        'compare_status' => $text['Comparestatus'] ?? null,
        'compare_msg' => $text['Comparemsg'] ?? null,
        'sim' => $text['Sim'] ?? null,
        'name' => $text['Name'] ?? null,
        'id_card_mask' => $idCardMask,
    ];
    $update = $pdo->prepare('UPDATE authentication_records SET status=?, id_card_mask=?, raw_response=?, updated_at=NOW() WHERE id=?');
    $update->execute([$status, $idCardMask, json_encode($safeRaw, JSON_UNESCAPED_UNICODE), $record['id']]);
    if ($success) {
        $verifiedName = trim((string)($text['Name'] ?? ''));
        if ($verifiedName === '') {
            $oldRaw = json_decode($record['raw_response'] ?? '{}', true);
            $verifiedName = trim((string)($oldRaw['name'] ?? ''));
        }
        if (column_exists($pdo, 'candidates', 'real_name') && $verifiedName !== '') {
            $candidateUpdate = $pdo->prepare('UPDATE candidates SET real_name=?, realname_status="verified", realname_verified_at=NOW(), updated_at=NOW() WHERE id=?');
            $candidateUpdate->execute([mb_substr($verifiedName, 0, 80, 'UTF-8'), $candidate['id']]);
        } else {
            $candidateUpdate = $pdo->prepare('UPDATE candidates SET realname_status="verified", realname_verified_at=NOW(), updated_at=NOW() WHERE id=?');
            $candidateUpdate->execute([$candidate['id']]);
        }
        if (!empty($candidate['candidate_account_id']) && table_exists($pdo, 'candidate_accounts')) {
            $accountUpdate = $pdo->prepare('UPDATE candidate_accounts SET realname_status="verified", realname_verified_at=NOW(), auth_provider="tencent", auth_transaction_id=?, updated_at=NOW() WHERE id=?');
            $accountUpdate->execute([$record['transaction_id'], (int)$candidate['candidate_account_id']]);
        }
        header('Location: ' . $resumeUrl . '&realname=success');
        exit;
    }
    $candidateUpdate = $pdo->prepare('UPDATE candidates SET realname_status="failed", updated_at=NOW() WHERE id=?');
    $candidateUpdate->execute([$candidate['id']]);
    header('Location: ' . $authUrl . '&realname=failed');
    exit;
} catch (Throwable $e) {
    $update = $pdo->prepare('UPDATE authentication_records SET raw_response=?, updated_at=NOW() WHERE id=?');
    $update->execute([json_encode(['callback_error' => $e->getMessage()], JSON_UNESCAPED_UNICODE), $record['id']]);
    header('Location: ' . $authUrl . '&realname=query_failed');
    exit;
}
