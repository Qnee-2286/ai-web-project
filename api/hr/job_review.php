<?php
require_once __DIR__ . '/../bootstrap.php';

$hr = require_hr($pdo);
$hrId = (int)$hr['id'];
$jobId = (int)($_GET['job_id'] ?? 0);
if ($jobId <= 0) {
    respond(false, '缺少岗位参数', [], 422);
}

$jobStmt = $pdo->prepare('SELECT * FROM hr_jobs WHERE id=? AND hr_id=? LIMIT 1');
$jobStmt->execute([$jobId, $hrId]);
$job = $jobStmt->fetch();
if (!$job) {
    respond(false, '岗位不存在，或当前账号无权查看', [], 404);
}

$countStmt = $pdo->prepare('
    SELECT COUNT(DISTINCT s.id)
    FROM candidate_interview_sessions s
    INNER JOIN candidates c ON c.id = s.candidate_id
    WHERE c.hr_id=? AND c.job_id=? AND s.status IN ("completed", "report_ready")
');
$countStmt->execute([$hrId, $jobId]);
$completedCount = (int)$countStmt->fetchColumn();

$sampleEnough = $completedCount >= 10;
$topQuestions = [];
$weakSignals = [];

if ($sampleEnough && table_exists($pdo, 'candidate_interview_recordings')) {
    ensure_interview_recordings_schema($pdo);
    $questionStmt = $pdo->prepare('
        SELECT
          COALESCE(NULLIF(r.question_text, ""), CONCAT("第", r.sort_order, "题")) AS question_text,
          COUNT(*) AS answer_count,
          SUM(CASE WHEN r.transcript_text IS NULL OR CHAR_LENGTH(TRIM(r.transcript_text)) < 35 THEN 1 ELSE 0 END) AS short_count,
          SUM(CASE WHEN r.transcript_status="failed" THEN 1 ELSE 0 END) AS failed_count
        FROM candidate_interview_recordings r
        INNER JOIN candidate_interview_sessions s ON s.id = r.session_id
        INNER JOIN candidates c ON c.id = r.candidate_id
        WHERE c.hr_id=? AND c.job_id=? AND s.status IN ("completed", "report_ready")
        GROUP BY COALESCE(NULLIF(r.question_text, ""), CONCAT("第", r.sort_order, "题"))
        ORDER BY answer_count DESC
        LIMIT 8
    ');
    $questionStmt->execute([$hrId, $jobId]);
    $topQuestions = $questionStmt->fetchAll();
    foreach ($topQuestions as $row) {
        $answerCount = max(1, (int)$row['answer_count']);
        $shortRate = round(((int)$row['short_count']) * 100 / $answerCount);
        if ($shortRate >= 45) {
            $weakSignals[] = [
                'title' => '候选人普遍回答偏短',
                'text' => '题目“' . $row['question_text'] . '”有 ' . $shortRate . '% 的回答较短，可能需要改成更具体的场景题或增加追问。'
            ];
        }
    }
}

$nonSensitiveTips = [
    '可补充客户画像，例如“中小企业客户”“区域客户为主”“HR负责人或业务负责人沟通”。',
    '可补充业务场景和协作对象，例如“需要跨部门推进”“需要和销售/交付/运营协作”。',
    '可补充岗位目标和常见客户顾虑，但不要求填写成交金额、客单价、转化率、真实客户名称或内部经营数据。',
    '如确实需要表达规模，请使用范围化描述，例如“长周期跟进”“以转化和续费为主”“轻量化产品销售”。',
];

respond(true, '岗位复盘', [
    'job' => [
        'id' => (int)$job['id'],
        'company_name' => $job['company_name'] ?? null,
        'job_title' => $job['job_title'] ?? null,
        'work_location' => $job['work_location'] ?? null,
        'benefits' => $job['benefits'] ?? null,
    ],
    'completed_count' => $completedCount,
    'sample_enough' => $sampleEnough,
    'minimum_sample' => 10,
    'top_questions' => $topQuestions,
    'weak_signals' => $weakSignals,
    'jd_tips' => $nonSensitiveTips,
]);
