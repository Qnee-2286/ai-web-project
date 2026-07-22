<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/payment_membership.php';
require_post();

$hr = require_hr($pdo);
$hrId = (int)$hr['id'];
$input = json_input();
$jobId = (int)($input['job_id'] ?? 0);

if ($jobId <= 0) {
    respond(false, 'Invalid job id', [], 422);
}

$stmt = $pdo->prepare('SELECT id FROM hr_jobs WHERE id=? AND hr_id=? AND status="active" LIMIT 1');
$stmt->execute([$jobId, $hrId]);
if (!$stmt->fetch()) {
    respond(false, 'Interview job not found or closed', [], 404);
}

$tokenStmt = $pdo->prepare('
    SELECT invite_token
    FROM candidates
    WHERE hr_id=?
      AND job_id=?
      AND (phone IS NULL OR phone="")
      AND phone_verified_at IS NULL
      AND candidate_status="not_received"
    ORDER BY id ASC
    LIMIT 1
');
$tokenStmt->execute([$hrId, $jobId]);
$token = (string)($tokenStmt->fetchColumn() ?: '');
$quotaSource = 'existing';

if ($token === '') {
    $consume = hi_consume_interview_quota($pdo, $hrId);
    if (empty($consume['ok'])) {
        respond(false, 'Interview quota is insufficient. Please upgrade membership or buy an addon package.', [
            'quota_required' => true,
        ], 402);
    }
    $quotaSource = (string)$consume['source'];

    $token = generate_token();
    $insert = $pdo->prepare('
        INSERT INTO candidates
            (hr_id, job_id, invite_token, realname_status, candidate_status, created_at, updated_at)
        VALUES (?, ?, ?, "pending", "not_received", NOW(), NOW())
    ');
    $insert->execute([$hrId, $jobId, $token]);
}

$base = rtrim($config['app']['base_url'] ?? 'https://hi.hongzedigital.com', '/');
$link = $base . '/candidate/index.html?token=' . urlencode($token);

respond(true, 'Interview link created', [
    'candidate_token' => $token,
    'link' => $link,
    'quota_source' => $quotaSource,
]);
