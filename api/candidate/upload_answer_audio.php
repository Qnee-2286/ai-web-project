<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$token = trim((string)($_POST['token'] ?? ($_SESSION['candidate_token'] ?? '')));
$sessionId = (int)($_POST['session_id'] ?? 0);
$sortOrder = (int)($_POST['sort_order'] ?? 0);
$questionId = isset($_POST['question_id']) && $_POST['question_id'] !== '' ? (int)$_POST['question_id'] : null;
$questionText = trim((string)($_POST['question_text'] ?? ''));
$questionType = trim((string)($_POST['question_type'] ?? ''));
$audioSeconds = isset($_POST['audio_seconds']) ? max(1, (int)$_POST['audio_seconds']) : null;

$candidate = candidate_by_token($pdo, $token);
if (!$candidate) {
    respond(false, '候选人链接无效，请从HR发送的邀请链接重新进入', [], 401);
}
if ($sessionId <= 0 || $sortOrder <= 0 || $questionText === '') {
    respond(false, '录音提交参数不完整', [], 422);
}
if (!table_exists($pdo, 'candidate_interview_recordings')) {
    respond(false, '录音数据表未初始化，请先导入 api/upgrade_20260526_interview_recordings.sql', [], 500);
}

ensure_interview_recordings_schema($pdo);
$sessionStmt = $pdo->prepare('SELECT id FROM candidate_interview_sessions WHERE id=? AND candidate_id=? AND status IN ("interviewing","device_checked") LIMIT 1');
$sessionStmt->execute([$sessionId, (int)$candidate['id']]);
if (!$sessionStmt->fetch()) {
    respond(false, '当前面试会话无效或已结束', [], 404);
}

$file = $_FILES['audio_file'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    respond(false, '没有收到录音文件，请重新回答本题', [], 422);
}
$size = (int)($file['size'] ?? 0);
if ($size <= 0 || $size > 30 * 1024 * 1024) {
    respond(false, '录音文件大小异常，请重新录制', [], 422);
}

$mime = trim((string)($file['type'] ?? ''));
$allowed = [
    'audio/webm' => 'webm',
    'audio/mp4' => 'm4a',
    'audio/mpeg' => 'mp3',
    'audio/ogg' => 'ogg',
    'audio/wav' => 'wav',
    'video/mp4' => 'm4a',
    'application/octet-stream' => 'webm',
];
$baseMime = strtolower(trim(explode(';', $mime)[0]));
if (!isset($allowed[$baseMime])) {
    respond(false, '当前录音格式暂不支持，请使用手机浏览器重新录制', ['mime_type' => $mime], 422);
}

$oss = $config['oss'] ?? [];
if (!oss_is_configured($oss)) {
    respond(false, '录音存储尚未接通，请联系平台工作人员', [], 503);
}

$prefix = trim((string)($oss['prefix'] ?? 'interview-audio/'), '/') . '/';
$ext = $allowed[$baseMime];
$objectKey = $prefix
    . date('Y/m/d')
    . '/session-' . $sessionId
    . '/question-' . $sortOrder . '-' . bin2hex(random_bytes(6)) . '.' . $ext;

$oldStmt = $pdo->prepare('SELECT audio_object_key FROM candidate_interview_recordings WHERE session_id=? AND sort_order=? LIMIT 1');
$oldStmt->execute([$sessionId, $sortOrder]);
$oldKey = (string)($oldStmt->fetchColumn() ?: '');

$upload = oss_put_file($oss, $objectKey, (string)$file['tmp_name'], $baseMime);
$stmt = $pdo->prepare('INSERT INTO candidate_interview_recordings(session_id, candidate_id, question_id, question_text, question_type, sort_order, audio_object_key, audio_mime_type, audio_size, audio_seconds, oss_etag, transcript_status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,"pending",NOW(),NOW()) ON DUPLICATE KEY UPDATE question_id=VALUES(question_id), question_text=VALUES(question_text), question_type=VALUES(question_type), audio_object_key=VALUES(audio_object_key), audio_mime_type=VALUES(audio_mime_type), audio_size=VALUES(audio_size), audio_seconds=VALUES(audio_seconds), oss_etag=VALUES(oss_etag), transcript_status="pending", transcript_text=NULL, updated_at=NOW()');
$stmt->execute([
    $sessionId,
    (int)$candidate['id'],
    $questionId,
    $questionText,
    mb_substr($questionType, 0, 40, 'UTF-8'),
    $sortOrder,
    $objectKey,
    $baseMime,
    $size,
    $audioSeconds,
    $upload['etag'] ?? '',
]);

if ($oldKey !== '' && $oldKey !== $objectKey) {
    oss_delete_object($oss, $oldKey);
}

respond(true, '本题语音回答已保存', [
    'session_id' => $sessionId,
    'sort_order' => $sortOrder,
    'audio_seconds' => $audioSeconds,
    'transcript_status' => 'pending',
]);
