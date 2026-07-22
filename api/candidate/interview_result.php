<?php
require_once __DIR__ . '/../bootstrap.php';

$token = trim((string)($_GET['token'] ?? ($_SESSION['candidate_token'] ?? '')));
$sessionId = (int)($_GET['session_id'] ?? 0);
$candidate = candidate_by_token($pdo, $token);
if (!$candidate) {
    respond(false, '候选人链接无效，请从HR发送的邀请链接重新进入', [], 401);
}
if ($sessionId <= 0) {
    $stmt = $pdo->prepare('SELECT id FROM candidate_interview_sessions WHERE candidate_id=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int)$candidate['id']]);
    $sessionId = (int)($stmt->fetchColumn() ?: 0);
}
if ($sessionId <= 0) {
    respond(false, '暂无面试记录', [], 404);
}

$answers = [];
if (table_exists($pdo, 'candidate_interview_answers')) {
    $answersStmt = $pdo->prepare('SELECT question_text, answer_text, sort_order FROM candidate_interview_answers WHERE session_id=? AND candidate_id=? ORDER BY sort_order ASC');
    $answersStmt->execute([$sessionId, (int)$candidate['id']]);
    $answers = $answersStmt->fetchAll();
}

$recordings = [];
if (table_exists($pdo, 'candidate_interview_recordings')) {
    $hasTranscript = column_exists($pdo, 'candidate_interview_recordings', 'transcript_text');
    $transcriptCol = $hasTranscript ? ', transcript_text' : ', NULL AS transcript_text';
    $recordingStmt = $pdo->prepare('SELECT question_text, sort_order, audio_seconds, transcript_status' . $transcriptCol . ' FROM candidate_interview_recordings WHERE session_id=? AND candidate_id=? ORDER BY sort_order ASC');
    $recordingStmt->execute([$sessionId, (int)$candidate['id']]);
    $recordings = $recordingStmt->fetchAll();
}

$materials = [
    'merge_status' => 'pending',
    'transcript_status' => 'pending',
    'report_status' => 'pending',
    'merged_audio_download_url' => '',
    'audio_message' => '面试材料正在整理中，请稍后刷新。',
];

if (table_exists($pdo, 'candidate_interview_materials')) {
    $matStmt = $pdo->prepare('SELECT * FROM candidate_interview_materials WHERE session_id=? AND candidate_id=? LIMIT 1');
    $matStmt->execute([$sessionId, (int)$candidate['id']]);
    $mat = $matStmt->fetch() ?: [];
    $materials['merge_status'] = $mat['merge_status'] ?? 'pending';
    $materials['transcript_status'] = $mat['transcript_status'] ?? 'pending';
    $materials['report_status'] = $mat['report_status'] ?? 'pending';
    $objectKey = trim((string)($mat['merged_audio_object_key'] ?? ''));
    if ($objectKey !== '' && oss_is_configured($config['oss'] ?? [])) {
        try {
            $materials['merged_audio_download_url'] = oss_presigned_url($config['oss'], $objectKey, 86400);
        } catch (Throwable $e) {
            $materials['merged_audio_download_url'] = '';
        }
    }
    if ($materials['merge_status'] === 'completed') {
        $materials['audio_message'] = '本次面试回答录音已整理为一个音频文件，可在24小时内下载。';
    } elseif ($materials['merge_status'] === 'unsupported') {
        $materials['audio_message'] = '服务器暂未安装录音合并服务，录音已按题保存，HR端仍可复核。';
    } elseif ($materials['merge_status'] === 'failed') {
        $materials['audio_message'] = '整段录音暂未生成成功，录音已按题保存，HR端仍可复核。';
    }
}

respond(true, '面试完成记录', [
    'candidate_token' => $token,
    'session_id' => $sessionId,
    'interview_type' => count($recordings) > 0 ? 'audio' : 'text',
    'answers' => $answers,
    'recordings' => array_map(function ($r) {
        return [
            'question_text' => $r['question_text'] ?? '',
            'sort_order' => (int)($r['sort_order'] ?? 0),
            'audio_seconds' => (int)($r['audio_seconds'] ?? 0),
            'transcript_status' => $r['transcript_status'] ?? 'pending',
            'transcript_text' => $r['transcript_text'] ?? '',
        ];
    }, $recordings),
    'recording_count' => count($recordings),
    'materials' => $materials,
    'processing' => !in_array($materials['merge_status'], ['completed', 'unsupported', 'failed'], true),
    'audio_message' => $materials['audio_message'],
]);
