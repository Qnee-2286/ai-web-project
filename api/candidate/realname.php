<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$input = json_input();
$token = trim((string)($input['token'] ?? ($_SESSION['candidate_token'] ?? '')));
$candidate = candidate_by_token($pdo, $token);
if (!$candidate || (int)($candidate['id'] ?? 0) !== (int)($_SESSION['candidate_id'] ?? $candidate['id'])) {
    respond(false, '候选人链接无效或认证会话已过期', [], 401);
}
if (empty($candidate['phone_verified_at']) || empty($candidate['agreement_accepted_at'])) {
    respond(false, '请先完成手机号验证和授权确认', [], 422);
}
if (($candidate['realname_status'] ?? '') === 'verified') {
    respond(true, '已完成实名认证', [
        'candidate_token' => $token,
        'next' => 'resume.html?token=' . urlencode($token),
    ]);
}

$name = trim((string)($input['real_name'] ?? ''));
$provider = $config['realname']['provider'] ?? 'mock';

if ($provider === 'mock') {
    $transaction = 'MOCK-CAND-' . date('YmdHis') . '-' . random_int(1000, 9999);
    $stmt = $pdo->prepare('INSERT INTO authentication_records(subject_type, subject_id, provider, auth_type, status, transaction_id, created_at, updated_at) VALUES("candidate",?,?,?,?,?,NOW(),NOW())');
    $stmt->execute([(int)$candidate['id'], $provider, 'wechat_realname', 'success', $transaction]);

    if (column_exists($pdo, 'candidates', 'real_name') && $name !== '') {
        $stmt = $pdo->prepare('UPDATE candidates SET real_name=?, realname_status="verified", realname_verified_at=NOW(), updated_at=NOW() WHERE id=?');
        $stmt->execute([mb_substr($name, 0, 80, 'UTF-8'), (int)$candidate['id']]);
    } else {
        $stmt = $pdo->prepare('UPDATE candidates SET realname_status="verified", realname_verified_at=NOW(), updated_at=NOW() WHERE id=?');
        $stmt->execute([(int)$candidate['id']]);
    }
    if (!empty($candidate['candidate_account_id']) && table_exists($pdo, 'candidate_accounts')) {
        $stmt = $pdo->prepare('UPDATE candidate_accounts SET realname_status="verified", realname_verified_at=NOW(), auth_provider=?, auth_transaction_id=?, updated_at=NOW() WHERE id=?');
        $stmt->execute([$provider, $transaction, (int)$candidate['candidate_account_id']]);
    }

    respond(true, '微信实名认证成功', [
        'candidate_token' => $token,
        'next' => 'resume.html?token=' . urlencode($token),
        'transaction_id' => $transaction,
    ]);
}

if ($provider !== 'tencent') {
    respond(false, '实名认证服务暂未配置', [], 500);
}

$idCard = strtoupper(trim((string)($input['id_card'] ?? '')));
if ($name === '' || mb_strlen($name, 'UTF-8') > 32) {
    respond(false, '请填写真实姓名', [], 422);
}
if (!validate_cn_id_card($idCard)) {
    respond(false, '请填写正确的身份证号码', [], 422);
}

$reuse = $pdo->prepare('SELECT transaction_id, raw_response FROM authentication_records WHERE subject_type="candidate" AND subject_id=? AND provider="tencent" AND auth_type="wechat_h5_realname" AND status="pending" AND created_at > DATE_SUB(NOW(), INTERVAL 90 MINUTE) ORDER BY id DESC LIMIT 1');
$reuse->execute([(int)$candidate['id']]);
$old = $reuse->fetch();
if ($old) {
    $oldRaw = json_decode($old['raw_response'] ?? '{}', true);
    if (!empty($oldRaw['redirect_url'])) {
        respond(true, '请继续完成微信实名认证', [
            'candidate_token' => $token,
            'redirect_url' => $oldRaw['redirect_url'],
            'transaction_id' => $old['transaction_id'],
            'reused' => true,
        ]);
    }
}

$redirectBase = rtrim($config['realname']['redirect_base'] ?? 'https://hi.hongzedigital.com', '/');
$redirectUrl = $redirectBase . '/api/candidate/realname_callback.php?token=' . urlencode($token);
$extra = 'candidate_id=' . (int)$candidate['id'];
$response = tencent_detect_auth($config['realname'], $name, $idCard, $redirectUrl, $extra);
$bizToken = $response['BizToken'] ?? '';
$url = $response['Url'] ?? '';
if ($bizToken === '' || $url === '') {
    respond(false, '腾讯云实名核身链接生成失败', ['response' => $response], 500);
}

$raw = [
    'request_id' => $response['RequestId'] ?? '',
    'redirect_url' => $url,
    'name' => $name,
    'id_card_mask' => mask_id_card($idCard),
];
$stmt = $pdo->prepare('INSERT INTO authentication_records(subject_type, subject_id, provider, auth_type, status, transaction_id, id_card_mask, raw_response, created_at, updated_at) VALUES("candidate",?,?,?,?,?,?,?,NOW(),NOW())');
$stmt->execute([(int)$candidate['id'], 'tencent', 'wechat_h5_realname', 'pending', $bizToken, mask_id_card($idCard), json_encode($raw, JSON_UNESCAPED_UNICODE)]);
$stmt = $pdo->prepare('UPDATE candidates SET realname_status="pending", updated_at=NOW() WHERE id=?');
$stmt->execute([(int)$candidate['id']]);

respond(true, '请继续完成微信实名认证', [
    'candidate_token' => $token,
    'redirect_url' => $url,
    'transaction_id' => $bizToken,
]);
