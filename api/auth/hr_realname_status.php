<?php
require_once __DIR__ . '/../bootstrap.php';

$hr = require_hr($pdo);
$order = latest_hr_realname_order($pdo, (int)$hr['id']);

if (($hr['realname_status'] ?? '') === 'verified') {
    respond(true, 'HR实名状态', [
        'realname_status' => 'verified',
        'verified_at' => $hr['realname_verified_at'] ?? null,
        'order_no' => $order['order_no'] ?? null,
    ]);
}

if (!$order) {
    respond(true, 'HR实名状态', [
        'realname_status' => $hr['realname_status'] ?? 'pending',
        'verified_at' => null,
        'order_no' => null,
    ]);
}

$provider = $config['realname']['provider'] ?? 'mock';
if ($provider === 'mock' && ($order['status'] ?? '') === 'pending') {
    $stmt = $pdo->prepare('UPDATE hr_realname_orders SET status="verified", verified_at=NOW(), updated_at=NOW() WHERE id=?');
    $stmt->execute([$order['id']]);
    try {
        $stmt = $pdo->prepare('UPDATE hr_accounts SET realname_status="verified", realname_verified_at=NOW(), realname_order_no=?, updated_at=NOW() WHERE id=?');
        $stmt->execute([$order['order_no'], $hr['id']]);
    } catch (Throwable $e) {
        $stmt = $pdo->prepare('UPDATE hr_accounts SET realname_status="verified", realname_verified_at=NOW(), updated_at=NOW() WHERE id=?');
        $stmt->execute([$hr['id']]);
    }
    respond(true, 'HR实名状态', [
        'realname_status' => 'verified',
        'verified_at' => date('Y-m-d H:i:s'),
        'order_no' => $order['order_no'],
    ]);
}

if ($provider === 'tencent' && ($order['status'] ?? '') === 'pending') {
    $order = update_hr_realname_from_tencent($pdo, $config, $order);
}

$status = $order['status'] ?? 'pending';
$statusMap = [
    'verified' => 'verified',
    'failed' => 'failed',
    'expired' => 'expired',
    'pending' => 'pending',
];

respond(true, 'HR实名状态', [
    'realname_status' => $statusMap[$status] ?? 'pending',
    'verified_at' => $order['verified_at'] ?? null,
    'order_no' => $order['order_no'] ?? null,
    'expires_at' => $order['expires_at'] ?? null,
    'fail_reason' => $order['fail_reason'] ?? null,
]);
