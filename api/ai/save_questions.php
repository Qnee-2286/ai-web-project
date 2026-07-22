<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$hr = require_hr($pdo);
$input = json_input();
$jobId = (int)($input['job_id'] ?? 0);
$questions = $input['questions'] ?? [];

if ($jobId <= 0) {
    respond(false, '请先选择初面任务', [], 422);
}

if (!is_array($questions) || count($questions) === 0) {
    respond(false, '请至少保留一道面试问题', [], 422);
}

if (!table_exists($pdo, 'ai_question_sets') || !table_exists($pdo, 'ai_interview_questions')) {
    respond(false, 'AI题库数据表未初始化，请先在宝塔数据库导入 upgrade_20260518_ai_questions.sql', [], 500);
}

$stmt = $pdo->prepare('SELECT id FROM hr_jobs WHERE id=? AND hr_id=? LIMIT 1');
$stmt->execute([$jobId, (int)$hr['id']]);
if (!$stmt->fetch()) {
    respond(false, '初面任务不存在，或当前账号无权访问', [], 404);
}

$clean = [];
foreach ($questions as $item) {
    if (!is_array($item)) {
        continue;
    }
    $question = trim((string)($item['question'] ?? $item['question_text'] ?? ''));
    if ($question === '') {
        continue;
    }
    $clean[] = [
        'question' => $question,
        'type' => mb_substr(trim((string)($item['type'] ?? $item['question_type'] ?? '面试问题')), 0, 40, 'UTF-8'),
        'difficulty' => mb_substr(trim((string)($item['difficulty'] ?? '中等')), 0, 40, 'UTF-8'),
        'purpose' => mb_substr(trim((string)($item['purpose'] ?? '用于初面判断')), 0, 255, 'UTF-8'),
        'is_required' => !empty($item['is_required']) ? 1 : 0,
    ];
}

if (count($clean) < 1) {
    respond(false, '请至少保留1道有效问题', [], 422);
}
if (count($clean) > 13) {
    respond(false, '一场初面最多保留13道题，请删除部分题目后再保存', ['question_count' => count($clean)], 422);
}

try {
    $pdo->beginTransaction();

    $set = $pdo->prepare('INSERT INTO ai_question_sets(hr_id, job_id, provider, model, status, prompt_hash, raw_response, created_at) VALUES(?,?,?,?, "generated", ?, ?, NOW())');
    $set->execute([
        (int)$hr['id'],
        $jobId,
        'manual',
        'hr-edited',
        hash('sha256', json_encode($clean, JSON_UNESCAPED_UNICODE)),
        json_encode(['questions' => $clean], JSON_UNESCAPED_UNICODE),
    ]);
    $setId = (int)$pdo->lastInsertId();

    $delete = $pdo->prepare('DELETE FROM ai_interview_questions WHERE job_id=? AND hr_id=?');
    $delete->execute([$jobId, (int)$hr['id']]);

    $insert = $pdo->prepare('INSERT INTO ai_interview_questions(set_id, hr_id, job_id, question_text, question_type, difficulty, purpose, sort_order, is_required, source, created_at) VALUES(?,?,?,?,?,?,?,?,?,"hr",NOW())');
    $i = 1;
    foreach ($clean as $item) {
        $insert->execute([
            $setId,
            (int)$hr['id'],
            $jobId,
            $item['question'],
            $item['type'],
            $item['difficulty'],
            $item['purpose'],
            $i,
            $item['is_required'],
        ]);
        $i++;
    }

    $pdo->commit();
    respond(true, '面试问题已保存', ['set_id' => $setId, 'questions' => $clean]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(false, '面试问题保存失败：' . $e->getMessage(), [], 500);
}
