<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$input = json_input();
$name = trim($input['name'] ?? '');
$phone = trim($input['phone'] ?? '');
$code = trim($input['sms_code'] ?? '');
$password = (string)($input['password'] ?? '');
$confirm = (string)($input['password_confirm'] ?? '');
$agreement = !empty($input['agreement']);

if ($name === '' || !validate_phone($phone) || $password === '' || strlen($password) < 6) {
    respond(false, '请填写姓名、正确手机号和至少6位密码', [], 422);
}
if ($password !== $confirm) {
    respond(false, '两次输入的密码不一致', [], 422);
}
if (!$agreement) {
    respond(false, '请先勾选注册协议和隐私政策', [], 422);
}
if (!verify_code($pdo, 'sms', $phone, 'hr_register', $code)) {
    respond(false, '短信验证码错误或已过期', [], 422);
}

$stmt = $pdo->prepare('SELECT id FROM hr_accounts WHERE phone=? LIMIT 1');
$stmt->execute([$phone]);
if ($stmt->fetch()) {
    respond(false, '该手机号已注册，请直接登录', [], 409);
}

$stmt = $pdo->prepare('INSERT INTO hr_accounts(name, phone, phone_verified_at, password_hash, realname_status, company_verification_status, created_at, updated_at) VALUES(?,?,NOW(),?,"pending","pending",NOW(),NOW())');
$stmt->execute([$name, $phone, password_hash($password, PASSWORD_DEFAULT)]);
$hrId = (int)$pdo->lastInsertId();

session_regenerate_id(true);
$_SESSION = ['hr_id' => $hrId];

audit_log($pdo, $hrId, 'hr_register', 'success');
respond(true, '注册成功，请继续绑定邮箱', ['hr_id' => $hrId, 'next' => 'bind-email.html']);
