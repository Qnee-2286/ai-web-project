<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$input = json_input();
$phone = trim((string)($input['phone'] ?? ''));
$code = trim((string)($input['sms_code'] ?? ''));

if (!validate_phone($phone)) {
    respond(false, '请填写正确的手机号', [], 422);
}
if ($code === '') {
    respond(false, '请输入短信验证码', [], 422);
}

/* ---- 验证码校验 ---- */
try {
    $codeOk = verify_code($pdo, 'sms', $phone, 'candidate_auth', $code);
} catch (Throwable $e) {
    respond(false, '验证码校验失败：' . $e->getMessage(), [], 500);
}
if (!$codeOk) {
    respond(false, '短信验证码错误或已过期', [], 422);
}

/* ---- 查找或创建候选人独立账户 ---- */
try {
    $hasTable = table_exists($pdo, 'candidate_accounts');
} catch (Throwable $e) {
    respond(false, '数据库检查失败：' . $e->getMessage(), [], 500);
}
if (!$hasTable) {
    respond(false, '系统尚未初始化候选人账户表(candidate_accounts)，请在服务器上执行升级SQL', [], 500);
}

try {
    $stmt = $pdo->prepare('SELECT * FROM candidate_accounts WHERE phone=? LIMIT 1');
    $stmt->execute([$phone]);
    $account = $stmt->fetch();
} catch (Throwable $e) {
    respond(false, '查询候选人账户失败：' . $e->getMessage(), [], 500);
}

if (!$account) {
    /* 新用户：创建 candidate_account */
    try {
        $pdo->prepare(
            'INSERT INTO candidate_accounts(phone, phone_verified_at, realname_status, created_at, updated_at)
             VALUES(?, NOW(), "pending", NOW(), NOW())'
        )->execute([$phone]);
        $accountId = $pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM candidate_accounts WHERE id=? LIMIT 1');
        $stmt->execute([$accountId]);
        $account = $stmt->fetch();
    } catch (Throwable $e) {
        respond(false, '创建候选人账户失败：' . $e->getMessage(), [], 500);
    }
} else {
    /* 已有账户：更新验证时间 */
    try {
        $pdo->prepare('UPDATE candidate_accounts SET phone_verified_at=NOW(), updated_at=NOW() WHERE id=?')
            ->execute([$account['id']]);
        $account['phone_verified_at'] = date('Y-m-d H:i:s');
    } catch (Throwable $e) {
        /* 更新失败不阻断登录 */
    }
}

if (!$account) {
    respond(false, '账户数据异常，请重试', [], 500);
}

/* 写入 session */
$_SESSION['candidate_account_id'] = (int)$account['id'];

/* 将同手机号的历史面试记录补绑到候选人账号，便于个人中心查看 */
try {
    if (table_exists($pdo, 'candidates') && column_exists($pdo, 'candidates', 'candidate_account_id')) {
        $stmt = $pdo->prepare('UPDATE candidates SET candidate_account_id=?, updated_at=NOW() WHERE phone=? AND (candidate_account_id IS NULL OR candidate_account_id=0)');
        $stmt->execute([(int)$account['id'], $phone]);
    }
} catch (Throwable $e) {
    /* 补绑失败不阻断登录 */
}

/* 查最新实名信息（非关键，失败不阻断） */
$realName = null;
$idCardMask = null;
$avatarUrl = null;

try {
    if (table_exists($pdo, 'authentication_records')) {
        $stmt = $pdo->prepare(
            'SELECT id_card_mask, avatar_url
               FROM authentication_records
              WHERE subject_type="candidate" AND subject_id=? AND status="success"
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([(int)$account['id']]);
        $auth = $stmt->fetch();
        if ($auth) {
            $idCardMask = $auth['id_card_mask'] ?? null;
            $avatarUrl  = $auth['avatar_url'] ?? null;
        }
    }
} catch (Throwable $e) {
    /* 非关键查询，静默忽略 */
}

/* 尝试从 candidates 表获取实名姓名（非关键，失败不阻断） */
try {
    if (table_exists($pdo, 'candidates')
        && column_exists($pdo, 'candidates', 'real_name')
        && column_exists($pdo, 'candidates', 'candidate_account_id')) {
        $stmt = $pdo->prepare(
            'SELECT real_name FROM candidates
              WHERE candidate_account_id=? AND real_name IS NOT NULL AND real_name <> ""
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([(int)$account['id']]);
        $rn = $stmt->fetchColumn();
        if ($rn) $realName = $rn;
    }
} catch (Throwable $e) {
    /* 非关键查询，静默忽略 */
}

respond(true, '登录成功', [
    'account_id'       => (int)$account['id'],
    'phone'            => $account['phone'],
    'realname_status'  => $account['realname_status'] ?? 'pending',
    'real_name'        => $realName,
    'id_card_mask'     => $idCardMask,
    'avatar_url'       => $avatarUrl,
]);
