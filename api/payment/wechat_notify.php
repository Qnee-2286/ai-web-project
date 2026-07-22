<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/wechatpay.php';
require_once __DIR__ . '/../lib/payment_membership.php';

$raw = file_get_contents('php://input') ?: '';
try {
    if (!wechatpay_verify_notify_signature($config, $raw)) {
        throw new RuntimeException('Wechat Pay notify signature verification failed');
    }
    $payload = json_decode($raw, true);
    if (!is_array($payload) || empty($payload['resource'])) {
        throw new RuntimeException('Wechat Pay notify payload is empty');
    }
    $data = wechatpay_decrypt_notify($config, $payload['resource']);
    if (($data['trade_state'] ?? '') !== 'SUCCESS') {
        echo json_encode(['code' => 'SUCCESS', 'message' => 'OK'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    mark_order_paid(
        $pdo,
        (string)$data['out_trade_no'],
        'wechat',
        ((float)($data['amount']['payer_total'] ?? 0)) / 100,
        (string)($data['transaction_id'] ?? ''),
        $raw,
        date('Y-m-d H:i:s', strtotime((string)($data['success_time'] ?? 'now')))
    );
    echo json_encode(['code' => 'SUCCESS', 'message' => 'OK'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('wechat_notify_error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['code' => 'FAIL', 'message' => 'Notify processing failed'], JSON_UNESCAPED_UNICODE);
}
