<?php
require_once __DIR__ . '/../bootstrap.php';

require_platform_admin($pdo);

$summary = [
    'hr_count' => (int)$pdo->query('SELECT COUNT(*) FROM hr_accounts')->fetchColumn(),
    'candidate_count' => (int)$pdo->query('SELECT COUNT(*) FROM candidates WHERE phone_verified_at IS NOT NULL AND is_test=0')->fetchColumn(),
    'test_candidate_count' => (int)$pdo->query('SELECT COUNT(*) FROM candidates WHERE is_test=1')->fetchColumn(),
    'report_count' => table_exists($pdo, 'candidate_interview_reports')
        ? (int)$pdo->query('SELECT COUNT(*) FROM candidate_interview_reports')->fetchColumn()
        : 0,
];

$hrStmt = $pdo->query('
    SELECT id, name, phone, email, realname_status, company_verification_status, is_platform_admin, created_at
    FROM hr_accounts
    ORDER BY id DESC
    LIMIT 100
');
$hrs = [];
foreach ($hrStmt->fetchAll() as $row) {
    $row['phone'] = mask_phone((string)$row['phone']);
    $row['email'] = mask_email((string)($row['email'] ?? ''));
    $hrs[] = $row;
}

$reportSelect = table_exists($pdo, 'candidate_interview_reports')
    ? 'rep.id AS report_id, rep.status AS report_status'
    : 'NULL AS report_id, NULL AS report_status';
$reportJoin = table_exists($pdo, 'candidate_interview_reports')
    ? 'LEFT JOIN candidate_interview_reports rep ON rep.candidate_id=c.id'
    : '';
$candidateStmt = $pdo->query("
    SELECT c.id, c.phone, c.phone_verified_at, c.realname_status, c.candidate_status, c.is_test,
      ses.interview_no,
      c.created_at, j.job_title, h.name AS hr_name, {$reportSelect}
    FROM candidates c
    LEFT JOIN hr_jobs j ON j.id=c.job_id
    LEFT JOIN hr_accounts h ON h.id=c.hr_id
    LEFT JOIN candidate_interview_sessions ses ON ses.id = (
      SELECT MAX(s2.id) FROM candidate_interview_sessions s2 WHERE s2.candidate_id = c.id
    )
    {$reportJoin}
    ORDER BY c.id DESC
    LIMIT 200
");
$candidates = [];
foreach ($candidateStmt->fetchAll() as $row) {
    $row['phone'] = !empty($row['phone']) ? mask_phone((string)$row['phone']) : '';
    $candidates[] = $row;
}

respond(true, '平台管理数据', [
    'summary' => $summary,
    'hr_accounts' => $hrs,
    'candidates' => $candidates,
]);
