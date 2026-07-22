<?php
require_once __DIR__ . '/../bootstrap.php';

$token = trim((string)($_GET['token'] ?? ($_SESSION['candidate_token'] ?? '')));
$candidate = candidate_by_token($pdo, $token);
if (!$candidate) {
    respond(false, '候选人链接无效，请从HR发送的邀请链接重新进入', [], 401);
}
if (($candidate['realname_status'] ?? '') !== 'verified') {
    respond(false, '请先完成实名认证', ['next' => 'auth.html?token=' . urlencode($token)], 422);
}

if (!table_exists($pdo, 'candidate_resumes') || !table_exists($pdo, 'candidate_interview_sessions')) {
    respond(false, '候选人面试数据表未初始化，请先导入最新升级脚本', [], 500);
}

$resumeStmt = $pdo->prepare('SELECT id, original_name, created_at FROM candidate_resumes WHERE candidate_id=? AND status="uploaded" ORDER BY id DESC LIMIT 1');
$resumeStmt->execute([(int)$candidate['id']]);
$resume = $resumeStmt->fetch();
if (!$resume) {
    respond(false, '请先上传简历，再进入面试', ['next' => 'resume.html?token=' . urlencode($token)], 422);
}

$jobSerialSelect = column_exists($pdo, 'hr_jobs', 'job_serial') ? 'job_serial,' : 'NULL AS job_serial,';
$jobExtraSelect = '';
if (column_exists($pdo, 'hr_jobs', 'work_location')) {
    $jobExtraSelect .= ', work_location';
}
if (column_exists($pdo, 'hr_jobs', 'salary_unit')) {
    $jobExtraSelect .= ', salary_unit';
}
$jobStmt = $pdo->prepare('SELECT id, hr_id, ' . $jobSerialSelect . ' company_name, job_title, salary_min_k, salary_max_k' . $jobExtraSelect . ', benefits, company_intro, responsibilities, requirements FROM hr_jobs WHERE id=? AND status="active" LIMIT 1');
$jobStmt->execute([(int)$candidate['job_id']]);
$job = $jobStmt->fetch();
if (!$job) {
    respond(false, '岗位信息不存在或已关闭', [], 404);
}
$job['work_location'] = $job['work_location'] ?? '';
$job['salary_unit'] = $job['salary_unit'] ?? 'K/月';

$sessionStmt = $pdo->prepare('SELECT * FROM candidate_interview_sessions WHERE candidate_id=? AND status IN ("device_checked","interviewing") ORDER BY id DESC LIMIT 1');
$sessionStmt->execute([(int)$candidate['id']]);
$session = $sessionStmt->fetch();
if (!$session) {
    respond(false, '请先完成设备检查', ['next' => 'device-check.html?token=' . urlencode($token)], 422);
}

$questionStmt = $pdo->prepare('SELECT id, question_text, question_type, difficulty, purpose, sort_order, is_required, source FROM ai_interview_questions WHERE job_id=? ORDER BY sort_order ASC, id ASC LIMIT 13');
$questionStmt->execute([(int)$candidate['job_id']]);
$questions = $questionStmt->fetchAll();

if (!$questions) {
    $questions = [
        ['id' => null, 'question_text' => '请用1到2分钟介绍你的过往工作经历，以及你为什么考虑这个岗位。', 'question_type' => '基础必问题', 'difficulty' => '基础', 'purpose' => '了解候选人的基础经历和求职动机', 'sort_order' => 1, 'is_required' => 1, 'source' => 'system'],
        ['id' => null, 'question_text' => '请结合上一段经历，说一次你独立推进工作并拿到结果的过程。', 'question_type' => '基础必问题', 'difficulty' => '基础', 'purpose' => '了解真实工作过程和个人贡献', 'sort_order' => 2, 'is_required' => 1, 'source' => 'system'],
        ['id' => null, 'question_text' => '你对这个岗位的主要职责是怎么理解的？哪些部分和你的经验最匹配？', 'question_type' => '岗位匹配题', 'difficulty' => '中等', 'purpose' => '确认岗位理解和经验匹配度', 'sort_order' => 3, 'is_required' => 1, 'source' => 'system'],
        ['id' => null, 'question_text' => '如果进入这个岗位，你预计自己前两周会先做哪些准备？', 'question_type' => '岗位匹配题', 'difficulty' => '中等', 'purpose' => '观察候选人的落地思路', 'sort_order' => 4, 'is_required' => 1, 'source' => 'system'],
        ['id' => null, 'question_text' => '请补充说明简历里最能证明你适合这个岗位的一段经历。', 'question_type' => '简历追问题', 'difficulty' => '中等', 'purpose' => '给表达慢热的候选人补充真实经历的机会', 'sort_order' => 5, 'is_required' => 1, 'source' => 'system'],
    ];
}

$answeredSortOrders = [];
if (table_exists($pdo, 'candidate_interview_recordings')) {
    $answeredStmt = $pdo->prepare('SELECT sort_order FROM candidate_interview_recordings WHERE session_id=? AND candidate_id=? ORDER BY sort_order ASC');
    $answeredStmt->execute([(int)$session['id'], (int)$candidate['id']]);
    $answeredSortOrders = array_values(array_map('intval', $answeredStmt->fetchAll(PDO::FETCH_COLUMN)));
}

$interviewNo = $session['interview_no'] ?? null;
$pdo->beginTransaction();
try {
    if (column_exists($pdo, 'candidate_interview_sessions', 'interview_no') && empty($interviewNo)) {
        $jobSerial = (int)($job['job_serial'] ?? 0);
        if ($jobSerial <= 0) {
            $jobSerial = job_serial_for_interview($pdo, (int)$job['hr_id'], (int)$job['id']);
        }
        $interviewNo = next_interview_no($pdo, $jobSerial);
        $updateSession = $pdo->prepare('UPDATE candidate_interview_sessions SET interview_no=?, status="interviewing", started_at=IFNULL(started_at,NOW()), updated_at=NOW() WHERE id=?');
        $updateSession->execute([$interviewNo, (int)$session['id']]);
    } else {
        $updateSession = $pdo->prepare('UPDATE candidate_interview_sessions SET status="interviewing", started_at=IFNULL(started_at,NOW()), updated_at=NOW() WHERE id=?');
        $updateSession->execute([(int)$session['id']]);
    }

    $updateCandidate = $pdo->prepare('UPDATE candidates SET candidate_status="interviewing", updated_at=NOW() WHERE id=?');
    $updateCandidate->execute([(int)$candidate['id']]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    respond(false, '面试启动失败，请稍后重试', ['error' => $e->getMessage()], 500);
}

respond(true, '面试已开始', [
    'candidate_token' => $token,
    'session_id' => (int)$session['id'],
    'interview_no' => $interviewNo,
    'job' => $job,
    'resume' => $resume,
    'questions' => $questions,
    'answered_sort_orders' => $answeredSortOrders,
]);
