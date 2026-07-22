<?php
require_once __DIR__ . '/../bootstrap.php';

$hr = require_hr($pdo);
$recordingId = (int)($_GET['recording_id'] ?? 0);
if ($recordingId <= 0 || !table_exists($pdo, 'candidate_interview_recordings')) {
    respond(false, '录音参数无效', [], 422);
}

$isPlatformAdmin = !empty($hr['is_platform_admin']);
$permissionSql = $isPlatformAdmin ? '' : ' AND ses.hr_id=?';
$params = $isPlatformAdmin ? [$recordingId] : [$recordingId, (int)$hr['id']];
$stmt = $pdo->prepare("
    SELECT rec.audio_object_key
    FROM candidate_interview_recordings rec
    INNER JOIN candidate_interview_sessions ses ON ses.id=rec.session_id
    WHERE rec.id=?{$permissionSql}
    LIMIT 1
");
$stmt->execute($params);
$objectKey = (string)($stmt->fetchColumn() ?: '');
if ($objectKey === '') {
    respond(false, '录音不存在或无权查看', [], 404);
}

try {
    $url = oss_signed_get_url($config['oss'] ?? [], $objectKey, 600);
} catch (Throwable $e) {
    respond(false, '录音暂时无法读取，请稍后再试', [], 502);
}
header('Location: ' . $url, true, 302);
exit;
