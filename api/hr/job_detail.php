<?php
require_once __DIR__ . '/../bootstrap.php';
$hr = require_hr($pdo);
$jobId = (int)($_GET['job_id'] ?? 0);
if ($jobId <= 0) {
    respond(false, '岗位ID无效', [], 422);
}
$stmt = $pdo->prepare('SELECT * FROM hr_jobs WHERE id=? AND hr_id=? LIMIT 1');
$stmt->execute([$jobId, (int)$hr['id']]);
$job = $stmt->fetch();
if (!$job) {
    respond(false, '岗位不存在或无权访问', [], 404);
}
respond(true, '岗位详情', ['job' => $job]);
