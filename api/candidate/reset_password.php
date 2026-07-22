<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$accountId = (int)($_SESSION['candidate_account_id'] ?? 0);
if ($accountId <= 0) {
    respond(false, '请先登录', [], 401);
}

$input = json_input();
$code = trim((string)($input['sms_code'] ?? ''));
$password = (string)($input['new_password'] ?? '');
$confirm = (string)($input['password_confirm'] ?? '');

if ($code === '') {
    respond(false, '请输入短信验证码', [], 422);
}
if (strlen($password) < 8) {
    respond(false, '新密码至少8位', [], 422);
}
if ($password !== $confirm) {
    respond(false, '两次输入的密码不一致', [], 422);
}
if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
    respond(false, '新密码需包含字母和数字', [], 422);
}
if (!table_exists($pdo, 'candidate_accounts')) {
    respond(false, '候选人账户表未初始化，请先执行升级SQL', [], 500);
}
if (!column_exists($pdo, 'candidate_accounts', 'password_hash')) {
    respond(false, '候选人密码字段未初始化，请先执行升级SQL', [], 500);
}

$stmt = $pdo->prepare('SELECT id, phone FROM candidate_accounts WHERE id=? LIMIT 1');
$stmt->execute([$accountId]);
$account = $stmt->fetch();
if (!$account || !validate_phone((string)$account['phone'])) {
    respond(false, '候选人账号不存在，请重新登录', [], 401);
}

if (!verify_code($pdo, 'sms', $account['phone'], 'candidate_password', $code)) {
    respond(false, '短信验证码错误或已过期', [], 422);
}

$stmt = $pdo->prepare('UPDATE candidate_accounts SET password_hash=?, updated_at=NOW() WHERE id=?');
$stmt->execute([password_hash($password, PASSWORD_DEFAULT), $accountId]);

respond(true, '密码修改成功');
