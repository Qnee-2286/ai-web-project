<?php
/**
 * POST /api/hr/generate_codes.php
 * 管理员批量生成兑换码
 * Body: { "plan_key": "monthly", "count": 5 }
 * 要求: 当前登录 HR 为平台管理员（hr_accounts.role = 'admin'）
 * 注意: hr_accounts 表需要增加 role 字段，见下方说明
 */
require_once __DIR__ . '/../bootstrap.php';
require_post();

$hr = require_hr($pdo);
$hrId = (int)$hr['id'];

// 检查管理员权限（如果 hr_accounts 没有 role 字段，暂时用 id=1 作为超级管理员判断）
$isAdmin = false;
if (column_exists($pdo, 'hr_accounts', 'role')) {
    $roleStmt = $pdo->prepare('SELECT role FROM hr_accounts WHERE id = ?');
    $roleStmt->execute([$hrId]);
    $isAdmin = ($roleStmt->fetchColumn() === 'admin');
} else {
    // 没有 role 字段时，id=1 默认为管理员
    $isAdmin = ($hrId === 1);
}

if (!$isAdmin) {
    respond(false, '无权限，仅管理员可生成兑换码', [], 403);
}

$input = json_input();
$planKey = trim((string)($input['plan_key'] ?? 'monthly'));
$count = (int)($input['count'] ?? 5);

if ($count < 1) $count = 1;
if ($count > 100) $count = 100;

// 查套餐
$planStmt = $pdo->prepare('SELECT id, plan_name FROM membership_plans WHERE plan_key = ? AND is_active = 1');
$planStmt->execute([$planKey]);
$plan = $planStmt->fetch();

if (!$plan) {
    respond(false, '套餐不存在或已下架', [], 404);
}

// 生成唯一码（10-18位随机长度，大小写+数字混合）
$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
$codes = [];
$now = date('Y-m-d H:i:s');

$stmt = $pdo->prepare('
    INSERT INTO redemption_codes (code, plan_id, status, generated_by, created_at)
    VALUES (?, ?, "unused", ?, ?)
');

for ($i = 0; $i < $count; $i++) {
    $attempts = 0;
    do {
        $len = random_int(10, 18);
        $code = '';
        for ($j = 0; $j < $len; $j++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        // 检查是否重复
        $dup = $pdo->prepare('SELECT COUNT(*) FROM redemption_codes WHERE code = ?');
        $dup->execute([$code]);
        $attempts++;
    } while ((int)$dup->fetchColumn() > 0 && $attempts < 10);

    $stmt->execute([$code, $plan['id'], $hrId, $now]);
    $codes[] = $code;
}

respond(true, '已生成 ' . count($codes) . ' 个兑换码', [
    'plan_name' => $plan['plan_name'],
    'plan_key' => $planKey,
    'count' => count($codes),
    'codes' => $codes,
]);
