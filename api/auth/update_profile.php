<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();
$hr = require_hr($pdo);
$input = json_input();
$name = trim($input['name'] ?? '');
$avatar = trim($input['avatar_url'] ?? '');
if ($name === '') {
    respond(false, 'Name is required', [], 422);
}
$stmt = $pdo->prepare('UPDATE hr_accounts SET name=?, avatar_url=?, updated_at=NOW() WHERE id=?');
$stmt->execute([$name, $avatar, $hr['id']]);
respond(true, 'Profile saved');
