<?php
require_once __DIR__ . '/../bootstrap.php';

$hr = require_hr($pdo);
$hrId = (int)$hr['id'];

$hasResumeTable = table_exists($pdo, 'candidate_resumes');
$hasSessionTable = table_exists($pdo, 'candidate_interview_sessions');
$hasRealName = column_exists($pdo, 'candidates', 'real_name');
$hasInterviewNo = $hasSessionTable && column_exists($pdo, 'candidate_interview_sessions', 'interview_no');
$jobLocationSelect = column_exists($pdo, 'hr_jobs', 'work_location') ? 'j.work_location' : 'NULL AS work_location';

$nameSelect = $hasRealName ? 'c.real_name' : 'NULL AS real_name';
$resumeSelect = $hasResumeTable
    ? 'r.original_name AS resume_name, r.created_at AS resume_uploaded_at'
    : 'NULL AS resume_name, NULL AS resume_uploaded_at';
$resumeJoin = $hasResumeTable
    ? 'LEFT JOIN candidate_resumes r ON r.id = (SELECT r2.id FROM candidate_resumes r2 WHERE r2.candidate_id = c.id AND r2.status = "uploaded" ORDER BY r2.id DESC LIMIT 1)'
    : '';
$sessionSelect = $hasSessionTable
    ? ($hasInterviewNo
        ? 's.id AS session_id, s.status AS session_status, s.interview_no AS interview_no, s.completed_at AS interview_completed_at'
        : 's.id AS session_id, s.status AS session_status, NULL AS interview_no, s.completed_at AS interview_completed_at')
    : 'NULL AS session_id, NULL AS session_status, NULL AS interview_no, NULL AS interview_completed_at';
$sessionJoin = $hasSessionTable
    ? 'LEFT JOIN candidate_interview_sessions s ON s.id = (SELECT s2.id FROM candidate_interview_sessions s2 WHERE s2.candidate_id = c.id ORDER BY s2.id DESC LIMIT 1)'
    : '';

$stmt = $pdo->prepare("
    SELECT
      c.id,
      {$nameSelect},
      c.invite_token,
      c.phone,
      c.phone_verified_at,
      c.realname_status,
      c.realname_verified_at,
      c.candidate_status,
      c.created_at,
      c.updated_at,
      j.job_title,
      {$jobLocationSelect},
      j.company_name,
      {$resumeSelect},
      {$sessionSelect}
    FROM candidates c
    LEFT JOIN hr_jobs j ON j.id = c.job_id AND j.hr_id = c.hr_id
    {$resumeJoin}
    {$sessionJoin}
    WHERE c.hr_id=?
      AND c.phone_verified_at IS NOT NULL
    ORDER BY c.updated_at DESC, c.id DESC
    LIMIT 200
");
$stmt->execute([$hrId]);

respond(true, '候选人列表', ['candidates' => $stmt->fetchAll()]);
