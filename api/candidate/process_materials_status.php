<?php
require_once __DIR__ . '/../bootstrap.php';

/**
 * 轻量状态查询接口 —— 只读数据库，不做任何处理。
 * 用于 processing.html 轮询检查材料整理进度，
 * 避免因 process_materials.php 同步处理超时导致前端报"接口返回格式错误"。
 */

$token = trim((string)($_GET['token'] ?? ($_SESSION['candidate_token'] ?? '')));
$sessionId = (int)($_GET['session_id'] ?? 0);

$candidate = candidate_by_token($pdo, $token);
if (!$candidate) {
    respond(false, '候选人链接无效', [], 401);
}
if ($sessionId <= 0) {
    respond(false, '面试会话无效', [], 422);
}
if (!table_exists($pdo, 'candidate_interview_materials')) {
    // 材料表还不存在，说明处理还没开始，返回 pending
    respond(true, '等待处理', [
        'done' => false,
        'session_id' => $sessionId,
        'merge_status' => 'pending',
        'transcript_status' => 'pending',
        'report_status' => 'pending',
    ]);
}

$sessionStmt = $pdo->prepare('SELECT id FROM candidate_interview_sessions WHERE id=? AND candidate_id=? LIMIT 1');
$sessionStmt->execute([$sessionId, (int)$candidate['id']]);
if (!$sessionStmt->fetch()) {
    respond(false, '当前面试会话不存在', [], 404);
}

$materialStmt = $pdo->prepare('SELECT * FROM candidate_interview_materials WHERE session_id=? LIMIT 1');
$materialStmt->execute([$sessionId]);
$material = $materialStmt->fetch() ?: [];

$done = in_array(($material['merge_status'] ?? ''), ['completed', 'unsupported', 'failed'], true)
    && in_array(($material['transcript_status'] ?? ''), ['completed', 'failed'], true)
    && in_array(($material['report_status'] ?? ''), ['ready', 'failed'], true);

respond(true, $done ? '面试材料已整理完成' : '面试材料正在整理中', [
    'done' => $done,
    'session_id' => $sessionId,
    'merge_status' => $material['merge_status'] ?? 'pending',
    'transcript_status' => $material['transcript_status'] ?? 'pending',
    'report_status' => $material['report_status'] ?? 'pending',
    'next' => 'complete.html?token=' . urlencode($token) . '&session_id=' . $sessionId,
]);
