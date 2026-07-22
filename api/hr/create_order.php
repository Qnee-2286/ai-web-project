<?php
/**
 * POST /api/hr/create_order.php
 * Body: {"plan_key":"monthly|quarterly|yearly|addon_15","pay_method":"wechat|alipay"}
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/alipay.php';
require_once __DIR__ . '/../lib/wechatpay.php';
require_once __DIR__ . '/../lib/payment_membership.php';
require_post();

$hr = require_hr($pdo);
$hrId = (int)$hr['id'];
hi_cleanup_expired_unpaid_orders($pdo);
$input = json_input();
$planKey = trim((string)($input['plan_key'] ?? ''));
$payMethod = trim((string)($input['pay_method'] ?? ''));
$allowedPlans = ['monthly', 'quarterly', 'yearly', 'addon_15'];

if (!in_array($planKey, $allowedPlans, true)) {
    respond(false, 'Invalid plan', [], 422);
}
if (!in_array($payMethod, ['wechat', 'alipay'], true)) {
    respond(false, 'Invalid payment method', [], 422);
}

$stmt = $pdo->prepare('SELECT * FROM membership_plans WHERE plan_key=? AND is_active=1 LIMIT 1');
$stmt->execute([$planKey]);
$plan = $stmt->fetch();
if (!$plan) {
    respond(false, 'Plan not available', [], 404);
}

if ($planKey === 'addon_15' && !hi_active_membership($pdo, $hrId)) {
    respond(false, 'Addon package requires an active membership', [], 400);
}

$amount = (float)$plan['price'];
$now = date('Y-m-d H:i:s');
$orderNo = 'HZ' . date('YmdHis') . sprintf('%03d', random_int(0, 999));

$pdo->beginTransaction();
try {
    $insOrder = $pdo->prepare('
        INSERT INTO hr_orders (order_no, hr_id, plan_id, amount, pay_method, pay_status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, "pending", ?, ?)
    ');
    $insOrder->execute([$orderNo, $hrId, (int)$plan['id'], $amount, $payMethod, $now, $now]);
    $orderId = (int)$pdo->lastInsertId();
    $pdo->commit();

    $order = [
        'id' => $orderId,
        'order_no' => $orderNo,
        'hr_id' => $hrId,
        'plan_id' => (int)$plan['id'],
        'amount' => $amount,
        'pay_method' => $payMethod,
        'pay_status' => 'pending',
    ];

    if ($payMethod === 'alipay') {
        $payForm = alipay_page_pay_form($config, $order, $plan);
        respond(true, 'Order created, please complete Alipay payment', [
            'order_no' => $orderNo,
            'plan_name' => $plan['plan_name'],
            'plan_key' => $planKey,
            'amount' => $amount,
            'pay_method' => $payMethod,
            'pay_status' => 'pending',
            'payment_action' => 'form',
            'payment_html' => $payForm,
        ]);
    }

    $codeUrl = wechatpay_create_native($config, $order, $plan);
    respond(true, 'Order created, please scan with WeChat Pay', [
        'order_no' => $orderNo,
        'plan_name' => $plan['plan_name'],
        'plan_key' => $planKey,
        'amount' => $amount,
        'pay_method' => $payMethod,
        'pay_status' => 'pending',
        'payment_action' => 'wechat_native',
        'code_url' => $codeUrl,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}
