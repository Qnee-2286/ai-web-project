<?php
require_once __DIR__ . '/../bootstrap.php';

$accountId = (int)($_SESSION['candidate_account_id'] ?? 0);
if ($accountId <= 0) {
    respond(false, '请先登录', [], 401);
}

try {
    $hasTable = table_exists($pdo, 'candidate_accounts');
} catch (Throwable $e) {
    respond(false, '数据库检查失败：' . $e->getMessage(), [], 500);
}
if (!$hasTable) {
    respond(false, '候选人账户表不存在', [], 500);
}

try {
    $stmt = $pdo->prepare('SELECT * FROM candidate_accounts WHERE id=? LIMIT 1');
    $stmt->execute([$accountId]);
    $account = $stmt->fetch();
} catch (Throwable $e) {
    respond(false, '查询账户失败：' . $e->getMessage(), [], 500);
}
if (!$account) {
    respond(false, '账户不存在', [], 404);
}

/* 实名信息（非关键） */
$realName   = null;
$idCardMask = null;
$avatarUrl  = null;

try {
    if (table_exists($pdo, 'authentication_records')) {
        $stmt = $pdo->prepare(
            'SELECT id_card_mask, avatar_url
               FROM authentication_records
              WHERE subject_type="candidate" AND subject_id=? AND status="success"
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$accountId]);
        $auth = $stmt->fetch();
        if ($auth) {
            $idCardMask = $auth['id_card_mask'] ?? null;
            $avatarUrl  = $auth['avatar_url'] ?? null;
        }
    }
} catch (Throwable $e) { /* 非关键，静默 */ }

try {
    if (table_exists($pdo, 'candidates')
        && column_exists($pdo, 'candidates', 'real_name')
        && column_exists($pdo, 'candidates', 'candidate_account_id')) {
        $stmt = $pdo->prepare(
            'SELECT real_name FROM candidates
              WHERE candidate_account_id=? AND real_name IS NOT NULL AND real_name <> ""
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$accountId]);
        $rn = $stmt->fetchColumn();
        if ($rn) $realName = $rn;
    }
} catch (Throwable $e) { /* 非关键，静默 */ }

/* 面试记录（非关键） */
$interviewRecords = [];
try {
    $canJoin = table_exists($pdo, 'candidates')
            && table_exists($pdo, 'candidate_interview_sessions')
            && column_exists($pdo, 'candidates', 'candidate_account_id')
            && column_exists($pdo, 'candidates', 'job_id')
            && table_exists($pdo, 'hr_jobs')
            && table_exists($pdo, 'candidate_interview_reports');
    if ($canJoin) {
        $jobRecordExtra = '';
        if (column_exists($pdo, 'hr_jobs', 'work_location')) {
            $jobRecordExtra .= ', j.work_location';
        }
        if (column_exists($pdo, 'hr_jobs', 'salary_unit')) {
            $jobRecordExtra .= ', j.salary_unit';
        }
        $stmt = $pdo->prepare(
            'SELECT c.id AS candidate_id, c.invite_token,
                    j.company_name, j.job_title, j.salary_min_k, j.salary_max_k' . $jobRecordExtra . ',
                    s.status AS session_status, s.started_at, s.completed_at,
                    r.recommendation
               FROM candidates c
               LEFT JOIN hr_jobs j ON j.id = c.job_id
               LEFT JOIN candidate_interview_sessions s ON s.candidate_id = c.id
               LEFT JOIN candidate_interview_reports r ON r.session_id = s.id
              WHERE c.candidate_account_id = ?
              ORDER BY s.started_at DESC'
        );
        $stmt->execute([$accountId]);
        $interviewRecords = $stmt->fetchAll();
        foreach ($interviewRecords as &$record) {
            $record['work_location'] = $record['work_location'] ?? '';
            $record['salary_unit'] = $record['salary_unit'] ?? 'K/月';
        }
        unset($record);
    }
} catch (Throwable $e) { /* 非关键，静默 */ }

/* 统计 */
$totalInterviews = count($interviewRecords);
$daysKept = 15;

respond(true, '账户信息', [
    'account_id'       => (int)$account['id'],
    'phone'            => $account['phone'],
    'realname_status'  => $account['realname_status'] ?? 'pending',
    'real_name'        => $realName,
    'id_card_mask'     => $idCardMask,
    'avatar_url'       => $avatarUrl,
    'interview_count'  => $totalInterviews,
    'records_keep_days'=> $daysKept,
    'interview_records'=> $interviewRecords,
]);
