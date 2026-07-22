<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$hr = require_hr($pdo);
$input = json_input();
$password = (string)($input['password'] ?? '');
$confirm = (string)($input['password_confirm'] ?? '');

if (strlen($password) < 6) {
    respond(false, '密码至少 6 位', [], 422);
}
if ($password !== $confirm) {
    respond(false, '两次输入的密码不一致', [], 422);
}

$stmt = $pdo->prepare('UPDATE hr_accounts SET password_hash=?, updated_at=NOW() WHERE id=?');
$stmt->execute([password_hash($password, PASSWORD_DEFAULT), $hr['id']]);

audit_log($pdo, (int)$hr['id'], 'hr_set_password', 'success');
respond(true, '密码设置成功，请继续绑定邮箱', ['next' => 'bind-email.html']);
