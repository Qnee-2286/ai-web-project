<?php
require_once __DIR__ . '/../bootstrap.php';

$hr = require_hr($pdo);
$hrId = (int)$hr['id'];
$jobLocationSelect = column_exists($pdo, 'hr_jobs', 'work_location') ? 'j.work_location' : 'NULL AS work_location';
$jobGroupBy = column_exists($pdo, 'hr_jobs', 'work_location') ? 'j.id, j.job_title, j.status, j.work_location' : 'j.id, j.job_title, j.status';

$jobStmt = $pdo->prepare('SELECT COUNT(*) FROM hr_jobs WHERE hr_id=?');
$jobStmt->execute([$hrId]);
$jobCount = (int)$jobStmt->fetchColumn();

$candidateStmt = $pdo->prepare('
    SELECT
      COUNT(*) AS total_candidates,
      SUM(CASE WHEN candidate_status IN ("not_received","pending_interview") THEN 1 ELSE 0 END) AS pending_candidates,
      SUM(CASE WHEN candidate_status IN ("completed","review_pending") THEN 1 ELSE 0 END) AS review_pending
    FROM candidates
    WHERE hr_id=?
      AND phone_verified_at IS NOT NULL
');
$candidateStmt->execute([$hrId]);
$candidate = $candidateStmt->fetch() ?: [];

$recentStmt = $pdo->prepare("
    SELECT
      j.id,
      j.job_title,
      {$jobLocationSelect},
      j.status,
      COUNT(c.id) AS candidate_count,
      SUM(CASE WHEN c.candidate_status IN ('completed','review_pending') THEN 1 ELSE 0 END) AS review_pending_count
    FROM hr_jobs j
    LEFT JOIN candidates c ON c.job_id = j.id AND c.hr_id = j.hr_id AND c.phone_verified_at IS NOT NULL
    WHERE j.hr_id=?
    GROUP BY {$jobGroupBy}
    ORDER BY j.id DESC
    LIMIT 5
");
$recentStmt->execute([$hrId]);
$recentJobs = $recentStmt->fetchAll();

respond(true, '工作台数据', [
    'job_count' => $jobCount,
    'total_candidates' => (int)($candidate['total_candidates'] ?? 0),
    'pending_candidates' => (int)($candidate['pending_candidates'] ?? 0),
    'review_pending' => (int)($candidate['review_pending'] ?? 0),
    'recent_jobs' => $recentJobs,
]);
