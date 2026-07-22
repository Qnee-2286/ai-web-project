<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$input = json_input();
$phone = trim((string)($input['phone'] ?? ''));
$code = trim((string)($input['sms_code'] ?? ''));
$token = trim((string)($input['token'] ?? ''));
$agreement = !empty($input['agreement']);
$jobRecordConfirm = !empty($input['job_record_confirm']);

if ($token === '') {
    respond(false, '面试链接无效，请从HR发送的邀请链接重新进入', [], 422);
}
if (!validate_phone($phone)) {
    respond(false, '请填写正确的手机号', [], 422);
}
if (!$agreement || !$jobRecordConfirm) {
    respond(false, '请先勾选授权协议和岗位信息留痕确认', [], 422);
}
if (!verify_code($pdo, 'sms', $phone, 'candidate_auth', $code)) {
    respond(false, '短信验证码错误或已过期', [], 422);
}

$invite = candidate_by_token($pdo, $token);
if (!$invite) {
    respond(false, '面试链接无效或已失效，请联系发起面试的HR', [], 404);
}
if (empty($invite['job_id']) || empty($invite['hr_id'])) {
    respond(false, '当前面试链接尚未关联岗位，请联系发起面试的HR', [], 404);
}

$accountId = 0;
$account = null;
try {
    if (table_exists($pdo, 'candidate_accounts')) {
        $stmt = $pdo->prepare('SELECT * FROM candidate_accounts WHERE phone=? LIMIT 1');
        $stmt->execute([$phone]);
        $account = $stmt->fetch();
        if (!$account) {
            $stmt = $pdo->prepare('INSERT INTO candidate_accounts(phone, phone_verified_at, realname_status, created_at, updated_at) VALUES(?, NOW(), "pending", NOW(), NOW())');
            $stmt->execute([$phone]);
            $accountId = (int)$pdo->lastInsertId();
        } else {
            $accountId = (int)$account['id'];
            $stmt = $pdo->prepare('UPDATE candidate_accounts SET phone_verified_at=NOW(), updated_at=NOW() WHERE id=?');
            $stmt->execute([$accountId]);
        }

        if ($accountId > 0 && (($account['realname_status'] ?? 'pending') !== 'verified')) {
            $hasCandidateRealName = column_exists($pdo, 'candidates', 'real_name');
            $nameSelect = $hasCandidateRealName ? 'real_name' : 'NULL AS real_name';
            $stmt = $pdo->prepare(
                'SELECT realname_verified_at, ' . $nameSelect . '
                   FROM candidates
                  WHERE phone=? AND realname_status="verified"
                  ORDER BY realname_verified_at DESC, id DESC
                  LIMIT 1'
            );
            $stmt->execute([$phone]);
            $verifiedCandidate = $stmt->fetch();
            if ($verifiedCandidate) {
                $hasAccountRealnameAt = column_exists($pdo, 'candidate_accounts', 'realname_verified_at');
                if (column_exists($pdo, 'candidate_accounts', 'real_name')) {
                    if ($hasAccountRealnameAt) {
                        $stmt = $pdo->prepare('UPDATE candidate_accounts SET realname_status="verified", realname_verified_at=?, real_name=COALESCE(NULLIF(?, ""), real_name), updated_at=NOW() WHERE id=?');
                        $stmt->execute([
                            $verifiedCandidate['realname_verified_at'] ?? date('Y-m-d H:i:s'),
                            $verifiedCandidate['real_name'] ?? '',
                            $accountId,
                        ]);
                    } else {
                        $stmt = $pdo->prepare('UPDATE candidate_accounts SET realname_status="verified", real_name=COALESCE(NULLIF(?, ""), real_name), updated_at=NOW() WHERE id=?');
                        $stmt->execute([$verifiedCandidate['real_name'] ?? '', $accountId]);
                    }
                } else {
                    if ($hasAccountRealnameAt) {
                        $stmt = $pdo->prepare('UPDATE candidate_accounts SET realname_status="verified", realname_verified_at=?, updated_at=NOW() WHERE id=?');
                        $stmt->execute([$verifiedCandidate['realname_verified_at'] ?? date('Y-m-d H:i:s'), $accountId]);
                    } else {
                        $stmt = $pdo->prepare('UPDATE candidate_accounts SET realname_status="verified", updated_at=NOW() WHERE id=?');
                        $stmt->execute([$accountId]);
                    }
                }
            }
        }

        $stmt = $pdo->prepare('SELECT * FROM candidate_accounts WHERE id=? LIMIT 1');
        $stmt->execute([$accountId]);
        $account = $stmt->fetch() ?: $account;
    }
} catch (Throwable $e) {
    respond(false, '候选人账号登录失败：' . $e->getMessage(), [], 500);
}

$terminalStatuses = ['completed', 'review_pending', 'rejected'];
$invitePhone = trim((string)($invite['phone'] ?? ''));
$inviteStatus = (string)($invite['candidate_status'] ?? '');
$hasCandidateAccountId = column_exists($pdo, 'candidates', 'candidate_account_id');
$candidate = null;

