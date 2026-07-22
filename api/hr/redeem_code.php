<?php
/**
 * POST /api/hr/redeem_code.php
 * HR 兑换会员码
 * Body: { "code": "HI-A3K9X2M7" }
 */
require_once __DIR__ . '/../bootstrap.php';
require_post();

$hr = require_hr($pdo);
$hrId = (int)$hr['id'];

$input = json_input();
$code = strtoupper(trim((string)($input['code'] ?? '')));

if ($code === '' || strlen($code) < 8) {
    respond(false, '请输入有效的兑换码', [], 422);
}

// 查找兑换码
$stmt = $pdo->prepare('
    SELECT rc.*, p.plan_key, p.plan_name, p.interview_quota, p.job_quota, p.report_days, p.valid_days
    FROM redemption_codes rc
    JOIN membership_plans p ON p.id = rc.plan_id
    WHERE rc.code = ?
    LIMIT 1
');
$stmt->execute([$code]);
$entry = $stmt->fetch();

if (!$entry) {
    respond(false, '兑换码不存在，请检查后重试', [], 404);
}

if ($entry['status'] !== 'unused') {
    respond(false, '该兑换码已' . ($entry['status'] === 'used' ? '被使用' : '失效') . '，无法兑换', [], 400);
}

if ($entry['expires_at'] && strtotime($entry['expires_at']) < time()) {
    $pdo->prepare('UPDATE redemption_codes SET status="expired" WHERE id=?')->execute([$entry['id']]);
    respond(false, '该兑换码已过期', [], 400);
}

$now = date('Y-m-d H:i:s');
$planKey = $entry['plan_key'];

$pdo->beginTransaction();
try {
    if ($planKey === 'monthly') {
        // 月度会员：检查是否已有有效会员
        $existStmt = $pdo->prepare('
            SELECT id, expires_at FROM hr_memberships
            WHERE hr_id = ? AND status = "active" AND expires_at > NOW()
            AND plan_id IN (SELECT id FROM membership_plans WHERE plan_key = "monthly")
            LIMIT 1
        ');
        $existStmt->execute([$hrId]);
        $existing = $existStmt->fetch();

        if ($existing) {
            $pdo->rollBack();
            $rd = max(1, ceil((strtotime($existing['expires_at']) - time()) / 86400));
            respond(false, '当前已有月度会员（剩余 ' . $rd . ' 天），请先使用完再兑换', [], 409);
        }

        // 创建会员记录
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $entry['valid_days'] . ' days'));
        $insStmt = $pdo->prepare('
            INSERT INTO hr_memberships (hr_id, plan_id, redemption_code_id, activated_at, expires_at, total_interviews, used_interviews, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, "active", ?, ?)
        ');
        $insStmt->execute([$hrId, $entry['plan_id'], $entry['id'], $now, $expiresAt, $entry['interview_quota'], $now, $now]);

    } elseif ($planKey === 'addon') {
        // 加量包：需要先有有效月度会员
        $existStmt = $pdo->prepare('
            SELECT id, total_interviews FROM hr_memberships
            WHERE hr_id = ? AND status = "active" AND expires_at > NOW()
            AND plan_id IN (SELECT id FROM membership_plans WHERE plan_key = "monthly")
            LIMIT 1
        ');
        $existStmt->execute([$hrId]);
        $existing = $existStmt->fetch();

        if (!$existing) {
            $pdo->rollBack();
            respond(false, '加量包需要先激活月度会员', [], 400);
        }

        // 给现有会员加额度
        $newTotal = (int)$existing['total_interviews'] + (int)$entry['interview_quota'];
        $pdo->prepare('UPDATE hr_memberships SET total_interviews = ?, updated_at = ? WHERE id = ?')
            ->execute([$newTotal, $now, $existing['id']]);

        $expiresAt = null; // 加量包不延长有效期
    }

    // 标记兑换码已使用
    $pdo->prepare('UPDATE redemption_codes SET status="used", used_by_hr_id=?, used_at=? WHERE id=?')
        ->execute([$hrId, $now, $entry['id']]);

    $pdo->commit();

    // 返回兑换后的会员状态
    $statusStmt = $pdo->prepare('
        SELECT m.*, p.plan_name, p.plan_key
        FROM hr_memberships m
        JOIN membership_plans p ON p.id = m.plan_id
        WHERE m.hr_id = ? AND m.status = "active" AND m.expires_at > NOW()
        ORDER BY m.activated_at DESC LIMIT 1
    ');
    $statusStmt->execute([$hrId]);
    $m = $statusStmt->fetch();

    $remain = $m ? max(0, $m['total_interviews'] - $m['used_interviews']) : 0;
    $remainDays = $m ? max(0, ceil((strtotime($m['expires_at']) - time()) / 86400)) : 0;

    respond(true, '兑换成功', [
        'plan_name' => $m['plan_name'] ?? $entry['plan_name'],
        'plan_key' => $entry['plan_key'],
        'redeemed_at' => $now,
        'expires_at' => $m['expires_at'] ?? null,
        'total_interviews' => (int)($m['total_interviews'] ?? 0),
        'remain_interviews' => $remain,
        'remain_days' => $remainDays,
    ]);

} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
