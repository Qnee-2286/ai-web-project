<?php
function interview_processing_payload(?string $raw): array
{
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

function interview_start_audio_processing(PDO $pdo, array $config, array $session, array $candidate): array
{
    $sessionId = (int)$session['id'];
    $reportStmt = $pdo->prepare('SELECT * FROM candidate_interview_reports WHERE session_id=? LIMIT 1');
    $reportStmt->execute([$sessionId]);
    $report = $reportStmt->fetch();
    if ($report && ($report['status'] ?? '') === 'ready') {
        return ['status' => 'completed', 'report_id' => (int)$report['id']];
    }
    if ($report && ($report['status'] ?? '') === 'draft') {
        $payload = interview_processing_payload($report['raw_response'] ?? '');
        if (!empty($payload['transcription_task_id'])) {
            return ['status' => 'processing', 'task_id' => (string)$payload['transcription_task_id'], 'report_id' => (int)$report['id']];
        }
    }

    $recordingStmt = $pdo->prepare('SELECT * FROM candidate_interview_recordings WHERE session_id=? AND candidate_id=? ORDER BY sort_order ASC');
    $recordingStmt->execute([$sessionId, (int)$candidate['id']]);
    $recordings = $recordingStmt->fetchAll();
    if (!$recordings) {
        throw new RuntimeException('还没有保存任何语音回答，不能提交面试');
    }

    $urls = [];
    foreach ($recordings as $recording) {
        $urls[] = oss_signed_get_url($config['oss'] ?? [], (string)$recording['audio_object_key'], 7200);
    }
    $task = dashscope_transcription_start($config['llm'] ?? [], $urls);
    $payload = json_encode([
        'processing' => true,
        'transcription_task_id' => $task['task_id'],
        'recording_count' => count($recordings),
    ], JSON_UNESCAPED_UNICODE);

    $draft = $pdo->prepare('INSERT INTO candidate_interview_reports(session_id, candidate_id, hr_id, job_id, status, summary, raw_response, created_at, updated_at) VALUES(?,?,?,?, "draft", ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE status="draft", summary=VALUES(summary), raw_response=VALUES(raw_response), updated_at=NOW()');
    $draft->execute([
        $sessionId,
        (int)$candidate['id'],
        (int)$session['hr_id'],
        (int)$session['job_id'],
        '候选人已提交语音初面，系统正在生成转写记录与初面报告。',
        $payload,
    ]);
    $pdo->prepare('UPDATE candidate_interview_recordings SET transcript_status="processing", updated_at=NOW() WHERE session_id=?')->execute([$sessionId]);
    return ['status' => 'processing', 'task_id' => $task['task_id'], 'report_id' => (int)$pdo->lastInsertId()];
}

function interview_generate_report(PDO $pdo, array $config, array $session, array $candidate): void
{
    $sessionId = (int)$session['id'];
    $answersStmt = $pdo->prepare('SELECT question_text, question_type, answer_text, answer_seconds, sort_order FROM candidate_interview_answers WHERE session_id=? AND candidate_id=? ORDER BY sort_order ASC');
    $answersStmt->execute([$sessionId, (int)$candidate['id']]);
    $answers = $answersStmt->fetchAll();
    if (!$answers) {
        throw new RuntimeException('转写记录尚未生成');
    }

    $jobStmt = $pdo->prepare('SELECT * FROM hr_jobs WHERE id=? LIMIT 1');
    $jobStmt->execute([(int)$session['job_id']]);
    $job = $jobStmt->fetch();
    if (!$job) {
        throw new RuntimeException('岗位信息不存在');
    }

    $summary = '候选人已完成线上语音初面，系统已整理本次问答记录，等待HR复核。';
    $basicScore = null;
    $matchScore = null;
    $keywords = '';
    $concerns = '';
    $recommendation = 'hold';
    $rawResponse = null;

    $qa = [];
    foreach ($answers as $answer) {
        $qa[] = 'Q' . (int)$answer['sort_order'] . '：' . $answer['question_text'] . "\nA：" . $answer['answer_text'];
    }
    try {
        $response = dashscope_chat($config['llm'] ?? [], [
            [
                'role' => 'system',
                'content' => '你是招聘初面报告整理助手。你只整理候选人回答线索，不替代HR作录用、淘汰或推进决定。必须返回JSON对象。',
            ],
            [
                'role' => 'user',
                'content' => implode("\n", [
                    '请根据岗位信息和候选人语音初面转写生成供HR复核使用的初面报告。',
                    '要求：识别真实经历、岗位匹配线索、需人工复核的问题；不要因为表达速度慢直接否定候选人。',
                    'recommendation只能是continue、hold、reject之一，且仅供HR复核参考。',
                    '返回格式：{"summary":"摘要","basic_score":80,"match_score":75,"keywords":"关键词1,关键词2","concerns":"需HR复核的点","recommendation":"hold"}',
                    '',
                    '公司：' . $job['company_name'],
                    '岗位：' . $job['job_title'],
                    '薪资：' . $job['salary_min_k'] . '-' . $job['salary_max_k'] . 'K',
                    '福利：' . ($job['benefits'] ?: '未填写'),
                    '职责：' . $job['responsibilities'],
                    '要求：' . $job['requirements'],
                    '',
                    '候选人问答：',
                    implode("\n\n", $qa),
                ]),
            ],
        ]);
        $parsed = parse_llm_json_object(dashscope_message_content($response));
        $summary = ai_report_text_value($parsed['summary'] ?? null, $summary);
        $basicScore = isset($parsed['basic_score']) ? max(0, min(100, (int)$parsed['basic_score'])) : null;
        $matchScore = isset($parsed['match_score']) ? max(0, min(100, (int)$parsed['match_score'])) : null;
        $keywords = ai_report_keywords_value($parsed['keywords'] ?? '');
        $concerns = ai_report_text_value($parsed['concerns'] ?? null, '');
        $recommendation = ai_report_recommendation_value($parsed['recommendation'] ?? null, $recommendation);
        $rawResponse = json_encode($response, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $concerns = 'AI报告暂未生成完整结论，请HR直接查看语音转写记录进行复核。';
        $rawResponse = json_encode(['report_error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }

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
    $pdo->prepare('UPDATE candidate_interview_sessions SET status="report_ready", updated_at=NOW() WHERE id=?')->execute([$sessionId]);
}

function interview_refresh_audio_processing(PDO $pdo, array $config, int $sessionId, array $candidate): array
{
    $sessionStmt = $pdo->prepare('SELECT * FROM candidate_interview_sessions WHERE id=? AND candidate_id=? LIMIT 1');
    $sessionStmt->execute([$sessionId, (int)$candidate['id']]);
    $session = $sessionStmt->fetch();
    if (!$session) {
        throw new RuntimeException('面试会话不存在');
    }
    $reportStmt = $pdo->prepare('SELECT * FROM candidate_interview_reports WHERE session_id=? LIMIT 1');
    $reportStmt->execute([$sessionId]);
    $report = $reportStmt->fetch();
    if ($report && ($report['status'] ?? '') === 'ready') {
        return ['status' => 'completed', 'report' => $report];
    }
    if (!$report) {
        return ['status' => 'not_started'];
    }
    $payload = interview_processing_payload($report['raw_response'] ?? '');
    $taskId = trim((string)($payload['transcription_task_id'] ?? ''));
    if ($taskId === '') {
        return ['status' => 'failed', 'message' => '转写任务信息不存在，请联系平台工作人员'];
    }

    $task = dashscope_transcription_query($config['llm'] ?? [], $taskId);
    $taskStatus = strtoupper((string)($task['output']['task_status'] ?? ''));
    if (in_array($taskStatus, ['PENDING', 'RUNNING'], true)) {
        return ['status' => 'processing'];
    }
    if ($taskStatus !== 'SUCCEEDED') {
        $error = trim((string)($task['message'] ?? $task['output']['message'] ?? '语音转写失败'));
        $pdo->prepare('UPDATE candidate_interview_recordings SET transcript_status="failed", updated_at=NOW() WHERE session_id=?')->execute([$sessionId]);
        $pdo->prepare('UPDATE candidate_interview_reports SET summary=?, raw_response=?, updated_at=NOW() WHERE session_id=?')->execute([
            '语音转写失败，请联系平台工作人员处理。',
            json_encode(['transcription_task_id' => $taskId, 'error' => $error], JSON_UNESCAPED_UNICODE),
            $sessionId,
        ]);
        return ['status' => 'failed', 'message' => $error];
    }

    $recordingsStmt = $pdo->prepare('SELECT * FROM candidate_interview_recordings WHERE session_id=? AND candidate_id=? ORDER BY sort_order ASC');
    $recordingsStmt->execute([$sessionId, (int)$candidate['id']]);
    $recordings = $recordingsStmt->fetchAll();
    $results = $task['output']['results'] ?? [];
    if (count($results) < count($recordings)) {
        return ['status' => 'processing'];
    }

    $pdo->beginTransaction();
    try {
        foreach ($recordings as $index => $recording) {
            $result = $results[$index] ?? [];
            $url = trim((string)($result['transcription_url'] ?? ''));
            $text = $url !== '' ? dashscope_transcription_text($url) : '';
            if ($text === '') {
                $text = '[本题语音未识别出清晰文字，请HR结合录音复核]';
            }
            $pdo->prepare('UPDATE candidate_interview_recordings SET transcript_status="completed", transcript_text=?, updated_at=NOW() WHERE id=?')->execute([$text, (int)$recording['id']]);
            $pdo->prepare('INSERT INTO candidate_interview_answers(session_id, candidate_id, question_id, question_text, question_type, answer_text, answer_seconds, sort_order, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE question_id=VALUES(question_id), question_text=VALUES(question_text), question_type=VALUES(question_type), answer_text=VALUES(answer_text), answer_seconds=VALUES(answer_seconds), updated_at=NOW()')->execute([
                $sessionId,
                (int)$candidate['id'],
                $recording['question_id'] !== null ? (int)$recording['question_id'] : null,
                $recording['question_text'],
                $recording['question_type'],
                $text,
                $recording['audio_seconds'],
                (int)$recording['sort_order'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    interview_generate_report($pdo, $config, $session, $candidate);
    return ['status' => 'completed'];
}
