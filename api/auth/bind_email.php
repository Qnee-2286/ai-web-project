<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$hr = require_hr($pdo);
$input = json_input();
$email = normalize_email($input['email'] ?? '');
$code = trim($input['email_code'] ?? '');
$agreement = !empty($input['agreement']);

if (!validate_email($email)) {
    respond(false, '邮箱格式不正确', [], 422);
}
if (!$agreement) {
    respond(false, '请确认该邮箱为本人或企业授权使用', [], 422);
}
try {
    $codeOk = verify_code($pdo, 'email', $email, 'bind_email', $code);
} catch (Throwable $e) {
    respond(false, '邮箱验证码校验失败，请稍后重试', ['error' => $e->getMessage()], 500);
}
if (!$codeOk) {
    try {
        $debug = verification_code_debug($pdo, 'email', $email, 'bind_email', $code);
        $hint = sprintf(
            'v7 session=%s cookie=%s db_rows=%d db_matches=%d latest_id=%s latest_used=%s latest_created=%s latest_expires=%s',
            $debug['session'],
            $debug['cookie'],
            $debug['db_rows'],
            $debug['db_matches'],
            (string)($debug['latest_id'] ?? 'null'),
            $debug['latest_used'],
            (string)($debug['latest_created'] ?? 'null'),
            (string)($debug['latest_expires'] ?? 'null')
        );
    } catch (Throwable $e) {
        $hint = 'v7 debug_error=' . $e->getMessage();
    }
    respond(false, '邮箱验证码错误或已过期（' . $hint . '）', [], 422);
}

try {
    if (column_exists($pdo, 'hr_accounts', 'email')) {
        $stmt = $pdo->prepare('SELECT id FROM hr_accounts WHERE email=? LIMIT 1');
        $stmt->execute([$email]);
        $existingId = (int)($stmt->fetchColumn() ?: 0);
        if ($existingId > 0 && $existingId !== (int)$hr['id']) {
            respond(false, '该邮箱已绑定其他HR账号，请更换邮箱或先解绑原账号', [], 409);
        }
    }

    $sets = [];
    $params = [];
    if (column_exists($pdo, 'hr_accounts', 'email')) {
        $sets[] = 'email=?';
        $params[] = $email;
    }
    if (column_exists($pdo, 'hr_accounts', 'email_verified_at')) {
        $sets[] = 'email_verified_at=NOW()';
    }
    if (column_exists($pdo, 'hr_accounts', 'updated_at')) {
        $sets[] = 'updated_at=NOW()';
    }
    if (!$sets) {
        respond(false, 'HR账号邮箱字段未初始化，请联系管理员检查数据库', [], 500);
    }
    $params[] = $hr['id'];
    $stmt = $pdo->prepare('UPDATE hr_accounts SET ' . implode(', ', $sets) . ' WHERE id=?');
    $stmt->execute($params);
} catch (Throwable $e) {
    respond(false, '邮箱绑定更新失败：' . $e->getMessage(), ['error' => $e->getMessage()], 500);
}

respond(true, '邮箱绑定成功，请继续完成HR实名', ['next' => 'realname.html']);
