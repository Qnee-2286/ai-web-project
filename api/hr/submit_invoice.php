<?php
/**
 * POST /api/hr/submit_invoice.php
 * 提交发票申请
 * Body: { "order_no": "HZ...", "invoice_type": "company|personal", "title": "...", "tax_number": "...", "email": "...", "remark": "..." }
 *
 * 流程：存发票信息 → 邮件通知管理员 → 返回成功
 * 管理员邮箱优先读 config['app']['admin_email']，默认 hongzeshuzi@hongzedigital.cn
 */
require_once __DIR__ . '/../bootstrap.php';
require_post();

$hr = require_hr($pdo);
$hrId = (int)$hr['id'];

$input = json_input();
$orderNo = trim((string)($input['order_no'] ?? ''));
$invoiceType = trim((string)($input['invoice_type'] ?? 'company'));
$title = trim((string)($input['title'] ?? ''));
$taxNumber = trim((string)($input['tax_number'] ?? ''));
$email = normalize_email((string)($input['email'] ?? ''));
$remark = trim((string)($input['remark'] ?? ''));

// ── 字段校验 ──
if ($orderNo === '') {
    respond(false, '缺少订单号', [], 422);
}
if (!in_array($invoiceType, ['company', 'personal'], true)) {
    respond(false, '发票类型无效', [], 422);
}
if ($title === '') {
    respond(false, '请填写发票抬头', [], 422);
}
if (mb_strlen($title) > 200) {
    respond(false, '发票抬头过长', [], 422);
}
if ($invoiceType === 'company' && $taxNumber === '') {
    respond(false, '企业发票请填写税号', [], 422);
}
if (!validate_email($email)) {
    respond(false, '请填写正确的接收邮箱', [], 422);
}

// ── 查订单（确认属于该 HR 且已支付）──
$stmt = $pdo->prepare('
    SELECT o.*, p.plan_name, p.plan_key
    FROM hr_orders o
    JOIN membership_plans p ON p.id = o.plan_id
    WHERE o.order_no=? AND o.hr_id=?
    LIMIT 1
');
$stmt->execute([$orderNo, $hrId]);
$order = $stmt->fetch();

if (!$order) {
    respond(false, '订单不存在', [], 404);
}
if ($order['pay_status'] !== 'paid') {
    respond(false, '订单未支付，无法开具发票', [], 400);
}

// ── 检查是否已有发票申请 ──
$existStmt = $pdo->prepare('SELECT id FROM hr_invoice_requests WHERE order_id=? LIMIT 1');
$existStmt->execute([$order['id']]);
if ($existStmt->fetch()) {
    respond(false, '该订单已提交过发票申请，请勿重复提交', [], 409);
}

$now = date('Y-m-d H:i:s');

// ── 存发票申请 ──
$insStmt = $pdo->prepare('
    INSERT INTO hr_invoice_requests (order_id, hr_id, invoice_type, title, tax_number, email, remark, status, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, "pending", ?, ?)
');
$insStmt->execute([$order['id'], $hrId, $invoiceType, $title, $taxNumber, $email, $remark, $now, $now]);

// ── 邮件通知管理员 ──
$adminEmail = $config['app']['admin_email'] ?? 'hongzeshuzi@hongzedigital.cn';
$typeText = $invoiceType === 'company' ? '企业' : '个人';
$payMethodText = $order['pay_method'] === 'wechat' ? '微信支付' : '支付宝';

$subject = '【AI面试系统-发票申请】' . $title . ' · ' . $order['plan_name'];

$htmlBody = '<div style="font-family:sans-serif;max-width:600px;margin:0 auto;color:#333">'
    . '<h2 style="color:#0070ff;border-bottom:2px solid #00d68f;padding-bottom:10px">新的发票申请</h2>'
    . '<table style="width:100%;border-collapse:collapse;font-size:14px">'
    . '<tr><td style="padding:8px 0;color:#666;width:120px">订单编号</td><td style="padding:8px 0;font-weight:600">' . htmlspecialchars($orderNo) . '</td></tr>'
    . '<tr><td style="padding:8px 0;color:#666">套餐</td><td style="padding:8px 0">' . htmlspecialchars($order['plan_name']) . '</td></tr>'
    . '<tr><td style="padding:8px 0;color:#666">实付金额</td><td style="padding:8px 0;font-weight:600;color:#0070ff">¥' . number_format((float)$order['amount'], 2) . '</td></tr>'
    . '<tr><td style="padding:8px 0;color:#666">支付方式</td><td style="padding:8px 0">' . $payMethodText . '</td></tr>'
    . '<tr><td style="padding:8px 0;color:#666">抬头类型</td><td style="padding:8px 0">' . $typeText . '</td></tr>'
    . '<tr><td style="padding:8px 0;color:#666">发票抬头</td><td style="padding:8px 0;font-weight:600">' . htmlspecialchars($title) . '</td></tr>'
    . ($taxNumber ? '<tr><td style="padding:8px 0;color:#666">税号</td><td style="padding:8px 0">' . htmlspecialchars($taxNumber) . '</td></tr>' : '')
    . '<tr><td style="padding:8px 0;color:#666">接收邮箱</td><td style="padding:8px 0">' . htmlspecialchars($email) . '</td></tr>'
    . ($remark ? '<tr><td style="padding:8px 0;color:#666">备注</td><td style="padding:8px 0">' . htmlspecialchars($remark) . '</td></tr>' : '')
    . '</table>'
    . '<p style="margin-top:20px;padding:12px;background:#f0fdf4;border-radius:8px;font-size:13px;color:#047857">请登录税控系统开具电子普通发票，发送至用户邮箱 ' . htmlspecialchars($email) . '</p>'
    . '<p style="margin-top:16px;font-size:12px;color:#999">此邮件由系统自动发送，请勿回复</p>'
    . '</div>';

$textBody = "新的发票申请\n\n"
    . "订单编号：{$orderNo}\n"
    . "套餐：{$order['plan_name']}\n"
    . "实付金额：¥" . number_format((float)$order['amount'], 2) . "\n"
    . "支付方式：{$payMethodText}\n"
    . "抬头类型：{$typeText}\n"
    . "发票抬头：{$title}\n"
    . ($taxNumber ? "税号：{$taxNumber}\n" : '')
    . "接收邮箱：{$email}\n"
    . ($remark ? "备注：{$remark}\n" : '')
    . "\n请登录税控系统开具电子普通发票，发送至用户邮箱 {$email}";

// 仅在非 dev_mode 且邮件渠道已配置时真实发送
$shouldSend = empty($config['app']['dev_mode']) && channel_provider($config, 'email') !== 'mock';
if ($shouldSend) {
    try {
        send_notification_email($config['email'], $adminEmail, $subject, $htmlBody, $textBody);
    } catch (Throwable $e) {
        error_log('Invoice notification email failed: ' . $e->getMessage());
    }
} else {
    error_log('[dev/mock] Invoice notification skipped for order ' . $orderNo . ' → ' . $adminEmail);
}

respond(true, '发票申请已提交', [
    'order_no' => $orderNo,
    'invoice_type' => $typeText,
    'title' => $title,
    'email' => $email,
    'admin_notified' => $shouldSend,
]);
