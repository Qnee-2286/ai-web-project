<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$input = json_input();
$token = trim((string)($input['token'] ?? ($_SESSION['candidate_token'] ?? '')));
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
if (($candidate['realname_status'] ?? '') !== 'verified') {
    respond(false, '请先完成实名认证', [], 422);
}

$camera = !empty($input['camera']);
$microphone = !empty($input['microphone']);
$network = trim((string)($input['network'] ?? 'unknown'));
if (!$camera || !$microphone) {
    respond(false, '请允许摄像头和麦克风权限后再进入面试', [], 422);
}

if (!table_exists($pdo, 'candidate_interview_sessions')) {
    respond(false, '候选人面试数据表未初始化，请先导入 upgrade_20260521_candidate_interview.sql', [], 500);
}

$payload = [
    'camera' => $camera,
    'microphone' => $microphone,
    'network' => $network,
    'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    'checked_at' => date('Y-m-d H:i:s'),
];

$stmt = $pdo->prepare('SELECT id FROM candidate_interview_sessions WHERE candidate_id=? AND status IN ("device_checked","interviewing") ORDER BY id DESC LIMIT 1');
$stmt->execute([(int)$candidate['id']]);
$sessionId = (int)($stmt->fetchColumn() ?: 0);

if ($sessionId > 0) {
    $update = $pdo->prepare('UPDATE candidate_interview_sessions SET device_check_json=?, status="device_checked", updated_at=NOW() WHERE id=?');
    $update->execute([json_encode($payload, JSON_UNESCAPED_UNICODE), $sessionId]);
} else {
    $insert = $pdo->prepare('INSERT INTO candidate_interview_sessions(candidate_id, hr_id, job_id, invite_token, status, device_check_json, created_at, updated_at) VALUES(?,?,?,?, "device_checked", ?, NOW(), NOW())');
    $insert->execute([(int)$candidate['id'], (int)$candidate['hr_id'], (int)$candidate['job_id'], $token, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    $sessionId = (int)$pdo->lastInsertId();
}

$candidateUpdate = $pdo->prepare('UPDATE candidates SET candidate_status="pending_interview", updated_at=NOW() WHERE id=?');
$candidateUpdate->execute([(int)$candidate['id']]);

respond(true, '设备检查通过', [
    'candidate_token' => $token,
    'session_id' => $sessionId,
    'next' => 'interview.html?token=' . urlencode($token),
]);
