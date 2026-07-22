<?php
require_once __DIR__ . '/../bootstrap.php';

$hr = require_hr($pdo);
$jobId = (int)($_GET['job_id'] ?? 0);

if ($jobId <= 0) {
    respond(false, '请先选择初面任务', [], 422);
}

$stmt = $pdo->prepare('SELECT id FROM hr_jobs WHERE id=? AND hr_id=? LIMIT 1');
$stmt->execute([$jobId, (int)$hr['id']]);
if (!$stmt->fetch()) {
    respond(false, '初面任务不存在，或当前账号无权访问', [], 404);
}

if (!table_exists($pdo, 'ai_interview_questions')) {
    respond(true, 'AI题库数据表未初始化', ['questions' => []]);
}

$questions = $pdo->prepare('SELECT id, question_text, question_type, difficulty, purpose, sort_order, is_required, source, created_at FROM ai_interview_questions WHERE job_id=? AND hr_id=? ORDER BY sort_order ASC, id ASC LIMIT 13');
$questions->execute([$jobId, (int)$hr['id']]);

respond(true, '题目列表', ['questions' => $questions->fetchAll()]);
