<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/payment_membership.php';

$hr = require_hr($pdo);
$hrId = (int)$hr['id'];
hi_cleanup_expired_unpaid_orders($pdo);

$stmt = $pdo->prepare('
    SELECT o.order_no, o.amount, o.paid_amount, o.pay_method, o.pay_status, o.paid_at, o.created_at,
           o.quota_increase, p.plan_name, p.plan_key, p.interview_quota,
           m.expires_at AS membership_expires_at
    FROM hr_orders o
    JOIN membership_plans p ON p.id = o.plan_id
    LEFT JOIN hr_memberships m ON m.id = o.membership_id
    WHERE o.hr_id=? AND o.pay_status="paid"
    ORDER BY COALESCE(o.paid_at, o.created_at) DESC
    LIMIT 50
');
$stmt->execute([$hrId]);
$rows = [];
foreach ($stmt->fetchAll() as $row) {
    $payMethodText = $row['pay_method'] === 'wechat' ? 'Wechat Pay' : ($row['pay_method'] === 'alipay' ? 'Alipay' : '-');
    $statusText = $row['pay_status'] === 'paid' ? 'Paid' : ($row['pay_status'] === 'pending' ? 'Pending' : 'Closed');
    $quotaIncrease = (int)($row['quota_increase'] ?? 0);
    if ($quotaIncrease <= 0 && $row['pay_status'] === 'paid') {
        $quotaIncrease = (int)$row['interview_quota'];
    }
    $validityText = '';
    if ($row['pay_status'] === 'paid') {
        if ($row['plan_key'] === 'addon_15') {
            $validityText = 'Long-term';
        } elseif (!empty($row['membership_expires_at'])) {
            $validityText = 'Until ' . date('Y-m-d', strtotime((string)$row['membership_expires_at']));
        }
    }
    $rows[] = [
        'order_no' => $row['order_no'],
        'created_at' => $row['paid_at'] ?: $row['created_at'],
        'plan_name' => $row['plan_name'],
        'plan_key' => $row['plan_key'],
        'amount' => (float)$row['amount'],
        'paid_amount' => isset($row['paid_amount']) ? (float)$row['paid_amount'] : null,
        'pay_method_text' => $payMethodText,
        'pay_status' => $row['pay_status'],
        'pay_status_text' => $statusText,
        'quota_increase' => $quotaIncrease,
        'validity_text' => $validityText,
    ];
}

respond(true, 'ok', ['records' => $rows]);
