<?php
require_once __DIR__ . '/../bootstrap.php';

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
    SELECT r.original_name, r.stored_name, r.storage_path, r.file_ext, r.mime_type, r.file_size, r.created_at
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
// 确保 private_uploads 目录存在
if (!is_dir($base) && !mkdir($base, 0750, true)) {
    respond(false, '服务器存储目录不存在，请联系平台工作人员检查 private_uploads 目录', [], 500);
}
$base = realpath($base);
$filePath = realpath($root . '/' . ltrim((string)$resume['storage_path'], '/\\'));
if (!$base || !$filePath || strpos($filePath, $base) !== 0 || !is_file($filePath)) {
    // 尝试用存储路径直接拼接（realpath 可能因目录不存在返回 false）
    $directPath = $root . '/' . ltrim((string)$resume['storage_path'], '/\\');
    if (is_file($directPath)) {
        $filePath = $directPath;
    } else {
        respond(false, '简历文件不存在或已被清理，请让候选人重新上传', [], 404);
    }
}

$ext = strtolower((string)($resume['file_ext'] ?? pathinfo((string)$resume['original_name'], PATHINFO_EXTENSION)));
$actualSize = filesize($filePath);
$downloadUrl = '../api/hr/download_resume.php?candidate_id=' . rawurlencode((string)$candidateId);
$preview = [
    'original_name' => $resume['original_name'],
    'file_ext' => $ext,
    'mime_type' => $resume['mime_type'],
    'file_size' => (int)$resume['file_size'],
    'actual_size' => $actualSize,
    'size_matches' => $actualSize === (int)$resume['file_size'],
    'created_at' => $resume['created_at'],
    'download_url' => $downloadUrl . '&download=1',
    'inline_url' => $downloadUrl,
    'preview_type' => 'unsupported',
    'text' => '',
    'message' => '',
];

if ($actualSize <= 0) {
    $preview['message'] = '文件为空，请让候选人重新上传。';
    respond(true, '简历预览', ['preview' => $preview]);
}

if ($ext === 'pdf') {
    $preview['preview_type'] = 'pdf';
    $preview['message'] = 'PDF 可直接在页面中预览。';
    respond(true, '简历预览', ['preview' => $preview]);
}

if ($ext === 'docx') {
    if (!class_exists('ZipArchive')) {
        $preview['message'] = '当前服务器未启用 DOCX 文本解析组件，请先下载查看。';
        respond(true, '简历预览', ['preview' => $preview]);
    }
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        $preview['message'] = 'DOCX 文件无法解析，可能上传过程损坏，请下载核对或让候选人重新上传。';
        respond(true, '简历预览', ['preview' => $preview]);
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if (!$xml) {
        $preview['message'] = 'DOCX 文件缺少正文内容，请下载核对或让候选人重新上传。';
        respond(true, '简历预览', ['preview' => $preview]);
    }
    $xml = preg_replace('/<\/w:p>/', "\n", $xml);
    $xml = preg_replace('/<w:tab\/>/', "\t", $xml);
    $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    $text = preg_replace("/[ \t]+/", ' ', $text);
    $text = preg_replace("/\n{3,}/", "\n\n", trim($text));
    $preview['preview_type'] = 'text';
    $preview['text'] = function_exists('mb_substr') ? mb_substr($text, 0, 12000, 'UTF-8') : substr($text, 0, 12000);
    $preview['message'] = $preview['text'] !== '' ? '已提取 DOCX 正文用于网页预览。' : '未提取到可预览正文，请下载原文件查看。';
    respond(true, '简历预览', ['preview' => $preview]);
}

if ($ext === 'doc') {
    $preview['message'] = '旧版 DOC 文件浏览器无法稳定预览，请下载原文件查看。';
    respond(true, '简历预览', ['preview' => $preview]);
}

$preview['message'] = '该文件格式暂不支持网页预览，请下载原文件查看。';
respond(true, '简历预览', ['preview' => $preview]);
