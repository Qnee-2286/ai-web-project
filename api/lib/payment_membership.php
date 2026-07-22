<?php

function hi_active_membership(PDO $pdo, int $hrId): ?array
{
    $stmt = $pdo->prepare('
        SELECT m.*, p.plan_key, p.plan_name, p.report_days, p.interview_quota, p.valid_days
        FROM hr_memberships m
        JOIN membership_plans p ON p.id = m.plan_id
        WHERE m.hr_id=? AND m.status="active" AND m.expires_at > NOW()
        ORDER BY m.expires_at DESC, m.id DESC
        LIMIT 1
    ');
    $stmt->execute([$hrId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function hi_addon_remaining(PDO $pdo, int $hrId): int
{
    $stmt = $pdo->prepare('SELECT total_interviews, used_interviews FROM hr_addon_balances WHERE hr_id=? LIMIT 1');
    $stmt->execute([$hrId]);
    $row = $stmt->fetch();
    if (!$row) {
        return 0;
    }
    return max(0, (int)$row['total_interviews'] - (int)$row['used_interviews']);
}

function hi_free_used_this_month(PDO $pdo, int $hrId): int
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM candidates
        WHERE hr_id=? AND created_at >= DATE_FORMAT(NOW(), "%Y-%m-01 00:00:00")
    ');
    $stmt->execute([$hrId]);
    return max(0, (int)$stmt->fetchColumn());
}

function hi_membership_snapshot(PDO $pdo, int $hrId): array
{
    $membership = hi_active_membership($pdo, $hrId);
    $addonRemaining = hi_addon_remaining($pdo, $hrId);
    $freeUsed = hi_free_used_this_month($pdo, $hrId);
    $freeRemaining = max(0, 5 - $freeUsed);

    if (!$membership) {
        return [
            'active' => false,
            'plan_name' => 'Free',
            'plan_key' => 'free',
            'activated_at' => null,
            'expires_at' => null,
            'total_interviews' => 5,
            'used_interviews' => $freeUsed,
            'remain_interviews' => $freeRemaining + $addonRemaining,
            'member_remaining' => 0,
            'addon_remaining' => $addonRemaining,
            'free_remaining' => $freeRemaining,
            'remain_days' => 0,
            'report_days' => 7,
        ];
    }

    $memberRemaining = max(0, (int)$membership['total_interviews'] - (int)$membership['used_interviews']);
    $remainDays = max(0, (int)ceil((strtotime((string)$membership['expires_at']) - time()) / 86400));

    return [
        'active' => true,
        'plan_name' => $membership['plan_name'],
        'plan_key' => $membership['plan_key'],
        'activated_at' => $membership['activated_at'],
        'expires_at' => $membership['expires_at'],
        'total_interviews' => (int)$membership['total_interviews'],
        'used_interviews' => (int)$membership['used_interviews'],
        'remain_interviews' => $memberRemaining + $addonRemaining,
        'member_remaining' => $memberRemaining,
        'addon_remaining' => $addonRemaining,
        'free_remaining' => 0,
        'remain_days' => $remainDays,
        'report_days' => (int)$membership['report_days'],
    ];
}

function hi_ensure_order_columns(PDO $pdo): void
{
    $pdo->query('SELECT grant_status, quota_increase FROM hr_orders LIMIT 1');
}

function hi_cleanup_expired_unpaid_orders(PDO $pdo): int
{
    $stmt = $pdo->prepare('
        DELETE FROM hr_orders
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
          AND (pay_status IS NULL OR pay_status <> "paid")
    ');
    $stmt->execute();
    return $stmt->rowCount();
}

function hi_grant_paid_order(PDO $pdo, array $order, array $plan, string $paidAt): int
{
    $orderId = (int)$order['id'];
    $hrId = (int)$order['hr_id'];
    $planKey = (string)$plan['plan_key'];
    $quota = (int)$plan['interview_quota'];
    $now = date('Y-m-d H:i:s');
    $membershipId = 0;

    if (in_array($planKey, ['monthly', 'quarterly', 'yearly'], true)) {
        $active = hi_active_membership($pdo, $hrId);
        $validDays = (int)$plan['valid_days'];

        if ($active && $active['plan_key'] === 'monthly' && $planKey === 'monthly') {
            $baseTime = max(strtotime((string)$active['expires_at']), strtotime($paidAt));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $validDays . ' days', $baseTime));
            $remaining = max(0, (int)$active['total_interviews'] - (int)$active['used_interviews']);
            $newTotal = $remaining + $quota;
            $stmt = $pdo->prepare('
                UPDATE hr_memberships
                SET plan_id=?, activated_at=?, expires_at=?, total_interviews=?, used_interviews=0, status="active", updated_at=?
                WHERE id=?
            ');
            $stmt->execute([(int)$plan['id'], $paidAt, $expiresAt, $newTotal, $now, (int)$active['id']]);
            $membershipId = (int)$active['id'];
        } elseif ($active && in_array($planKey, ['quarterly', 'yearly'], true)) {
            $baseTime = max(strtotime((string)$active['expires_at']), strtotime($paidAt));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $validDays . ' days', $baseTime));
            $newTotal = (int)$active['total_interviews'] + $quota;
            $stmt = $pdo->prepare('
                UPDATE hr_memberships
                SET plan_id=?, activated_at=?, expires_at=?, total_interviews=?, status="active", updated_at=?
                WHERE id=?
            ');
            $stmt->execute([(int)$plan['id'], $paidAt, $expiresAt, $newTotal, $now, (int)$active['id']]);
            $membershipId = (int)$active['id'];
        } else {
            $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $validDays . ' days', strtotime($paidAt)));
            $stmt = $pdo->prepare('
                INSERT INTO hr_memberships
                    (hr_id, plan_id, redemption_code_id, activated_at, expires_at, total_interviews, used_interviews, status, created_at, updated_at)
                VALUES (?, ?, NULL, ?, ?, ?, 0, "active", ?, ?)
            ');
            $stmt->execute([$hrId, (int)$plan['id'], $paidAt, $expiresAt, $quota, $now, $now]);
            $membershipId = (int)$pdo->lastInsertId();
        }
    } elseif ($planKey === 'addon_15' || $planKey === 'addon') {
        if (!hi_active_membership($pdo, $hrId)) {
            throw new RuntimeException('Addon package requires an active membership');
        }

        $stmt = $pdo->prepare('
            INSERT INTO hr_addon_balances (hr_id, total_interviews, used_interviews, created_at, updated_at)
            VALUES (?, ?, 0, ?, ?)
            ON DUPLICATE KEY UPDATE total_interviews = total_interviews + VALUES(total_interviews), updated_at = VALUES(updated_at)
        ');
        $stmt->execute([$hrId, $quota, $now, $now]);
        $active = hi_active_membership($pdo, $hrId);
        $membershipId = $active ? (int)$active['id'] : 0;
    } else {
        throw new RuntimeException('Unknown plan type');
    }

    $stmt = $pdo->prepare('
        UPDATE hr_orders
        SET pay_status="paid", paid_at=?, membership_id=?, quota_increase=?, grant_status="granted", updated_at=?
        WHERE id=? AND pay_status="pending"
    ');
    $stmt->execute([$paidAt, $membershipId ?: null, $quota, $now, $orderId]);

    return $membershipId;
}

function mark_order_paid(PDO $pdo, string $orderNo, string $payMethod, float $paidAmount, string $gatewayOrderNo, string $notifyRaw, string $paidAt): array
{
    hi_ensure_order_columns($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('
            SELECT o.*, p.plan_key, p.plan_name, p.interview_quota, p.valid_days
            FROM hr_orders o
            JOIN membership_plans p ON p.id = o.plan_id
            WHERE o.order_no=? AND o.pay_method=?
            LIMIT 1
            FOR UPDATE
        ');
        $stmt->execute([$orderNo, $payMethod]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new RuntimeException('Order not found');
        }
        if ($order['pay_status'] !== 'paid' && strtotime((string)$order['created_at']) < time() - 86400) {
            $pdo->prepare('DELETE FROM hr_orders WHERE id=? AND (pay_status IS NULL OR pay_status <> "paid")')
                ->execute([(int)$order['id']]);
            throw new RuntimeException('Order expired');
        }

        if ($order['pay_status'] === 'paid') {
            $pdo->commit();
            return ['order' => $order, 'already_paid' => true, 'membership_id' => (int)($order['membership_id'] ?? 0)];
        }
        if ($order['pay_status'] !== 'pending') {
            throw new RuntimeException('Order is not payable');
        }

        $orderAmount = (float)$order['amount'];
        if (abs($orderAmount - $paidAmount) > 0.001) {
            throw new RuntimeException('Paid amount mismatch');
        }

        $plan = [
            'id' => (int)$order['plan_id'],
            'plan_key' => (string)$order['plan_key'],
            'plan_name' => (string)$order['plan_name'],
            'interview_quota' => (int)$order['interview_quota'],
            'valid_days' => (int)$order['valid_days'],
        ];
        $membershipId = hi_grant_paid_order($pdo, $order, $plan, $paidAt);

        $stmt = $pdo->prepare('
            UPDATE hr_orders
            SET gateway_order_no=?, notify_at=?, notify_raw=?, paid_amount=?, updated_at=?
            WHERE id=?
        ');
        $stmt->execute([$gatewayOrderNo, date('Y-m-d H:i:s'), $notifyRaw, $paidAmount, date('Y-m-d H:i:s'), (int)$order['id']]);

        $pdo->commit();
        return ['order' => $order, 'already_paid' => false, 'membership_id' => $membershipId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function hi_consume_interview_quota(PDO $pdo, int $hrId): array
{
    $pdo->beginTransaction();
    try {
        $active = hi_active_membership($pdo, $hrId);
        if ($active) {
            $remaining = max(0, (int)$active['total_interviews'] - (int)$active['used_interviews']);
            if ($remaining > 0) {
                $pdo->prepare('UPDATE hr_memberships SET used_interviews=used_interviews+1, updated_at=NOW() WHERE id=?')
                    ->execute([(int)$active['id']]);
                $pdo->commit();
                return ['ok' => true, 'source' => 'membership'];
            }

            $addon = hi_addon_remaining($pdo, $hrId);
            if ($addon > 0) {
                $pdo->prepare('UPDATE hr_addon_balances SET used_interviews=used_interviews+1, updated_at=NOW() WHERE hr_id=?')
                    ->execute([$hrId]);
                $pdo->commit();
                return ['ok' => true, 'source' => 'addon'];
            }
        } else {
            $freeUsed = hi_free_used_this_month($pdo, $hrId);
            if ($freeUsed < 5) {
                $pdo->commit();
                return ['ok' => true, 'source' => 'free'];
            }

            $addon = hi_addon_remaining($pdo, $hrId);
            if ($addon > 0) {
                $pdo->prepare('UPDATE hr_addon_balances SET used_interviews=used_interviews+1, updated_at=NOW() WHERE hr_id=?')
                    ->execute([$hrId]);
                $pdo->commit();
                return ['ok' => true, 'source' => 'addon'];
            }
        }

        $pdo->rollBack();
        return ['ok' => false, 'source' => 'none'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
