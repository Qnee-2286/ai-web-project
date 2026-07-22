<?php
/**
 * 简历文件下载 / 内联预览
 *
 * 关键设计：
 * - ob_start() 必须在 require bootstrap 之前调用，以捕获任何 BOM / warning 输出
 * - 发送文件前彻底清空所有输出缓冲，确保二进制流零污染
 * - 禁用错误显示，防止 PHP notice/warning 混入文件内容
 */

// ── 第 1 步：立即开启输出缓冲，捕获任何后续 include 的 BOM / warning ──
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../bootstrap.php';

// ── 第 2 步：业务逻辑（与之前一致） ──
$hr = require_hr($pdo);
$hrId = (int)$hr['id'];
$candidateId = (int)($_GET['candidate_id'] ?? 0);
if ($candidateId <= 0) {
    respond(false, '缺少候选人参数', [], 422);
}
if (!table_exists($pdo, 'candidate_resumes')) {
    respond(false, '简历表未初始化', [], 500);
}

$stmt = $pdo->prepare("
    SELECT r.original_name, r.storage_path, r.mime_type, r.file_size
    FROM candidate_resumes r
    INNER JOIN candidates c ON c.id = r.candidate_id
    WHERE r.candidate_id=? AND c.hr_id=? AND r.status='uploaded'
    ORDER BY r.id DESC
    LIMIT 1
");
$stmt->execute([$candidateId, $hrId]);
$resume = $stmt->fetch();
if (!$resume) {
    respond(false, '候选人暂未上传简历', [], 404);
}

$root = dirname(__DIR__, 2);
$base = $root . '/private_uploads';
if (!is_dir($base) && !mkdir($base, 0750, true)) {
    respond(false, '服务器存储目录不存在', [], 500);
}
$base = realpath($base) ?: $base;
$storagePath = ltrim((string)$resume['storage_path'], '/\\');
$filePath = realpath($root . '/' . $storagePath);
if (!$filePath || strpos($filePath, $base) !== 0 || !is_file($filePath)) {
    $directPath = $root . '/' . $storagePath;
    if (is_file($directPath)) {
        $filePath = $directPath;
    } else {
        respond(false, '简历文件不存在或已被清理', [], 404);
    }
}

$name = (string)$resume['original_name'];
$mime = $resume['mime_type'] ?: 'application/octet-stream';
$download = (string)($_GET['download'] ?? '') === '1';
$actualSize = filesize($filePath);
if ($actualSize <= 0) {
    respond(false, '简历文件为空，请让候选人重新上传', [], 422);
}
$fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
if (!$fallbackName || $fallbackName === '_') {
    $fallbackName = 'resume';
}

// ── 第 3 步：彻底清空所有输出缓冲（关键！解决 BOM / warning 污染） ──
while (ob_get_level() > 0) {
    ob_end_clean();
}
// 再次开启一个干净缓冲，防止后续 header 报错注入输出
ob_start();

// ── 第 4 步：发送 HTTP 头 ──
set_time_limit(0);
header('Content-Type: ' . $mime);
header('Content-Length: ' . $actualSize);
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: none');
// 告诉反向代理 / Nginx 不要做 gzip 压缩，原样传输二进制流
header('Content-Encoding: identity');
header('X-Accel-Buffering: no');
$disposition = $download ? 'attachment' : 'inline';
header("Content-Disposition: {$disposition}; filename=\"{$fallbackName}\"; filename*=UTF-8''" . rawurlencode($name));

// ── 第 5 步：发送文件内容 ──
// 清空刚开的干净缓冲（里面只可能有 header 报错，丢弃）
while (ob_get_level() > 0) {
    ob_end_clean();
}

$fp = @fopen($filePath, 'rb');
if ($fp) {
    fpassthru($fp);
    fclose($fp);
} else {
    readfile($filePath);
}
exit;
