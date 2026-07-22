<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

function short_text(string $text, int $length): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $length, 'UTF-8');
    }
    return substr($text, 0, $length);
}

if (!table_exists($pdo, 'candidate_resumes')) {
    respond(false, '简历表未初始化，请先在宝塔数据库导入 api/upgrade_20260518_resume.sql', [], 500);
}

$token = trim((string)($_POST['token'] ?? ($_SESSION['candidate_token'] ?? '')));
$candidate = candidate_by_token($pdo, $token);
if (!$candidate) {
    respond(false, '候选人链接无效，请从HR发送的邀请链接重新进入', [], 401);
}
if (empty($candidate['phone_verified_at']) || empty($candidate['agreement_accepted_at'])) {
    respond(false, '请先完成手机号验证和授权确认', [], 422);
}
if (($candidate['realname_status'] ?? '') !== 'verified') {
    respond(false, '请先完成实名认证，再上传简历', [], 422);
}
if (empty($_FILES['resume']) || !is_array($_FILES['resume'])) {
    respond(false, '请选择要上传的简历文件', [], 422);
}

$file = $_FILES['resume'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $errors = [
        UPLOAD_ERR_INI_SIZE => '简历文件超过服务器上传限制，请换小一点的文件或调整服务器上传限制',
        UPLOAD_ERR_FORM_SIZE => '简历文件超过页面允许大小',
        UPLOAD_ERR_PARTIAL => '简历只上传了一部分，请重新上传',
        UPLOAD_ERR_NO_FILE => '请选择要上传的简历文件',
        UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时上传目录',
        UPLOAD_ERR_CANT_WRITE => '服务器无法写入上传文件',
        UPLOAD_ERR_EXTENSION => '服务器扩展拦截了文件上传',
    ];
    respond(false, $errors[$file['error']] ?? '简历上传失败，请重新选择文件', ['upload_error' => $file['error'] ?? null], 422);
}

$maxSize = 20 * 1024 * 1024;
$size = (int)($file['size'] ?? 0);
if ($size <= 0 || $size > $maxSize) {
    respond(false, '简历文件大小需在20MB以内', [], 422);
}

$originalName = (string)($file['name'] ?? 'resume');
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$allowed = ['pdf', 'doc', 'docx'];
if (!in_array($ext, $allowed, true)) {
    respond(false, '仅支持PDF、DOC、DOCX格式简历', [], 422);
}

$mime = '';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $mime = (string)finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
    }
}

$root = dirname(__DIR__, 2);
$baseDir = $root . '/private_uploads';
$resumeBase = $baseDir . '/resumes';
$month = date('Ym');
$dir = $resumeBase . '/' . $month;
foreach ([$baseDir, $resumeBase, $dir] as $path) {
    if (!is_dir($path) && !mkdir($path, 0750, true)) {
        respond(false, '服务器存储目录创建失败，请检查 private_uploads 目录权限', ['path' => $path], 500);
    }
}
if (!is_writable($dir)) {
    respond(false, '服务器存储目录不可写，请检查 private_uploads 目录权限', ['path' => $dir], 500);
}

$storedName = 'cand_' . (int)$candidate['id'] . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$target = $dir . '/' . $storedName;
if (!move_uploaded_file($file['tmp_name'], $target)) {
    respond(false, '简历保存失败，请稍后再试', [], 500);
}
@chmod($target, 0640);

$relativePath = 'private_uploads/resumes/' . $month . '/' . $storedName;
try {
    $pdo->beginTransaction();

    $old = $pdo->prepare("UPDATE candidate_resumes SET status='replaced', updated_at=NOW() WHERE candidate_id=? AND status='uploaded'");
    $old->execute([(int)$candidate['id']]);

    $stmt = $pdo->prepare("INSERT INTO candidate_resumes(candidate_id, original_name, stored_name, storage_path, file_ext, mime_type, file_size, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,'uploaded',NOW(),NOW())");
    $stmt->execute([(int)$candidate['id'], short_text($originalName, 255), $storedName, $relativePath, $ext, $mime, $size]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @unlink($target);
    respond(false, '简历记录保存失败：' . $e->getMessage(), [], 500);
}

try {
    $update = $pdo->prepare("UPDATE candidates SET candidate_status=IF(candidate_status='not_received','pending_interview',candidate_status), updated_at=NOW() WHERE id=?");
    $update->execute([(int)$candidate['id']]);
} catch (Throwable $e) {
    // Older databases may not have candidate_status yet. Resume upload should still succeed.
}

respond(true, '简历上传成功', [
    'candidate_token' => $token,
    'file_name' => $originalName,
    'file_size' => $size,
    'next' => 'job-confirm.html?token=' . urlencode($token),
]);
