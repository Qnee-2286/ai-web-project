<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();
$hr = require_hr($pdo);
$input = json_input();
$old = (string)($input['old_password'] ?? '');
$new = (string)($input['new_password'] ?? '');
$confirm = (string)($input['confirm_password'] ?? '');
if (!password_verify($old, $hr['password_hash'])) {
    respond(false, 'Old password is incorrect', [], 422);
}
if (strlen($new) < 6 || $new !== $confirm) {
    respond(false, 'New password must be at least 6 characters and match confirmation', [], 422);
}
$stmt = $pdo->prepare('UPDATE hr_accounts SET password_hash=?, updated_at=NOW() WHERE id=?');
$stmt->execute([password_hash($new, PASSWORD_DEFAULT), $hr['id']]);
respond(true, 'Password reset');
