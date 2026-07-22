<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/alipay.php';
require_once __DIR__ . '/../lib/payment_membership.php';

$params = $_POST;
try {
    $pay = alipay_config($config);
    if (!alipay_verify($params, $pay['alipay_public_key'])) {
        echo 'fail';
        exit;
    }
    if (($params['app_id'] ?? '') !== $pay['app_id']) {
        echo 'fail';
        exit;
    }

    $tradeStatus = (string)($params['trade_status'] ?? '');
    if (!in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
        echo 'success';
        exit;
    }

    mark_order_paid(
        $pdo,
        (string)$params['out_trade_no'],
        'alipay',
        (float)$params['total_amount'],
        (string)($params['trade_no'] ?? ''),
        json_encode($params, JSON_UNESCAPED_UNICODE),
        date('Y-m-d H:i:s', strtotime((string)($params['gmt_payment'] ?? 'now')))
    );
    echo 'success';
} catch (Throwable $e) {
    error_log('alipay_notify_error: ' . $e->getMessage());
    echo 'fail';
}
