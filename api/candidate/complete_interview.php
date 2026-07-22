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
if (!table_exists($pdo, 'candidate_interview_reports')) {
    respond(false, '候选人报告数据表未初始化，请先导入 upgrade_20260521_candidate_interview.sql', [], 500);
}

$sessionStmt = $pdo->prepare('SELECT * FROM candidate_interview_sessions WHERE id=? AND candidate_id=? LIMIT 1');
$sessionStmt->execute([$sessionId, (int)$candidate['id']]);
$session = $sessionStmt->fetch();
if (!$session) {
    respond(false, '当前面试会话不存在', [], 404);
}

$answersStmt = $pdo->prepare('SELECT question_text, question_type, answer_text, answer_seconds, sort_order FROM candidate_interview_answers WHERE session_id=? ORDER BY sort_order ASC');
$answersStmt->execute([$sessionId]);
$answers = $answersStmt->fetchAll();
if (count($answers) === 0) {
    respond(false, '还没有保存任何面试答案，不能提交面试', [], 422);
}

$jobStmt = $pdo->prepare('SELECT * FROM hr_jobs WHERE id=? LIMIT 1');
$jobStmt->execute([(int)$session['job_id']]);
$job = $jobStmt->fetch();
if (!$job) {
    respond(false, '岗位信息不存在', [], 404);
}

$summary = '候选人已完成线上初面，系统已保存本次问答记录，等待HR复核。';
$basicScore = null;
$matchScore = null;
$keywords = '';
$concerns = '';
$recommendation = 'hold';
$rawResponse = null;

if (!empty($config['llm']['api_key']) && ($config['llm']['api_key'] ?? '') !== '你的百炼API Key') {
    $qa = [];
    foreach ($answers as $answer) {
        $qa[] = 'Q' . (int)$answer['sort_order'] . '：' . $answer['question_text'] . "\nA：" . $answer['answer_text'];
    }
    $messages = [
        [
            'role' => 'system',
            'content' => '你是招聘初面报告整理助手。你只整理候选人回答线索，不做录用决定。必须返回JSON对象。'
        ],
        [
            'role' => 'user',
            'content' => implode("\n", [
                '请根据岗位信息和候选人线上初面问答，生成给HR复核用的初面报告。',
                '要求：',
                '1. 不向候选人展示匹配分、推进建议或淘汰判断。',
                '2. 分数只作为HR复核线索，不代表自动决策。',
                '3. 重点识别真实经历、岗位匹配线索、表达不清但可能有经验的部分、需要人工复核的问题。',
                '4. recommendation只能是continue、hold、reject之一。',
                '5. 返回格式：{"summary":"摘要","basic_score":80,"match_score":75,"keywords":"关键词1,关键词2","concerns":"需要HR复核的点","recommendation":"hold"}',
                '',
                '岗位信息：',
                '公司：' . $job['company_name'],
                '岗位：' . $job['job_title'],
                '薪资：' . $job['salary_min_k'] . '-' . $job['salary_max_k'] . 'K',
                '福利：' . ($job['benefits'] ?: '未填写'),
                '职责：' . $job['responsibilities'],
                '要求：' . $job['requirements'],
                '',
                '候选人问答：',
                implode("\n\n", $qa),
            ])
        ],
    ];

    try {
        $response = dashscope_chat($config['llm'] ?? [], $messages);
        $content = dashscope_message_content($response);
        $parsed = parse_llm_json_object($content);
        $summary = ai_report_text_value($parsed['summary'] ?? null, $summary);
        $basicScore = isset($parsed['basic_score']) ? max(0, min(100, (int)$parsed['basic_score'])) : null;
        $matchScore = isset($parsed['match_score']) ? max(0, min(100, (int)$parsed['match_score'])) : null;
        $keywords = ai_report_keywords_value($parsed['keywords'] ?? '');
        $concerns = ai_report_text_value($parsed['concerns'] ?? null, '');
        $recommendation = ai_report_recommendation_value($parsed['recommendation'] ?? null, $recommendation);
        $rawResponse = json_encode($response, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $concerns = 'AI报告生成失败，请HR先查看原始问答记录。错误：' . $e->getMessage();
        $rawResponse = json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}

$pdo->beginTransaction();
try {
    $sessionUpdate = $pdo->prepare('UPDATE candidate_interview_sessions SET status="completed", completed_at=NOW(), updated_at=NOW() WHERE id=?');
    $sessionUpdate->execute([$sessionId]);

    $candidateUpdate = $pdo->prepare('UPDATE candidates SET candidate_status="completed", updated_at=NOW() WHERE id=?');
    $candidateUpdate->execute([(int)$candidate['id']]);

    $report = $pdo->prepare('INSERT INTO candidate_interview_reports(session_id, candidate_id, hr_id, job_id, status, summary, basic_score, match_score, keywords, concerns, recommendation, raw_response, created_at, updated_at) VALUES(?,?,?,?, "ready", ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE status="ready", summary=VALUES(summary), basic_score=VALUES(basic_score), match_score=VALUES(match_score), keywords=VALUES(keywords), concerns=VALUES(concerns), recommendation=VALUES(recommendation), raw_response=VALUES(raw_response), updated_at=NOW()');
    $report->execute([
        $sessionId,
        (int)$candidate['id'],
        (int)$session['hr_id'],
        (int)$session['job_id'],
        $summary,
        $basicScore,
        $matchScore,
        $keywords,
        $concerns,
        $recommendation,
        $rawResponse,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    respond(false, '面试提交失败，请稍后再试', ['error' => $e->getMessage()], 500);
}

respond(true, '面试已提交', [
    'candidate_token' => $token,
    'session_id' => $sessionId,
    'next' => 'complete.html?token=' . urlencode($token) . '&session_id=' . $sessionId,
]);
