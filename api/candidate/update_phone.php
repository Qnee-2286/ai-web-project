<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$accountId = (int)($_SESSION['candidate_account_id'] ?? 0);
if ($accountId <= 0) {
    respond(false, '请先登录', [], 401);
}

$input = json_input();
$phone = trim((string)($input['phone'] ?? ''));
$code = trim((string)($input['sms_code'] ?? ''));

if (!validate_phone($phone)) {
    respond(false, '请填写正确的手机号', [], 422);
}
if ($code === '') {
    respond(false, '请输入短信验证码', [], 422);
}
if (!table_exists($pdo, 'candidate_accounts')) {
    respond(false, '候选人账户表未初始化，请先执行升级SQL', [], 500);
}

$stmt = $pdo->prepare('SELECT id, phone FROM candidate_accounts WHERE id=? LIMIT 1');
$stmt->execute([$accountId]);
$account = $stmt->fetch();
if (!$account) {
    respond(false, '候选人账号不存在，请重新登录', [], 401);
}

if (!verify_code($pdo, 'sms', $phone, 'candidate_phone', $code)) {
    respond(false, '短信验证码错误或已过期', [], 422);
}

$stmt = $pdo->prepare('SELECT id FROM candidate_accounts WHERE phone=? AND id<>? LIMIT 1');
$stmt->execute([$phone, $accountId]);
if ($stmt->fetch()) {
    respond(false, '该手机号已绑定其他候选人账号', [], 409);
}

$stmt = $pdo->prepare('UPDATE candidate_accounts SET phone=?, phone_verified_at=NOW(), updated_at=NOW() WHERE id=?');
$stmt->execute([$phone, $accountId]);

respond(true, '手机号修改成功', [
    'account_id' => $accountId,
    'phone' => $phone,
]);
