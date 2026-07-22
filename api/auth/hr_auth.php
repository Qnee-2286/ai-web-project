<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$input = json_input();
$phone = trim((string)($input['phone'] ?? ''));
$code = trim((string)($input['sms_code'] ?? ''));

if (!validate_phone($phone)) {
    respond(false, '请填写正确的手机号', [], 422);
}
if ($code === '') {
    respond(false, '请输入短信验证码', [], 422);
}
if (!verify_code($pdo, 'sms', $phone, 'hr_entry', $code)) {
    respond(false, '短信验证码错误或已过期', [], 422);
}

// Check if HR already exists
$stmt = $pdo->prepare('SELECT * FROM hr_accounts WHERE phone=? LIMIT 1');
$stmt->execute([$phone]);
$hr = $stmt->fetch();

if ($hr) {
    // Existing user — login
    if (column_exists($pdo, 'hr_accounts', 'phone_verified_at')) {
        $pdo->prepare('UPDATE hr_accounts SET phone_verified_at=NOW(), updated_at=NOW() WHERE id=?')->execute([$hr['id']]);
    }

    session_regenerate_id(true);
    $_SESSION = ['hr_id' => (int)$hr['id']];

    audit_log($pdo, (int)$hr['id'], 'hr_entry_login', 'success');

    // Determine next step based on completion
    $emailDone = !empty($hr['email']) && !empty($hr['email_verified_at'] ?? null);
    $realDone = ($hr['realname_status'] ?? '') === 'verified';
    $passwordSet = !empty($hr['password_hash']);

    if ($emailDone && $realDone) {
        respond(true, '登录成功', ['is_new' => false, 'next' => 'dashboard.html', 'hr_id' => (int)$hr['id']]);
    } elseif (!$emailDone) {
        respond(true, '登录成功', ['is_new' => false, 'next' => 'bind-email.html', 'hr_id' => (int)$hr['id']]);
    } else {
        respond(true, '登录成功', ['is_new' => false, 'next' => 'realname.html', 'hr_id' => (int)$hr['id']]);
    }
} else {
    // New user — auto register (no name or password yet)
    $stmt = $pdo->prepare('INSERT INTO hr_accounts(phone, phone_verified_at, realname_status, company_verification_status, created_at, updated_at) VALUES(?, NOW(), "pending", "pending", NOW(), NOW())');
    $stmt->execute([$phone]);
    $hrId = (int)$pdo->lastInsertId();

    session_regenerate_id(true);
    $_SESSION = ['hr_id' => $hrId];

    audit_log($pdo, $hrId, 'hr_entry_register', 'success');
    respond(true, '注册成功，请设置密码', ['is_new' => true, 'next' => 'set-password.html', 'hr_id' => $hrId]);
}
