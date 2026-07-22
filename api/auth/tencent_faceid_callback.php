<?php
require_once __DIR__ . '/../bootstrap.php';

$orderNo = trim($_GET['order_no'] ?? '');
$state = trim($_GET['state'] ?? '');
$mock = !empty($_GET['mock']);
$redirectBase = rtrim($config['realname']['redirect_base'] ?? 'https://hi.hongzedigital.com', '/');
$realnameUrl = $redirectBase . '/hr/realname.html';
$dashboardUrl = $redirectBase . '/hr/dashboard.html';

ensure_hr_realname_orders_table($pdo);

if ($orderNo === '' || $state === '') {
    header('Location: ' . $realnameUrl . '?realname=invalid');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM hr_realname_orders WHERE order_no=? AND state=? LIMIT 1');
$stmt->execute([$orderNo, $state]);
$order = $stmt->fetch();
if (!$order) {
    header('Location: ' . $realnameUrl . '?realname=not_found');
    exit;
}

if ($mock || (($config['realname']['provider'] ?? 'mock') === 'mock')) {
    $stmt = $pdo->prepare('UPDATE hr_realname_orders SET status="verified", verified_at=NOW(), updated_at=NOW() WHERE id=?');
    $stmt->execute([$order['id']]);
    $stmt = $pdo->prepare('UPDATE hr_accounts SET realname_status="verified", realname_verified_at=NOW(), realname_order_no=?, updated_at=NOW() WHERE id=?');
    try {
        $stmt->execute([$orderNo, $order['hr_id']]);
    } catch (Throwable $e) {
        $stmt = $pdo->prepare('UPDATE hr_accounts SET realname_status="verified", realname_verified_at=NOW(), updated_at=NOW() WHERE id=?');
        $stmt->execute([$order['hr_id']]);
    }
    header('Location: ' . $dashboardUrl . '?realname=success');
    exit;
}

try {
    $order = update_hr_realname_from_tencent($pdo, $config, $order);
    if (($order['status'] ?? '') === 'verified') {
        try {
            $stmt = $pdo->prepare('UPDATE hr_accounts SET realname_order_no=? WHERE id=?');
            $stmt->execute([$orderNo, $order['hr_id']]);
        } catch (Throwable $e) {
            // Older databases may not have realname_order_no yet.
        }
        header('Location: ' . $dashboardUrl . '?realname=success');
        exit;
    }
    header('Location: ' . $realnameUrl . '?realname=' . urlencode($order['status'] ?? 'pending') . '&order_no=' . urlencode($orderNo));
    exit;
} catch (Throwable $e) {
    $stmt = $pdo->prepare('UPDATE hr_realname_orders SET fail_reason=?, updated_at=NOW() WHERE id=?');
    $stmt->execute([substr($e->getMessage(), 0, 500), $order['id']]);
    header('Location: ' . $realnameUrl . '?realname=query_failed&order_no=' . urlencode($orderNo));
    exit;
}
