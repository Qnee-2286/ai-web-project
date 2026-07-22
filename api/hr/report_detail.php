<?php
require_once __DIR__ . '/../bootstrap.php';

$hr = require_hr($pdo);
$hrId = (int)$hr['id'];
$candidateId = (int)($_GET['candidate_id'] ?? 0);
if ($candidateId <= 0) {
    respond(false, '缺少候选人参数', [], 422);
}

$hasRealName = column_exists($pdo, 'candidates', 'real_name');
$nameSelect = $hasRealName ? 'c.real_name' : 'NULL AS real_name';
$hasInterviewNo = table_exists($pdo, 'candidate_interview_sessions') && column_exists($pdo, 'candidate_interview_sessions', 'interview_no');
$interviewNoSelect = $hasInterviewNo ? 's.interview_no' : 'NULL AS interview_no';
$deviceCheckSelect = column_exists($pdo, 'candidate_interview_sessions', 'device_check_json') ? 's.device_check_json' : 'NULL AS device_check_json';
$jobLocationSelect = column_exists($pdo, 'hr_jobs', 'work_location') ? 'j.work_location' : 'NULL AS work_location';
$jobSalaryUnitSelect = column_exists($pdo, 'hr_jobs', 'salary_unit') ? 'j.salary_unit' : '"K/月" AS salary_unit';

$stmt = $pdo->prepare("
    SELECT
      c.id,
      {$nameSelect},
      c.phone,
      c.realname_status,
      c.candidate_status,
      c.updated_at AS candidate_updated_at,
      j.job_title,
      j.company_name,
      j.salary_min_k,
      j.salary_max_k,
      {$jobLocationSelect},
      {$jobSalaryUnitSelect},
      j.benefits,
      s.id AS session_id,
      {$interviewNoSelect},
      s.status AS session_status,
      s.started_at,
      s.completed_at,
      {$deviceCheckSelect},
      rep.id AS report_id,
      rep.status AS report_status,
      rep.summary,
      rep.basic_score,
      rep.match_score,
      rep.keywords,
      rep.concerns,
      rep.recommendation,
      rep.updated_at AS report_updated_at
    FROM candidates c
    LEFT JOIN hr_jobs j ON j.id = c.job_id AND j.hr_id = c.hr_id
    LEFT JOIN candidate_interview_sessions s ON s.id = (
      SELECT s2.id FROM candidate_interview_sessions s2 WHERE s2.candidate_id = c.id ORDER BY s2.id DESC LIMIT 1
    )
    LEFT JOIN candidate_interview_reports rep ON rep.session_id = s.id
    WHERE c.id=? AND c.hr_id=?
    LIMIT 1
");
$stmt->execute([$candidateId, $hrId]);
$detail = $stmt->fetch();
if (!$detail) {
    respond(false, '候选人不存在，或当前账号无权查看', [], 404);
}

$deviceCheck = json_decode((string)($detail['device_check_json'] ?? ''), true);
if (!is_array($deviceCheck)) {
    $deviceCheck = [];
}

$resume = null;
if (table_exists($pdo, 'candidate_resumes')) {
    $resumeStmt = $pdo->prepare('SELECT original_name, file_size, created_at FROM candidate_resumes WHERE candidate_id=? AND status="uploaded" ORDER BY id DESC LIMIT 1');
    $resumeStmt->execute([$candidateId]);
    $resume = $resumeStmt->fetch() ?: null;
}

$recordings = [];
if (!empty($detail['session_id']) && table_exists($pdo, 'candidate_interview_recordings')) {
    ensure_interview_recordings_schema($pdo);
    $transcriptSelect = column_exists($pdo, 'candidate_interview_recordings', 'transcript_text') ? ', transcript_text' : ', NULL AS transcript_text';
    $recordingStmt = $pdo->prepare('SELECT id, question_text, question_type, sort_order, audio_object_key, audio_size, audio_seconds, transcript_status, created_at' . $transcriptSelect . ' FROM candidate_interview_recordings WHERE session_id=? AND candidate_id=? ORDER BY sort_order ASC');
    $recordingStmt->execute([(int)$detail['session_id'], $candidateId]);
    $recordings = $recordingStmt->fetchAll();
    if ($recordings && table_exists($pdo, 'candidate_interview_answers')) {
        $answerStmt = $pdo->prepare('SELECT sort_order, answer_text FROM candidate_interview_answers WHERE session_id=? AND candidate_id=? ORDER BY sort_order ASC');
        $answerStmt->execute([(int)$detail['session_id'], $candidateId]);
        $answers = [];
        foreach ($answerStmt->fetchAll() as $answer) {
            $answers[(int)$answer['sort_order']] = (string)($answer['answer_text'] ?? '');
        }
        foreach ($recordings as &$recording) {
            $sort = (int)($recording['sort_order'] ?? 0);
            if (trim((string)($recording['transcript_text'] ?? '')) === '' && trim($answers[$sort] ?? '') !== '') {
                $recording['transcript_text'] = $answers[$sort];
                if (($recording['transcript_status'] ?? '') === 'pending') {
                    $recording['transcript_status'] = 'completed';
                }
            }
        }
        unset($recording);
    }
}

respond(true, '报告详情', [
    'candidate' => [
        'id' => (int)$detail['id'],
        'real_name' => $detail['real_name'] ?? null,
        'phone' => $detail['phone'] ?? null,
        'realname_status' => $detail['realname_status'] ?? null,
        'candidate_status' => $detail['candidate_status'] ?? null,
    ],
    'job' => [
        'company_name' => $detail['company_name'] ?? null,
        'job_title' => $detail['job_title'] ?? null,
        'salary_min_k' => $detail['salary_min_k'] ?? null,
        'salary_max_k' => $detail['salary_max_k'] ?? null,
        'work_location' => $detail['work_location'] ?? null,
        'salary_unit' => $detail['salary_unit'] ?? 'K/月',
        'benefits' => $detail['benefits'] ?? null,
    ],
    'session' => [
        'id' => isset($detail['session_id']) ? (int)$detail['session_id'] : null,
        'interview_no' => $detail['interview_no'] ?? null,
        'status' => $detail['session_status'] ?? null,
        'started_at' => $detail['started_at'] ?? null,
        'completed_at' => $detail['completed_at'] ?? null,
        'device_check' => $deviceCheck,
    ],
    'resume' => $resume,
    'report' => [
        'id' => isset($detail['report_id']) ? (int)$detail['report_id'] : null,
        'status' => $detail['report_status'] ?? null,
        'summary' => $detail['summary'] ?? null,
        'basic_score' => $detail['basic_score'] ?? null,
        'match_score' => $detail['match_score'] ?? null,
        'keywords' => $detail['keywords'] ?? null,
        'concerns' => $detail['concerns'] ?? null,
        'recommendation' => $detail['recommendation'] ?? null,
        'updated_at' => $detail['report_updated_at'] ?? null,
    ],
    'recordings' => $recordings,
]);
