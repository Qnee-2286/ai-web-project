<?php
require_once __DIR__ . '/../bootstrap.php';

$hr = require_hr($pdo);
$hrId = (int)$hr['id'];
$selectedStatus = (string)($_GET['status'] ?? 'pending');
$allowedStatuses = ['pending', 'continue', 'hold', 'reject', 'all'];
if (!in_array($selectedStatus, $allowedStatuses, true)) {
    $selectedStatus = 'pending';
}

$hasResumeTable = table_exists($pdo, 'candidate_resumes');
$hasSessionTable = table_exists($pdo, 'candidate_interview_sessions');
$hasReportTable = table_exists($pdo, 'candidate_interview_reports');
if (!$hasSessionTable) {
    respond(true, '报告复核列表', [
        'reports' => [],
        'counts' => ['pending' => 0, 'continue' => 0, 'hold' => 0, 'reject' => 0, 'all' => 0],
        'current_status' => $selectedStatus,
    ]);
}

$hasRealName = column_exists($pdo, 'candidates', 'real_name');
$hasInterviewNo = column_exists($pdo, 'candidate_interview_sessions', 'interview_no');
$jobLocationSelect = column_exists($pdo, 'hr_jobs', 'work_location') ? 'j.work_location' : 'NULL AS work_location';

$nameSelect = $hasRealName ? 'c.real_name' : 'NULL AS real_name';
$resumeSelect = $hasResumeTable ? 'r.original_name AS resume_name' : 'NULL AS resume_name';
$resumeJoin = $hasResumeTable
    ? 'LEFT JOIN candidate_resumes r ON r.id = (SELECT r2.id FROM candidate_resumes r2 WHERE r2.candidate_id = c.id AND r2.status = "uploaded" ORDER BY r2.id DESC LIMIT 1)'
    : '';
$sessionSelect = $hasInterviewNo
    ? 's.id AS session_id, s.interview_no, s.status AS session_status, s.completed_at AS interview_completed_at'
    : 's.id AS session_id, NULL AS interview_no, s.status AS session_status, s.completed_at AS interview_completed_at';
$sessionJoin = 'LEFT JOIN candidate_interview_sessions s ON s.id = (SELECT s2.id FROM candidate_interview_sessions s2 WHERE s2.candidate_id = c.id ORDER BY s2.id DESC LIMIT 1)';
$reportSelect = $hasReportTable
    ? 'rep.id AS report_id, rep.summary, rep.basic_score, rep.match_score, rep.keywords, rep.concerns, rep.recommendation, rep.status AS report_status, rep.updated_at AS report_updated_at'
    : 'NULL AS report_id, NULL AS summary, NULL AS basic_score, NULL AS match_score, NULL AS keywords, NULL AS concerns, NULL AS recommendation, NULL AS report_status, NULL AS report_updated_at';
$reportJoin = $hasReportTable ? 'LEFT JOIN candidate_interview_reports rep ON rep.session_id = s.id' : '';
$orderExpr = $hasReportTable ? 'COALESCE(rep.updated_at, s.completed_at, c.updated_at)' : 'COALESCE(s.completed_at, c.updated_at)';

$stmt = $pdo->prepare("
    SELECT
      c.id,
      {$nameSelect},
      c.phone,
      c.candidate_status,
      c.updated_at,
      j.job_title,
      {$jobLocationSelect},
      {$resumeSelect},
      {$sessionSelect},
      {$reportSelect}
    FROM candidates c
    LEFT JOIN hr_jobs j ON j.id = c.job_id AND j.hr_id = c.hr_id
    {$resumeJoin}
    {$sessionJoin}
    {$reportJoin}
    WHERE c.hr_id=?
      AND s.status IN ('completed', 'report_ready')
    ORDER BY {$orderExpr} DESC, c.id DESC
    LIMIT 300
");
$stmt->execute([$hrId]);
$rows = $stmt->fetchAll();

$counts = ['pending' => 0, 'continue' => 0, 'hold' => 0, 'reject' => 0, 'all' => 0];
$reports = [];
foreach ($rows as $row) {
    $reportStatus = (string)($row['report_status'] ?? '');
    $recommendation = (string)($row['recommendation'] ?? '');
    $bucket = 'pending';
    if ($reportStatus === 'reviewed' && in_array($recommendation, ['continue', 'hold', 'reject'], true)) {
        $bucket = $recommendation;
    }
    $row['review_bucket'] = $bucket;
    $counts[$bucket]++;
    $counts['all']++;
    if ($selectedStatus === 'all' || $selectedStatus === $bucket) {
        $reports[] = $row;
    }
}

respond(true, '报告复核列表', [
    'reports' => $reports,
    'counts' => $counts,
    'current_status' => $selectedStatus,
]);
