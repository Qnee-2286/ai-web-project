<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$input = json_input();
$phone = trim((string)($input['phone'] ?? ''));
$password = (string)($input['password'] ?? '');

if (!validate_phone($phone)) {
    respond(false, '请填写正确的手机号', [], 422);
}
if ($password === '') {
    respond(false, '请输入登录密码', [], 422);
}
if (!table_exists($pdo, 'candidate_accounts')) {
    respond(false, '候选人账户表未初始化，请先执行升级SQL', [], 500);
}
if (!column_exists($pdo, 'candidate_accounts', 'password_hash')) {
    respond(false, '候选人密码字段未初始化，请先执行升级SQL', [], 500);
}

$stmt = $pdo->prepare('SELECT * FROM candidate_accounts WHERE phone=? LIMIT 1');
$stmt->execute([$phone]);
$account = $stmt->fetch();
if (!$account || empty($account['password_hash'])) {
    respond(false, '该手机号尚未设置密码，请使用验证码登录', [], 401);
}
if (!password_verify($password, $account['password_hash'])) {
    respond(false, '登录密码不正确', [], 401);
}

session_regenerate_id(true);
$_SESSION['candidate_account_id'] = (int)$account['id'];

try {
    if (table_exists($pdo, 'candidates') && column_exists($pdo, 'candidates', 'candidate_account_id')) {
        $stmt = $pdo->prepare('UPDATE candidates SET candidate_account_id=?, updated_at=NOW() WHERE phone=? AND (candidate_account_id IS NULL OR candidate_account_id=0)');
        $stmt->execute([(int)$account['id'], $phone]);
    }
} catch (Throwable $e) {
    /* 补绑失败不阻断登录 */
}

respond(true, '登录成功', [
    'account_id' => (int)$account['id'],
    'phone' => $account['phone'],
    'next' => 'profile.html',
]);
