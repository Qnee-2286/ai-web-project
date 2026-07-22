<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$hr = require_hr($pdo);
$hrId = (int)$hr['id'];
$input = json_input();
$candidateId = (int)($input['candidate_id'] ?? 0);
$recommendation = (string)($input['recommendation'] ?? 'hold');
$feedback = trim((string)($input['feedback'] ?? ''));
$isDraft = !empty($input['draft']);

if ($candidateId <= 0) {
    respond(false, '缺少候选人参数', [], 422);
}
if (!in_array($recommendation, ['continue', 'hold', 'reject'], true)) {
    respond(false, '复核结果无效', [], 422);
}
if (!table_exists($pdo, 'candidate_interview_reports') || !table_exists($pdo, 'candidate_interview_sessions')) {
    respond(false, '报告表或面试记录表未初始化', [], 500);
}

$stmt = $pdo->prepare("
    SELECT
      c.id AS candidate_id,
      c.job_id,
      s.id AS session_id,
      s.status AS session_status,
      rep.id AS report_id
    FROM candidates c
    LEFT JOIN candidate_interview_sessions s ON s.id = (
      SELECT s2.id FROM candidate_interview_sessions s2 WHERE s2.candidate_id = c.id ORDER BY s2.id DESC LIMIT 1
    )
    LEFT JOIN candidate_interview_reports rep ON rep.session_id = s.id
    WHERE c.id=? AND c.hr_id=?
    LIMIT 1
");
$stmt->execute([$candidateId, $hrId]);
$row = $stmt->fetch();
if (!$row || empty($row['session_id'])) {
    respond(false, '候选人尚未产生可复核的面试记录', [], 422);
}

$status = $isDraft ? 'ready' : 'reviewed';
$summary = $feedback !== '' ? 'HR复核意见：' . $feedback : null;

if (!empty($row['report_id'])) {
    $update = $pdo->prepare('
        UPDATE candidate_interview_reports
        SET status=?, recommendation=?, concerns=IF(?="", concerns, ?), updated_at=NOW()
        WHERE id=? AND hr_id=?
    ');
    $update->execute([$status, $recommendation, $feedback, $summary, (int)$row['report_id'], $hrId]);
} else {
    $insert = $pdo->prepare('
        INSERT INTO candidate_interview_reports
          (session_id, candidate_id, hr_id, job_id, status, summary, concerns, recommendation, created_at, updated_at)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ');
    $insert->execute([
        (int)$row['session_id'],
        $candidateId,
        $hrId,
        (int)$row['job_id'],
        $status,
        '候选人已完成初面，AI报告仍在整理中。HR可先依据录音、简历与转写材料进行人工复核。',
        $summary,
        $recommendation,
    ]);
}

$candidateStatus = $recommendation === 'reject' ? 'rejected' : ($isDraft ? 'review_pending' : 'completed');
$candidateUpdate = $pdo->prepare('UPDATE candidates SET candidate_status=?, updated_at=NOW() WHERE id=? AND hr_id=?');
$candidateUpdate->execute([$candidateStatus, $candidateId, $hrId]);

respond(true, $isDraft ? '复核意见已暂存' : '复核意见已提交', [
    'candidate_id' => $candidateId,
    'recommendation' => $recommendation,
]);
