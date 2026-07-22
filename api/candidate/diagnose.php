<?php
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

$checks = [];

$tables = [
    'candidate_accounts',
    'authentication_records',
    'candidates',
    'verification_codes',
    'hr_jobs',
    'candidate_interview_sessions',
    'candidate_interview_reports',
];

foreach ($tables as $t) {
    try {
        $checks[] = ['type' => 'table', 'name' => $t, 'ok' => table_exists($pdo, $t)];
    } catch (Throwable $e) {
        $checks[] = ['type' => 'table', 'name' => $t, 'ok' => false, 'error' => $e->getMessage()];
    }
}

$cols = [
    ['candidates', 'candidate_account_id'],
    ['candidates', 'real_name'],
    ['candidates', 'job_id'],
    ['authentication_records', 'id_card_mask'],
    ['authentication_records', 'avatar_url'],
];
foreach ($cols as [$t, $c]) {
    try {
        if (!table_exists($pdo, $t)) {
            $checks[] = ['type' => 'column', 'name' => "$t.$c", 'ok' => false, 'reason' => "表 $t 不存在"];
        } else {
            $checks[] = ['type' => 'column', 'name' => "$t.$c", 'ok' => column_exists($pdo, $t, $c)];
        }
    } catch (Throwable $e) {
        $checks[] = ['type' => 'column', 'name' => "$t.$c", 'ok' => false, 'error' => $e->getMessage()];
    }
}

$stats = [];
try {
    if (table_exists($pdo, 'candidate_accounts')) {
        $stats[] = 'candidate_accounts 记录数: ' . $pdo->query('SELECT COUNT(*) FROM candidate_accounts')->fetchColumn();
    }
} catch (Throwable $e) { $stats[] = 'candidate_accounts 查询失败: ' . $e->getMessage(); }

try {
    if (table_exists($pdo, 'verification_codes')) {
        $stats[] = 'candidate_auth 验证码记录数: ' . $pdo->query("SELECT COUNT(*) FROM verification_codes WHERE purpose='candidate_auth'")->fetchColumn();
    }
} catch (Throwable $e) { $stats[] = 'verification_codes 查询失败: ' . $e->getMessage(); }

$allOk = true;
$missing = [];
foreach ($checks as $c) {
    if (!$c['ok']) { $allOk = false; $missing[] = $c['name']; }
}

$phpInfo = [
    'PHP 版本' => PHP_VERSION,
    'PDO 驱动' => implode(', ', PDO::getAvailableDrivers()),
    'Session' => session_status() === PHP_SESSION_ACTIVE ? '正常' : '未激活',
];
?>
<!DOCTYPE html>
<html lang="zh-CN"><head><meta charset="UTF-8"><title>候选人端诊断</title>
<style>
body{font-family:"Microsoft YaHei",sans-serif;padding:24px;max-width:700px;margin:0 auto;background:#f8f9fa;color:#333}
h1{font-size:20px;margin-bottom:16px}h2{font-size:15px;margin:20px 0 8px;color:#555}
.ok{color:#16a34a;font-weight:bold}.fail{color:#dc2626;font-weight:bold}
table{width:100%;border-collapse:collapse;margin-bottom:16px;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08)}
th,td{text-align:left;padding:8px 12px;border-bottom:1px solid #eee;font-size:13px}
th{background:#f1f5f9;font-weight:700}
.stat{background:#eff6ff;padding:10px 14px;border-radius:8px;margin-bottom:8px;font-size:13px}
.warn{background:#fef3c7;border:1px solid #f59e0b;padding:14px;border-radius:8px;margin:16px 0;font-size:13px;line-height:1.7}
.tip{background:#ecfdf5;border:1px solid #16a34a;padding:14px;border-radius:8px;margin:16px 0;font-size:13px;line-height:1.7}
</style></head><body>
<h1>候选人端数据库诊断</h1>

<h2>PHP 环境</h2>
<?php foreach ($phpInfo as $k => $v): ?>
<div class="stat"><strong><?=htmlspecialchars($k)?></strong>: <?=htmlspecialchars($v)?></div>
<?php endforeach; ?>

<h2>数据表 &amp; 列检查</h2>
<table>
<tr><th>类型</th><th>名称</th><th>状态</th><th>备注</th></tr>
<?php foreach ($checks as $c): ?>
<tr>
  <td><?=$c['type']==='table'?'表':'列'?></td>
  <td><?=htmlspecialchars($c['name'])?></td>
  <td class="<?=$c['ok']?'ok':'fail'?>"><?=$c['ok']?'✓ 存在':'✗ 缺失'?></td>
  <td><?=htmlspecialchars($c['error'] ?? $c['reason'] ?? '')?></td>
</tr>
<?php endforeach; ?>
</table>

<h2>数据统计</h2>
<?php foreach ($stats as $s): ?>
<div class="stat"><?=htmlspecialchars($s)?></div>
<?php endforeach; ?>

<?php if ($allOk): ?>
<div class="tip">所有检查项均通过。如候选人登录仍有问题，请检查 PHP 错误日志。</div>
<?php else: ?>
<div class="warn">
<strong>发现 <?=count($missing)?> 项缺失：</strong><br>
<?php foreach ($missing as $m): ?>• <?=htmlspecialchars($m)?><br><?php endforeach; ?>
<br>
<strong>解决方案：</strong>请在服务器 MySQL 中执行以下升级脚本：<br>
<code>upgrade_20260527_admin_candidate_accounts.sql</code>
</div>
<?php endif; ?>
<p style="color:#999;font-size:12px;margin-top:24px">诊断时间: <?=date('Y-m-d H:i:s')?></p>
</body></html>
