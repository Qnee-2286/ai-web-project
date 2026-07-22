<?php
require_once __DIR__ . '/../bootstrap.php';

$token = trim((string)($_GET['token'] ?? ($_SESSION['candidate_token'] ?? '')));
$candidate = candidate_by_token($pdo, $token);
if (!$candidate) {
    respond(false, '候选人链接无效，请从HR发送的邀请链接重新进入', [], 401);
}

if (!table_exists($pdo, 'candidate_resumes')) {
    respond(true, '简历状态', [
        'candidate_token' => $token,
        'realname_status' => $candidate['realname_status'] ?? '',
        'uploaded' => false,
        'resume' => null,
        'setup_warning' => '简历表未初始化，请先导入 api/upgrade_20260518_resume.sql',
    ]);
}

$stmt = $pdo->prepare("SELECT original_name, file_ext, file_size, created_at FROM candidate_resumes WHERE candidate_id=? AND status='uploaded' ORDER BY id DESC LIMIT 1");
$stmt->execute([(int)$candidate['id']]);
$resume = $stmt->fetch();

respond(true, '简历状态', [
    'candidate_token' => $token,
    'realname_status' => $candidate['realname_status'] ?? '',
    'uploaded' => (bool)$resume,
    'resume' => $resume ?: null,
]);
