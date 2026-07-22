<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$input = json_input();
$login = trim((string)($input['login'] ?? ''));
if (validate_email($login)) {
    $login = normalize_email($login);
}
$credential = (string)($input['credential'] ?? '');
$mode = (string)($input['mode'] ?? 'password');

if (!validate_phone($login) && !validate_email($login)) {
    respond(false, '请输入正确的手机号或邮箱', [], 422);
}
if ($credential === '') {
    respond(false, $mode === 'code' ? '请输入验证码' : '请输入登录密码', [], 422);
}

$stmt = $pdo->prepare('SELECT * FROM hr_accounts WHERE phone=? OR email=? LIMIT 1');
$stmt->execute([$login, $login]);
$hr = $stmt->fetch();
if (!$hr) {
    audit_log($pdo, null, 'hr_login', 'not_found');
    respond(false, '账号不存在，请先注册', [], 401);
}

$ok = false;
if ($mode === 'code') {
    $channel = validate_email($login) ? 'email' : 'sms';
    $ok = verify_code($pdo, $channel, $login, 'hr_login', $credential);
} else {
    $ok = password_verify($credential, $hr['password_hash']);
}

if (!$ok) {
    audit_log($pdo, (int)$hr['id'], 'hr_login', 'failed');
    respond(false, $mode === 'code' ? '验证码错误或已过期' : '登录密码不正确', [], 401);
}

session_regenerate_id(true);
$_SESSION = ['hr_id' => (int)$hr['id']];

audit_log($pdo, (int)$hr['id'], 'hr_login', 'success');
respond(true, '登录成功', ['next' => 'dashboard.html']);
