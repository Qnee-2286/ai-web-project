<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$input = json_input();
$token = trim((string)($input['token'] ?? ($_SESSION['candidate_token'] ?? '')));
$sessionId = (int)($input['session_id'] ?? 0);
$risk = $input['video_risk'] ?? [];

$candidate = candidate_by_token($pdo, $token);
if (!$candidate) {
    respond(false, '候选人登录已失效，请重新进入面试链接', [], 401);
}
if ($sessionId <= 0 || !is_array($risk)) {
    respond(false, '视频风控参数无效', [], 422);
}
if (!table_exists($pdo, 'candidate_interview_sessions')) {
    respond(false, '面试会话表未初始化', [], 500);
}

$stmt = $pdo->prepare('SELECT id, device_check_json FROM candidate_interview_sessions WHERE id=? AND candidate_id=? LIMIT 1');
$stmt->execute([$sessionId, (int)$candidate['id']]);
$session = $stmt->fetch();
if (!$session) {
    respond(false, '面试会话不存在', [], 404);
}

$existing = json_decode((string)($session['device_check_json'] ?? ''), true);
if (!is_array($existing)) {
    $existing = [];
}

$clean = [
    'supported' => !empty($risk['supported']),
    'samples' => max(0, min(500, (int)($risk['samples'] ?? 0))),
    'face_samples' => max(0, min(500, (int)($risk['face_samples'] ?? 0))),
    'no_face_samples' => max(0, min(500, (int)($risk['no_face_samples'] ?? 0))),
    'multi_face_samples' => max(0, min(500, (int)($risk['multi_face_samples'] ?? 0))),
    'camera_interruptions' => max(0, min(100, (int)($risk['camera_interruptions'] ?? 0))),
    'last_checked_at' => date('Y-m-d H:i:s'),
];

$level = 'normal';
if (!$clean['supported']) {
    $level = 'unsupported';
} elseif ($clean['camera_interruptions'] > 0 || $clean['multi_face_samples'] > 0 || $clean['no_face_samples'] >= 3) {
    $level = 'review';
} elseif ($clean['no_face_samples'] > 0) {
    $level = 'minor';
}
$clean['level'] = $level;

$existing['video_risk'] = $clean;
$update = $pdo->prepare('UPDATE candidate_interview_sessions SET device_check_json=?, updated_at=NOW() WHERE id=?');
$update->execute([json_encode($existing, JSON_UNESCAPED_UNICODE), $sessionId]);

respond(true, '视频风控记录已更新', ['video_risk' => $clean]);
