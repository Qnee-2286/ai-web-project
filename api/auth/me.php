<?php
require_once __DIR__ . '/../bootstrap.php';
$hr = require_hr($pdo);
respond(true, 'ok', [
    'id' => (int)$hr['id'],
    'name' => $hr['name'],
    'phone' => mask_phone($hr['phone']),
    'phone_verified' => !empty($hr['phone_verified_at']),
    'email' => $hr['email'],
    'email_verified' => !empty($hr['email_verified_at']),
    'realname_status' => $hr['realname_status'],
    'company_verification_status' => $hr['company_verification_status'],
    'avatar_url' => $hr['avatar_url'] ?? '',
]);
