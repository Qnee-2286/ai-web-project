<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/payment_membership.php';

$hr = require_hr($pdo);
$hrId = (int)$hr['id'];
hi_cleanup_expired_unpaid_orders($pdo);
$orderNo = trim((string)($_GET['order_no'] ?? ''));
if ($orderNo === '') {
    respond(false, 'Missing order number', [], 422);
}

$stmt = $pdo->prepare('
    SELECT o.*, p.plan_name, p.plan_key, p.valid_days, p.interview_quota,
           m.expires_at AS membership_expires_at
    FROM hr_orders o
    JOIN membership_plans p ON p.id = o.plan_id
    LEFT JOIN hr_memberships m ON m.id = o.membership_id
    WHERE o.order_no=? AND o.hr_id=?
    LIMIT 1
');
$stmt->execute([$orderNo, $hrId]);
$order = $stmt->fetch();
if (!$order) {
    respond(false, 'Order not found', [], 404);
}

$payMethodText = $order['pay_method'] === 'wechat' ? 'Wechat Pay' : ($order['pay_method'] === 'alipay' ? 'Alipay' : '-');
$statusText = $order['pay_status'] === 'paid' ? 'Paid' : ($order['pay_status'] === 'pending' ? 'Pending' : 'Closed');
$grantStatus = ($order['pay_status'] === 'paid') ? ((string)($order['grant_status'] ?? 'granted') ?: 'granted') : 'pending';
$quotaIncrease = (int)($order['quota_increase'] ?? 0);
if ($quotaIncrease <= 0 && $order['pay_status'] === 'paid') {
    $quotaIncrease = (int)$order['interview_quota'];
}

$validityText = '';
if ($order['pay_status'] === 'paid') {
    if ($order['plan_key'] === 'addon_15') {
        $validityText = 'Addon quota remains available after membership expiry';
    } elseif (!empty($order['membership_expires_at'])) {
        $validityText = 'Membership valid until ' . date('Y-m-d', strtotime((string)$order['membership_expires_at']));
    }
}

respond(true, 'ok', [
    'order_no' => $order['order_no'],
    'plan_name' => $order['plan_name'],
    'plan_key' => $order['plan_key'],
    'amount' => (float)$order['amount'],
    'paid_amount' => isset($order['paid_amount']) ? (float)$order['paid_amount'] : null,
    'pay_method' => $order['pay_method'],
    'pay_method_text' => $payMethodText,
    'pay_status' => $order['pay_status'],
    'pay_status_text' => $statusText,
    'grant_status' => $grantStatus,
    'quota_increase' => $quotaIncrease,
    'paid_at' => $order['paid_at'],
    'created_at' => $order['created_at'],
    'validity_text' => $validityText,
    'member_expires_at' => $order['membership_expires_at'] ?? null,
]);
