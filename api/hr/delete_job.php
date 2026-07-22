<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$hr = require_hr($pdo);
$input = json_input();
$jobId = (int)($input['job_id'] ?? 0);

if ($jobId <= 0) {
    respond(false, '初面任务ID无效', [], 422);
}

$stmt = $pdo->prepare('SELECT id, status FROM hr_jobs WHERE id=? AND hr_id=? LIMIT 1');
$stmt->execute([$jobId, (int)$hr['id']]);
$job = $stmt->fetch();
if (!$job) {
    respond(false, '初面任务不存在，或当前账号无权删除', [], 404);
}
if (($job['status'] ?? '') === 'closed') {
    respond(true, '该岗位已删除', ['job_id' => $jobId]);
}

$update = $pdo->prepare('UPDATE hr_jobs SET status="closed", updated_at=NOW() WHERE id=? AND hr_id=?');
$update->execute([$jobId, (int)$hr['id']]);

respond(true, '岗位已删除，历史候选人和报告记录仍会保留', ['job_id' => $jobId]);
