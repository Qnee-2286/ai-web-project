<?php
require_once __DIR__ . '/../bootstrap.php';

$token = trim((string)($_GET['token'] ?? ($_SESSION['candidate_token'] ?? '')));
$candidate = candidate_by_token($pdo, $token);
if (!$candidate) {
    respond(false, '候选人链接无效，请从HR发送的邀请链接重新进入', [], 401);
}
if (!empty($candidate['job_id'])) {
    $jobStmt = $pdo->prepare('SELECT status FROM hr_jobs WHERE id=? LIMIT 1');
    $jobStmt->execute([(int)$candidate['job_id']]);
    if ((string)($jobStmt->fetchColumn() ?: '') !== 'active') {
        respond(false, '该初面链接已失效，请联系HR', ['link_expired' => true], 410);
    }
}

$resume = null;
if (table_exists($pdo, 'candidate_resumes')) {
    $stmt = $pdo->prepare('SELECT id, original_name, created_at FROM candidate_resumes WHERE candidate_id=? AND status="uploaded" ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int)$candidate['id']]);
    $resume = $stmt->fetch() ?: null;
}

$latestSessionId = null;
$latestSessionStatus = null;
$completedSessionId = null;
$completedInterviewNo = null;
if (table_exists($pdo, 'candidate_interview_sessions')) {
    $stmt = $pdo->prepare('SELECT id, status FROM candidate_interview_sessions WHERE candidate_id=? AND status IN ("device_checked","interviewing") ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int)$candidate['id']]);
    $activeSession = $stmt->fetch() ?: null;

    if ($activeSession) {
        $latestSessionId = $activeSession['id'] ?? null;
        $latestSessionStatus = $activeSession['status'] ?? null;
    } else {
        $interviewNoSelect = column_exists($pdo, 'candidate_interview_sessions', 'interview_no') ? 'interview_no' : 'NULL AS interview_no';
        $stmt = $pdo->prepare('SELECT id, status, ' . $interviewNoSelect . ' FROM candidate_interview_sessions WHERE candidate_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([(int)$candidate['id']]);
        $latestSession = $stmt->fetch() ?: null;
        $latestSessionId = $latestSession['id'] ?? null;
        $latestSessionStatus = $latestSession['status'] ?? null;
        if (($latestSession['status'] ?? '') === 'completed') {
            $completedSessionId = $latestSession['id'] ?? null;
            $completedInterviewNo = $latestSession['interview_no'] ?? null;
        }
    }
}

respond(true, '候选人状态', [
    'candidate_token' => $token,
    'phone_verified' => !empty($candidate['phone_verified_at']),
    'agreement_accepted' => !empty($candidate['agreement_accepted_at']),
    'job_record_confirmed' => !empty($candidate['job_record_confirmed_at']),
    'realname_status' => $candidate['realname_status'],
    'candidate_status' => $candidate['candidate_status'] ?? 'not_received',
    'latest_session_id' => $latestSessionId ? (int)$latestSessionId : null,
    'latest_session_status' => $latestSessionStatus,
    'completed_session_id' => $completedSessionId ? (int)$completedSessionId : null,
    'completed_interview_no' => $completedInterviewNo,
    'resume_uploaded' => (bool)$resume,
    'resume' => $resume,
]);
