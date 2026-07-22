<?php
/**
 * GET /api/hr/redemption_history.php
 * 获取当前 HR 的兑换记录
 */
require_once __DIR__ . '/../bootstrap.php';

$hr = require_hr($pdo);
$hrId = (int)$hr['id'];

$stmt = $pdo->prepare('
    SELECT rc.code, p.plan_name, p.plan_key, rc.used_at
    FROM redemption_codes rc
    JOIN membership_plans p ON p.id = rc.plan_id
    WHERE rc.used_by_hr_id = ?
      AND rc.status = "used"
    ORDER BY rc.used_at DESC
    LIMIT 50
');
$stmt->execute([$hrId]);
$records = $stmt->fetchAll();

respond(true, '兑换记录', [
    'records' => $records,
    'total' => count($records),
]);
