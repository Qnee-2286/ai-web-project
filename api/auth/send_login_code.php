<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$input = json_input();
$login = trim($input['login'] ?? '');
if (validate_email($login)) {
    $login = normalize_email($login);
}

if (validate_phone($login)) {
    $result = create_verification_code($pdo, $config, 'sms', $login, 'hr_login');
    try {
        dispatch_verification_code($config, 'sms', $login, $result['code']);
    } catch (Throwable $e) {
        respond(false, '短信验证码发送失败：' . $e->getMessage(), [], 500);
    }
    $channel = 'sms';
} elseif (validate_email($login)) {
    $result = create_verification_code($pdo, $config, 'email', $login, 'hr_login');
    try {
        dispatch_verification_code($config, 'email', $login, $result['code']);
    } catch (Throwable $e) {
        respond(false, '邮箱验证码发送失败：' . $e->getMessage(), [], 500);
    }
    $channel = 'email';
} else {
    respond(false, '请输入正确的手机号或邮箱', [], 422);
}

$data = ['expires_in' => $result['expires_in']];
if (!empty($config['app']['dev_mode']) || (($config[$channel]['provider'] ?? 'mock') === 'mock')) {
    $data['dev_code'] = $result['code'];
}
respond(true, '登录验证码已发送', $data);
