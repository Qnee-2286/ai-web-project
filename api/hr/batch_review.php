<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$hr = require_hr($pdo);
$hrId = (int)$hr['id'];
$input = json_input();
$decision = (string)($input['decision'] ?? '');
$sessionIds = $input['session_ids'] ?? [];

if (!in_array($decision, ['continue', 'hold', 'reject'], true)) {
    respond(false, '复核结果无效', [], 422);
}
if (!is_array($sessionIds) || empty($sessionIds)) {
    respond(false, '请选择要操作的报告', [], 422);
}
if (!table_exists($pdo, 'candidate_interview_reports') || !table_exists($pdo, 'candidate_interview_sessions')) {
    respond(false, '报告表或面试记录表未初始化', [], 500);
}

$updated = 0;
$decisionMap = ['continue' => 'completed', 'hold' => 'review_pending', 'reject' => 'rejected'];

foreach ($sessionIds as $sid) {
    $sessionId = (int)$sid;
    if ($sessionId <= 0) continue;

    // 查找该 session 对应的候选人和报告
    $stmt = $pdo->prepare("
        SELECT
          c.id AS candidate_id,
          c.job_id,
          s.id AS session_id,
          rep.id AS report_id
        FROM candidate_interview_sessions s
        JOIN candidates c ON c.id = s.candidate_id
        LEFT JOIN candidate_interview_reports rep ON rep.session_id = s.id
        WHERE s.id=? AND c.hr_id=?
        LIMIT 1
    ");
    $stmt->execute([$sessionId, $hrId]);
    $row = $stmt->fetch();
    if (!$row) continue;

    // 更新或创建报告
    if (!empty($row['report_id'])) {
        $update = $pdo->prepare('
            UPDATE candidate_interview_reports
            SET status=?, recommendation=?, updated_at=NOW()
            WHERE id=? AND hr_id=?
        ');
        $update->execute(['reviewed', $decision, (int)$row['report_id'], $hrId]);
    } else {
        $insert = $pdo->prepare('
            INSERT INTO candidate_interview_reports
              (session_id, candidate_id, hr_id, job_id, status, summary, recommendation, created_at, updated_at)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ');
        $insert->execute([
            $sessionId,
            (int)$row['candidate_id'],
            $hrId,
            (int)$row['job_id'],
            'reviewed',
            'HR批量复核',
            $decision,
        ]);
    }

    // 更新候选人状态
    $candidateStatus = $decisionMap[$decision] ?? 'review_pending';
    $candidateUpdate = $pdo->prepare('UPDATE candidates SET candidate_status=?, updated_at=NOW() WHERE id=? AND hr_id=?');
    $candidateUpdate->execute([$candidateStatus, (int)$row['candidate_id'], $hrId]);

    $updated++;
}

respond(true, "批量操作完成，已更新 {$updated} 份报告", ['updated' => $updated]);
