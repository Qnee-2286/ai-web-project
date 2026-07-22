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

$stmt = $pdo->prepare("
    SELECT
      c.id,
      {$nameSelect},
      c.phone,
      c.realname_status,
      c.candidate_status,
      j.company_name,
      j.job_title
    FROM candidates c
    LEFT JOIN hr_jobs j ON j.id = c.job_id AND j.hr_id = c.hr_id
    WHERE c.id=? AND c.hr_id=?
    LIMIT 1
");
$stmt->execute([$candidateId, $hrId]);
$row = $stmt->fetch();
if (!$row) {
    respond(false, '候选人不存在，或当前账号无权查看', [], 404);
}

$resume = null;
if (table_exists($pdo, 'candidate_resumes')) {
    $resumeStmt = $pdo->prepare('
        SELECT original_name, stored_name, storage_path, file_ext, mime_type, file_size, created_at
        FROM candidate_resumes
        WHERE candidate_id=? AND status="uploaded"
        ORDER BY id DESC
        LIMIT 1
    ');
    $resumeStmt->execute([$candidateId]);
    $resume = $resumeStmt->fetch() ?: null;
}

if ($resume) {
    $size = (int)$resume['file_size'];
    if ($size >= 1048576) {
        $resume['file_size_label'] = round($size / 1048576, 2) . ' MB';
    } elseif ($size >= 1024) {
        $resume['file_size_label'] = round($size / 1024, 1) . ' KB';
    } else {
        $resume['file_size_label'] = $size . ' B';
    }
    unset($resume['stored_name'], $resume['storage_path']);
}

respond(true, '简历详情', [
    'candidate' => [
        'id' => (int)$row['id'],
        'real_name' => $row['real_name'] ?? null,
        'phone' => $row['phone'] ?? null,
        'realname_status' => $row['realname_status'] ?? null,
        'candidate_status' => $row['candidate_status'] ?? null,
    ],
    'job' => [
        'company_name' => $row['company_name'] ?? null,
        'job_title' => $row['job_title'] ?? null,
    ],
    'resume' => $resume,
]);
