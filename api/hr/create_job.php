<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$hr = require_hr($pdo);
$input = json_input();

$jobId = (int)($input['job_id'] ?? 0);
$companyName = trim((string)($input['company_name'] ?? ''));
$jobTitle = trim((string)($input['job_title'] ?? ''));
$jobType = trim((string)($input['job_type'] ?? ''));
$jobTypeCustom = trim((string)($input['job_type_custom'] ?? ''));
$jobDirection = trim((string)($input['job_direction'] ?? ''));
$jobDirectionCustom = trim((string)($input['job_direction_custom'] ?? ''));
$jobLevel = trim((string)($input['job_level'] ?? 'entry_staff'));
$questionBank = trim((string)($input['question_bank'] ?? ''));
$salaryMin = (int)($input['salary_min_k'] ?? 0);
$salaryMax = (int)($input['salary_max_k'] ?? 0);
$benefits = trim((string)($input['benefits'] ?? ''));
$companyIntro = trim((string)($input['company_intro'] ?? ''));
$responsibilities = trim((string)($input['responsibilities'] ?? ''));
$requirements = trim((string)($input['requirements'] ?? ''));
$hrId = (int)$hr['id'];

$allowedJobTypes = [
    'sales_business', 'marketing_operation', 'customer_service', 'retail_store',
    'logistics_delivery', 'production_manufacturing', 'food_hospitality',
    'life_property_service', 'administration', 'human_resources', 'finance_legal',
    'tech_rd', 'product_design', 'education_training', 'medical_health',
    'construction_property', 'flexible_parttime', 'other',
];
$allowedJobLevels = ['intern_parttime', 'entry_staff', 'senior_staff', 'lead_supervisor', 'manager', 'director_plus'];

if ($jobType === 'custom') {
    $jobType = $jobTypeCustom;
}
if ($jobDirection === 'custom') {
    $jobDirection = $jobDirectionCustom;
}
if ($jobDirection === '' && $jobType !== '') {
    $jobDirection = '其他';
}

if ($companyName === '' || $jobTitle === '' || $responsibilities === '' || $requirements === '') {
    respond(false, '请填写公司名称、岗位名称、岗位职责和任职要求', [], 422);
}
if ($jobType === '') {
    respond(false, '请选择或填写岗位大类', [], 422);
}
if ($jobDirection === '') {
    respond(false, '请选择或填写岗位方向', [], 422);
}
if (!in_array($jobType, $allowedJobTypes, true)) {
    $jobType = mb_substr($jobType, 0, 80, 'UTF-8');
}
if (!in_array($jobLevel, $allowedJobLevels, true)) {
    respond(false, '请选择岗位层级', [], 422);
}
if ($salaryMin < 2 || $salaryMin > 10 || $salaryMax < 5 || $salaryMax > 20 || $salaryMin > $salaryMax) {
    respond(false, '请确认薪资范围是否正确', [], 422);
}

$hasJobTypeColumns = column_exists($pdo, 'hr_jobs', 'job_type') && column_exists($pdo, 'hr_jobs', 'job_level');
$hasJobDirectionColumn = column_exists($pdo, 'hr_jobs', 'job_direction');
$hasJobSerialColumn = column_exists($pdo, 'hr_jobs', 'job_serial');

$baseColumns = ['company_name', 'job_title'];
$baseValues = [
    mb_substr($companyName, 0, 160, 'UTF-8'),
    mb_substr($jobTitle, 0, 120, 'UTF-8'),
];

if ($hasJobTypeColumns) {
    $baseColumns[] = 'job_type';
    $baseValues[] = mb_substr($jobType, 0, 80, 'UTF-8');
    if ($hasJobDirectionColumn) {
        $baseColumns[] = 'job_direction';
        $baseValues[] = mb_substr($jobDirection, 0, 120, 'UTF-8');
    }
    $baseColumns[] = 'job_level';
    $baseValues[] = $jobLevel;
}

$baseColumns = array_merge($baseColumns, [
    'question_bank', 'salary_min_k', 'salary_max_k', 'benefits',
    'company_intro', 'responsibilities', 'requirements',
]);
$baseValues = array_merge($baseValues, [
    mb_substr($questionBank, 0, 120, 'UTF-8'),
    $salaryMin,
    $salaryMax,
    mb_substr($benefits, 0, 500, 'UTF-8'),
    $companyIntro,
    $responsibilities,
    $requirements,
]);

if ($jobId > 0) {
    $check = $pdo->prepare('SELECT id FROM hr_jobs WHERE id=? AND hr_id=? LIMIT 1');
    $check->execute([$jobId, $hrId]);
    if (!$check->fetch()) {
        respond(false, '初面任务不存在，或当前账号无权修改', [], 404);
    }
    $sets = array_map(static fn($column) => $column . '=?', $baseColumns);
    $stmt = $pdo->prepare('UPDATE hr_jobs SET ' . implode(', ', $sets) . ', updated_at=NOW() WHERE id=? AND hr_id=?');
    $stmt->execute(array_merge($baseValues, [$jobId, $hrId]));
    respond(true, '岗位信息已更新', ['job_id' => $jobId, 'next' => 'create-interview-flow.html?job_id=' . $jobId]);
}

$insertColumns = ['hr_id'];
$insertValues = [$hrId];
if ($hasJobSerialColumn) {
    $insertColumns[] = 'job_serial';
    $insertValues[] = next_hr_job_serial($pdo, $hrId);
}
$insertColumns = array_merge($insertColumns, $baseColumns, ['status', 'created_at', 'updated_at']);
$placeholders = array_fill(0, count($insertValues) + count($baseValues), '?');
$placeholders[] = '"active"';
$placeholders[] = 'NOW()';
$placeholders[] = 'NOW()';

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO hr_jobs(' . implode(', ', $insertColumns) . ') VALUES(' . implode(',', $placeholders) . ')');
    $stmt->execute(array_merge($insertValues, $baseValues));
    $newJobId = (int)$pdo->lastInsertId();
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    respond(false, '岗位保存失败，请稍后重试', ['error' => $e->getMessage()], 500);
}

respond(true, '岗位信息已保存', ['job_id' => $newJobId, 'next' => 'create-interview-flow.html?job_id=' . $newJobId]);
