<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

require_hr($pdo);
$input = json_input();
$email = normalize_email($input['email'] ?? '');

if (!validate_email($email)) {
    respond(false, '请输入正确的邮箱地址', [], 422);
}

$result = create_verification_code($pdo, $config, 'email', $email, 'bind_email');
try {
    dispatch_verification_code($config, 'email', $email, $result['code']);
} catch (Throwable $e) {
    respond(false, '邮箱验证码发送失败：' . $e->getMessage(), [], 500);
}

$data = ['expires_in' => $result['expires_in']];
if (!empty($config['app']['dev_mode']) || (($config['email']['provider'] ?? 'mock') === 'mock')) {
    $data['dev_code'] = $result['code'];
}
respond(true, '邮箱验证码已发送', $data);