$identityWhere = 'phone=?';
$identityParams = [$phone];
if ($accountId > 0 && $hasCandidateAccountId) {
    $identityWhere = '(candidate_account_id=? OR phone=?)';
    $identityParams = [$accountId, $phone];
}

// Same person + same job + unfinished interview: resume it first.
$stmt = $pdo->prepare(
    'SELECT * FROM candidates
      WHERE hr_id=? AND job_id=? AND ' . $identityWhere . '
        AND candidate_status IN ("not_received","pending_interview","interviewing")
      ORDER BY id DESC LIMIT 1'
);
$stmt->execute(array_merge([(int)$invite['hr_id'], (int)$invite['job_id']], $identityParams));
$candidate = $stmt->fetch() ?: null;

// Same person + same job + already completed: return the completed record, do not create a new attempt.
if (!$candidate) {
    $stmt = $pdo->prepare(
        'SELECT * FROM candidates
          WHERE hr_id=? AND job_id=? AND ' . $identityWhere . '
            AND candidate_status IN ("completed","review_pending","rejected")
          ORDER BY updated_at DESC, id DESC LIMIT 1'
    );
    $stmt->execute(array_merge([(int)$invite['hr_id'], (int)$invite['job_id']], $identityParams));
    $candidate = $stmt->fetch() ?: null;
}

// A shared invite link can be claimed only while it is still blank.
if (!$candidate && $invitePhone === '' && !in_array($inviteStatus, $terminalStatuses, true)) {
    $candidate = $invite;
}

if (!$candidate) {
    $newToken = generate_token();
    while (candidate_by_token($pdo, $newToken)) {
        $newToken = generate_token();
    }
    $insert = $pdo->prepare('INSERT INTO candidates(hr_id, job_id, invite_token, phone, phone_verified_at, agreement_accepted_at, job_record_confirmed_at, realname_status, candidate_status, created_at, updated_at) VALUES(?,?,?,?,NOW(),NOW(),NOW(),"pending","pending_interview",NOW(),NOW())');
    $insert->execute([(int)$invite['hr_id'], (int)$invite['job_id'], $newToken, $phone]);
    $stmt = $pdo->prepare('SELECT * FROM candidates WHERE id=? LIMIT 1');
    $stmt->execute([(int)$pdo->lastInsertId()]);
    $candidate = $stmt->fetch();
}

$set = [
    'phone=?',
    'phone_verified_at=NOW()',
    'agreement_accepted_at=NOW()',
    'job_record_confirmed_at=NOW()',
    'candidate_status=IF(candidate_status="not_received","pending_interview",candidate_status)',
    'updated_at=NOW()',
];
$params = [$phone];

if ($accountId > 0 && $hasCandidateAccountId) {
    $set[] = 'candidate_account_id=?';
    $params[] = $accountId;
}
if (($account['realname_status'] ?? '') === 'verified') {
    $set[] = 'realname_status="verified"';
    $set[] = 'realname_verified_at=COALESCE(realname_verified_at, ?)';
    $params[] = $account['realname_verified_at'] ?? date('Y-m-d H:i:s');
    if (column_exists($pdo, 'candidates', 'real_name') && column_exists($pdo, 'candidate_accounts', 'real_name')) {
        $set[] = 'real_name=COALESCE(NULLIF(?, ""), real_name)';
        $params[] = $account['real_name'] ?? '';
    }
}

$params[] = (int)$candidate['id'];
$stmt = $pdo->prepare('UPDATE candidates SET ' . implode(', ', $set) . ' WHERE id=? AND candidate_status NOT IN ("completed","review_pending","rejected")');
$stmt->execute($params);

if ($accountId > 0 && $hasCandidateAccountId && in_array((string)($candidate['candidate_status'] ?? ''), $terminalStatuses, true)) {
    $stmt = $pdo->prepare('UPDATE candidates SET candidate_account_id=COALESCE(candidate_account_id, ?), updated_at=NOW() WHERE id=?');
    $stmt->execute([$accountId, (int)$candidate['id']]);
}

$stmt = $pdo->prepare('SELECT * FROM candidates WHERE id=? LIMIT 1');
$stmt->execute([(int)$candidate['id']]);
$candidate = $stmt->fetch() ?: $candidate;

$_SESSION['candidate_id'] = (int)$candidate['id'];
$_SESSION['candidate_token'] = $candidate['invite_token'];
if ($accountId > 0) {
    $_SESSION['candidate_account_id'] = $accountId;
}

respond(true, '手机号验证成功，请继续', [
    'candidate_token' => $candidate['invite_token'],
    'invite_token' => $token,
    'candidate_account_id' => $accountId,
    'realname_status' => $candidate['realname_status'] ?? 'pending',
    'candidate_status' => $candidate['candidate_status'] ?? 'not_received',
    'next' => 'realname',
]);
