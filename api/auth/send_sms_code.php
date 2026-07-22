<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();
$input = json_input();
$phone = trim($input['phone'] ?? '');
$purpose = trim($input['purpose'] ?? '');
$allowed = ['hr_register', 'hr_login', 'hr_entry', 'candidate_auth', 'candidate_password', 'candidate_phone'];
if (!validate_phone($phone) || !in_array($purpose, $allowed, true)) {
    respond(false, 'Invalid phone or purpose', [], 422);
}
$result = create_verification_code($pdo, $config, 'sms', $phone, $purpose);
try {
    dispatch_verification_code($config, 'sms', $phone, $result['code']);
} catch (Throwable $e) {
    respond(false, 'SMS send failed: ' . $e->getMessage(), [], 500);
}
$data = ['expires_in' => $result['expires_in']];
if (!empty($config['app']['dev_mode']) || (($config['sms']['provider'] ?? 'mock') === 'mock')) {
    $data['dev_code'] = $result['code'];
}
respond(true, 'Code sent', $data);
