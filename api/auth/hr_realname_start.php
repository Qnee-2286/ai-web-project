<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$hr = require_hr($pdo);
$input = json_input();
$name = trim($input['name'] ?? '');
$idCard = strtoupper(trim($input['id_card'] ?? ''));
$agreement = !empty($input['agreement']);

if ($name === '' || mb_strlen($name, 'UTF-8') > 32) {
    respond(false, '请填写真实姓名', [], 422);
}
if (!validate_cn_id_card($idCard)) {
    respond(false, '请填写正确的身份证号', [], 422);
}
if (!$agreement) {
    respond(false, '请先勾选实名认证授权', [], 422);
}
if (($hr['realname_status'] ?? '') === 'verified') {
    respond(true, 'HR已完成实名认证', [
        'realname_status' => 'verified',
        'verified_at' => $hr['realname_verified_at'] ?? null,
    ]);
}

ensure_hr_realname_orders_table($pdo);
$provider = $config['realname']['provider'] ?? 'mock';
$orderNo = generate_realname_order_no($pdo);
$state = bin2hex(random_bytes(16));
$expiresAt = date('Y-m-d H:i:s', time() + 7200);
$ruleId = (string)($config['realname']['rule_id'] ?? '');
$idHash = realname_id_card_hash($config, $idCard);

if ($provider === 'mock') {
    $bizToken = 'MOCK-HR-' . date('YmdHis') . '-' . random_int(1000, 9999);
    $redirectBase = rtrim($config['realname']['redirect_base'] ?? 'https://hi.hongzedigital.com', '/');
    $url = $redirectBase . '/api/auth/tencent_faceid_callback.php?order_no=' . urlencode($orderNo) . '&state=' . urlencode($state) . '&mock=1';
    $stmt = $pdo->prepare('INSERT INTO hr_realname_orders(order_no, hr_id, biz_token, redirect_url, status, id_card_hash, tencent_rule_id, state, started_at, expires_at, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,NOW(),?,NOW(),NOW())');
    $stmt->execute([$orderNo, $hr['id'], $bizToken, $url, 'pending', $idHash, $ruleId, $state, $expiresAt]);
    $stmt = $pdo->prepare('UPDATE hr_accounts SET realname_status="pending", updated_at=NOW() WHERE id=?');
    $stmt->execute([$hr['id']]);
    respond(true, '实名核身已发起', [
        'order_no' => $orderNo,
        'biz_token' => $bizToken,
        'redirect_url' => $url,
        'expires_at' => $expiresAt,
    ]);
}

if ($provider !== 'tencent') {
    respond(false, '实名认证服务暂未配置', [], 500);
}
if ($ruleId === '' || empty($config['realname']['secret_id']) || empty($config['realname']['secret_key'])) {
    respond(false, '腾讯云实名核身参数未配置完整，请检查 SecretId、SecretKey、RuleId', [], 500);
}

$old = latest_hr_realname_order($pdo, (int)$hr['id']);
if ($old && ($old['status'] ?? '') === 'pending' && !empty($old['redirect_url']) && strtotime($old['expires_at'] ?? '1970-01-01') > time()) {
    respond(true, '请继续完成微信实名认证', [
        'order_no' => $old['order_no'],
        'biz_token' => $old['biz_token'],
        'redirect_url' => $old['redirect_url'],
        'expires_at' => $old['expires_at'],
        'reused' => true,
    ]);
}

$redirectBase = rtrim($config['realname']['redirect_base'] ?? 'https://hi.hongzedigital.com', '/');
$redirectUrl = $redirectBase . '/api/auth/tencent_faceid_callback.php?order_no=' . urlencode($orderNo) . '&state=' . urlencode($state);
$extra = 'hr_id=' . (int)$hr['id'] . '&order_no=' . $orderNo;

try {
    $response = tencent_detect_auth($config['realname'], $name, $idCard, $redirectUrl, $extra);
    $bizToken = $response['BizToken'] ?? '';
    $url = $response['Url'] ?? '';
    if ($bizToken === '' || $url === '') {
        respond(false, '腾讯云实名核身链接生成失败', ['request_id' => $response['RequestId'] ?? null], 500);
    }
    $stmt = $pdo->prepare('INSERT INTO hr_realname_orders(order_no, hr_id, biz_token, redirect_url, status, id_card_hash, tencent_request_id, tencent_rule_id, state, started_at, expires_at, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,NOW(),?,NOW(),NOW())');
    $stmt->execute([$orderNo, $hr['id'], $bizToken, $url, 'pending', $idHash, $response['RequestId'] ?? '', $ruleId, $state, $expiresAt]);
    $stmt = $pdo->prepare('UPDATE hr_accounts SET realname_status="pending", updated_at=NOW() WHERE id=?');
    $stmt->execute([$hr['id']]);
    respond(true, '实名核身已发起', [
        'order_no' => $orderNo,
        'biz_token' => $bizToken,
        'redirect_url' => $url,
        'expires_at' => $expiresAt,
    ]);
} catch (Throwable $e) {
    $stmt = $pdo->prepare('INSERT INTO hr_realname_orders(order_no, hr_id, status, id_card_hash, tencent_rule_id, fail_reason, state, started_at, expires_at, created_at, updated_at) VALUES(?,?,"failed",?,?,?,?,NOW(),?,NOW(),NOW())');
    $stmt->execute([$orderNo, $hr['id'], $idHash, $ruleId, substr($e->getMessage(), 0, 500), $state, $expiresAt]);
    respond(false, '腾讯云实名核身发起失败：' . $e->getMessage(), ['order_no' => $orderNo], 500);
}
