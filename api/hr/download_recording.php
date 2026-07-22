<?php
require_once __DIR__ . '/../bootstrap.php';

$hr = require_hr($pdo);
$hrId = (int)$hr['id'];
$recordingId = (int)($_GET['recording_id'] ?? 0);
if ($recordingId <= 0) {
    respond(false, '缺少录音参数', [], 422);
}
if (!table_exists($pdo, 'candidate_interview_recordings')) {
    respond(false, '录音表未初始化', [], 500);
}

$stmt = $pdo->prepare('
    SELECT r.audio_object_key, r.question_text, r.sort_order
    FROM candidate_interview_recordings r
    INNER JOIN candidates c ON c.id = r.candidate_id
    WHERE r.id=? AND c.hr_id=?
    LIMIT 1
');
$stmt->execute([$recordingId, $hrId]);
$recording = $stmt->fetch();
if (!$recording) {
    respond(false, '录音不存在或无权访问', [], 404);
}

$objectKey = trim((string)($recording['audio_object_key'] ?? ''));
if ($objectKey === '') {
    respond(false, '该录音文件尚未保存完成', [], 404);
}

$ossConfig = $config['oss'] ?? [];
if (!oss_is_configured($ossConfig)) {
    respond(false, 'OSS存储服务未配置', [], 500);
}

try {
    $url = oss_presigned_url($ossConfig, $objectKey, 1800);
    header('Location: ' . $url, true, 302);
    exit;
} catch (Throwable $e) {
    respond(false, '生成录音下载链接失败', ['error' => $e->getMessage()], 500);
}
