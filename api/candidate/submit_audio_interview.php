<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$input = json_input();
$token = trim((string)($input['token'] ?? ($_SESSION['candidate_token'] ?? '')));
$sessionId = (int)($input['session_id'] ?? 0);

$candidate = candidate_by_token($pdo, $token);
if (!$candidate) {
    respond(false, '候选人链接无效，请从HR发送的邀请链接重新进入', [], 401);
}
if ($sessionId <= 0) {
    respond(false, '面试会话无效', [], 422);
}
if (!table_exists($pdo, 'candidate_interview_recordings')) {
    respond(false, '录音数据表未初始化，请联系平台工作人员', [], 500);
}
if (!table_exists($pdo, 'candidate_interview_materials')) {
    respond(false, '面试材料表未初始化，请先导入 upgrade_20260530_candidate_materials.sql', [], 500);
}

$sessionStmt = $pdo->prepare('SELECT * FROM candidate_interview_sessions WHERE id=? AND candidate_id=? LIMIT 1');
$sessionStmt->execute([$sessionId, (int)$candidate['id']]);
$session = $sessionStmt->fetch();
if (!$session) {
    respond(false, '当前面试会话不存在', [], 404);
}

$questionStmt = $pdo->prepare('SELECT COUNT(*) FROM ai_interview_questions WHERE job_id=?');
$questionStmt->execute([(int)($candidate['job_id'] ?? 0)]);
$questionCount = min(13, (int)$questionStmt->fetchColumn());

$recordingStmt = $pdo->prepare('SELECT COUNT(DISTINCT sort_order) FROM candidate_interview_recordings WHERE session_id=? AND candidate_id=?');
$recordingStmt->execute([$sessionId, (int)$candidate['id']]);
$recordingCount = (int)$recordingStmt->fetchColumn();
if ($recordingCount <= 0) {
    respond(false, '还没有保存任何语音回答，不能提交面试', [], 422);
}
if ($questionCount > 0 && $recordingCount < $questionCount) {
    respond(false, '还有面试题没有完成，请继续完成后再提交', [
        'recording_count' => $recordingCount,
        'question_count' => $questionCount,
    ], 422);
}

$pdo->beginTransaction();
try {
    $updateSession = $pdo->prepare('UPDATE candidate_interview_sessions SET status="completed", completed_at=COALESCE(completed_at, NOW()), updated_at=NOW() WHERE id=?');
    $updateSession->execute([$sessionId]);

    $updateCandidate = $pdo->prepare('UPDATE candidates SET candidate_status="completed", updated_at=NOW() WHERE id=?');
    $updateCandidate->execute([(int)$candidate['id']]);

    $material = $pdo->prepare('INSERT INTO candidate_interview_materials(session_id, candidate_id, merge_status, transcript_status, report_status, created_at, updated_at)
        VALUES(?, ?, "pending", "pending", "pending", NOW(), NOW())
        ON DUPLICATE KEY UPDATE updated_at=NOW()');
    $material->execute([$sessionId, (int)$candidate['id']]);

    if (table_exists($pdo, 'candidate_interview_reports')) {
        try {
            $report = $pdo->prepare('INSERT INTO candidate_interview_reports(session_id, candidate_id, hr_id, job_id, status, summary, created_at, updated_at)
                VALUES(?, ?, ?, ?, "draft", "候选人已完成语音初面，系统正在整理转写和报告。", NOW(), NOW())
                ON DUPLICATE KEY UPDATE summary=VALUES(summary), updated_at=NOW()');
            $report->execute([
                $sessionId,
                (int)$candidate['id'],
                (int)($candidate['hr_id'] ?? $session['hr_id'] ?? 0),
                (int)($candidate['job_id'] ?? $session['job_id'] ?? 0),
            ]);
        } catch (Throwable $reportError) {
            $materialError = $pdo->prepare('UPDATE candidate_interview_materials SET error_message=?, updated_at=NOW() WHERE session_id=?');
            $materialError->execute(['报告占位记录创建失败：' . $reportError->getMessage(), $sessionId]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    respond(false, '面试提交失败，请稍后再试', ['error' => $e->getMessage()], 500);
}

respond(true, '面试已提交，系统正在整理材料', [
    'candidate_token' => $token,
    'session_id' => $sessionId,
    'recording_count' => $recordingCount,
    'question_count' => $questionCount,
    'next' => 'processing.html?token=' . urlencode($token) . '&session_id=' . $sessionId,
]);
