<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$input = json_input();
$token = trim((string)($input['token'] ?? ($_SESSION['candidate_token'] ?? '')));
$sessionId = (int)($input['session_id'] ?? 0);
$sortOrder = (int)($input['sort_order'] ?? 0);
$questionId = isset($input['question_id']) && $input['question_id'] !== null ? (int)$input['question_id'] : null;
$questionText = trim((string)($input['question_text'] ?? ''));
$questionType = trim((string)($input['question_type'] ?? ''));
$answerText = trim((string)($input['answer_text'] ?? ''));
$answerSeconds = isset($input['answer_seconds']) ? max(0, (int)$input['answer_seconds']) : null;

$candidate = candidate_by_token($pdo, $token);
if (!$candidate) {
    respond(false, '候选人链接无效，请从HR发送的邀请链接重新进入', [], 401);
}
if ($sessionId <= 0 || $sortOrder <= 0 || $questionText === '') {
    respond(false, '答题参数不完整', [], 422);
}
if ($answerText === '') {
    respond(false, '请先完成本题作答，再保存进入下一题', [], 422);
}
if (!table_exists($pdo, 'candidate_interview_answers')) {
    respond(false, '候选人答题数据表未初始化，请先导入 upgrade_20260521_candidate_interview.sql', [], 500);
}

$sessionStmt = $pdo->prepare('SELECT id FROM candidate_interview_sessions WHERE id=? AND candidate_id=? AND status IN ("interviewing","device_checked") LIMIT 1');
$sessionStmt->execute([$sessionId, (int)$candidate['id']]);
if (!$sessionStmt->fetch()) {
    respond(false, '当前面试会话无效或已结束', [], 404);
}

$stmt = $pdo->prepare('INSERT INTO candidate_interview_answers(session_id, candidate_id, question_id, question_text, question_type, answer_text, answer_seconds, sort_order, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE question_id=VALUES(question_id), question_text=VALUES(question_text), question_type=VALUES(question_type), answer_text=VALUES(answer_text), answer_seconds=VALUES(answer_seconds), updated_at=NOW()');
$stmt->execute([
    $sessionId,
    (int)$candidate['id'],
    $questionId,
    $questionText,
    mb_substr($questionType, 0, 40, 'UTF-8'),
    $answerText,
    $answerSeconds,
    $sortOrder,
]);

respond(true, '本题答案已保存', [
    'session_id' => $sessionId,
    'sort_order' => $sortOrder,
]);
