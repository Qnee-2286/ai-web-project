<?php
require_once __DIR__ . '/../bootstrap.php';

$token = trim((string)($_GET['token'] ?? ($_SESSION['candidate_token'] ?? '')));
$candidate = candidate_by_token($pdo, $token);
if (!$candidate) {
    respond(false, '候选人链接无效，请从HR发送的邀请链接重新进入', [], 401);
}
if (empty($candidate['job_id'])) {
    respond(false, '当前面试链接尚未关联岗位，请联系发起面试的HR', [], 404);
}

$extra = '';
if (column_exists($pdo, 'hr_jobs', 'work_location')) {
    $extra .= ', work_location';
}
if (column_exists($pdo, 'hr_jobs', 'salary_unit')) {
    $extra .= ', salary_unit';
}
$stmt = $pdo->prepare('SELECT id, company_name, job_title, salary_min_k, salary_max_k' . $extra . ', benefits, company_intro, responsibilities, requirements FROM hr_jobs WHERE id=? AND status="active" LIMIT 1');
$stmt->execute([(int)$candidate['job_id']]);
$job = $stmt->fetch();
if (!$job) {
    respond(false, '岗位信息不存在或已关闭', [], 404);
}
$job['work_location'] = $job['work_location'] ?? '';
$job['salary_unit'] = $job['salary_unit'] ?? 'K/月';

respond(true, '岗位信息', [
    'candidate_token' => $token,
    'candidate_status' => $candidate['candidate_status'] ?? 'not_received',
    'realname_status' => $candidate['realname_status'] ?? 'pending',
    'job' => $job,
]);
