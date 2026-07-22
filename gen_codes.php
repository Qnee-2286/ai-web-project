<?php
/**
 * 兑换码生成工具 - 网页版
 * 
 * 使用方式：浏览器访问
 *   https://hi.hongzedigital.com/gen_codes.php
 * 
 * ⚠ 安全提醒：生成完兑换码后，请从服务器删除此文件！
 */

// ===== 连接数据库 =====
$config = require __DIR__ . '/api/config.php';
date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Shanghai');

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $config['db']['host'] ?? '127.0.0.1',
        $config['db']['port'] ?? '3306',
        $config['db']['database'] ?? 'hi_interview'
    );
    $pdo = new PDO($dsn, $config['db']['username'] ?? 'root', $config['db']['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("数据库连接失败: " . htmlspecialchars($e->getMessage()));
}

// ===== 查套餐列表 =====
$plans = $pdo->query('SELECT id, plan_key, plan_name, price, interview_quota FROM membership_plans WHERE is_active = 1 ORDER BY sort_order')->fetchAll();

// ===== 处理生成请求（PRG 模式，防止刷新重复生成） =====
$generatedCodes = [];
$planLabel = '';
$showResult = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate') {
    $planKey = $_POST['plan_key'] ?? 'monthly';
    $count = max(1, min(200, (int)($_POST['count'] ?? 10)));

    $planStmt = $pdo->prepare('SELECT id, plan_name, price, interview_quota FROM membership_plans WHERE plan_key = ? AND is_active = 1');
    $planStmt->execute([$planKey]);
    $plan = $planStmt->fetch();

    if ($plan) {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $now = date('Y-m-d H:i:s');
        $insertStmt = $pdo->prepare('INSERT INTO redemption_codes (code, plan_id, status, generated_by, created_at) VALUES (?, ?, "unused", NULL, ?)');
        $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM redemption_codes WHERE code = ?');

        for ($i = 0; $i < $count; $i++) {
            $attempts = 0;
            do {
                $len = random_int(10, 18);
                $code = '';
                for ($j = 0; $j < $len; $j++) {
                    $code .= $chars[random_int(0, strlen($chars) - 1)];
                }
                $checkStmt->execute([$code]);
                $attempts++;
            } while ((int)$checkStmt->fetchColumn() > 0 && $attempts < 10);

            $insertStmt->execute([$code, $plan['id'], $now]);
            $generatedCodes[] = $code;
        }

        // 用 session 暂存结果，然后重定向（PRG 模式）
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['_gen_result'] = [
            'codes' => $generatedCodes,
            'plan_name' => $plan['plan_name'],
            'time' => time(),
        ];
        header('Location: ' . $_SERVER['PHP_SELF'] . '?generated=1');
        exit;
    }
}

// 从重定向回来时，读取 session 中的结果
if (isset($_GET['generated'])) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (!empty($_SESSION['_gen_result'])) {
        $generatedCodes = $_SESSION['_gen_result']['codes'];
        $planLabel = $_SESSION['_gen_result']['plan_name'];
        $showResult = true;
        unset($_SESSION['_gen_result']);
    }
}

// ===== 查已有码列表 =====
$existingStmt = $pdo->query('
    SELECT rc.code, p.plan_name, rc.status, rc.used_at, rc.created_at
    FROM redemption_codes rc
    JOIN membership_plans p ON p.id = rc.plan_id
    ORDER BY rc.created_at DESC
    LIMIT 100
');
$existingCodes = $existingStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>兑换码管理工具</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: "Microsoft YaHei","PingFang SC","Segoe UI",sans-serif; background: #F7FAFC; color: #0F172A; line-height: 1.6; }
.container { max-width: 720px; margin: 0 auto; padding: 24px 16px 48px; }
h1 { font-size: 22px; margin-bottom: 6px; color: #0070FF; }
.subtitle { color: #475569; font-size: 13px; margin-bottom: 24px; }
.card { background: #fff; border: 1px solid #E2E8F0; border-radius: 12px; padding: 24px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
.card h2 { font-size: 16px; margin-bottom: 16px; }
.form-row { display: flex; gap: 12px; align-items: flex-end; margin-bottom: 14px; flex-wrap: wrap; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group label { font-size: 13px; font-weight: 600; color: #475569; }
select, input[type="number"] { height: 40px; padding: 0 12px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; background: #fff; color: #0F172A; }
select { min-width: 200px; }
input[type="number"] { width: 100px; }
.btn-gen { height: 40px; padding: 0 24px; border: none; border-radius: 8px; background: linear-gradient(135deg, #0070FF, #00D68F); color: #fff; font-size: 14px; font-weight: 700; cursor: pointer; white-space: nowrap; }
.btn-gen:hover { opacity: 0.9; }
.result { margin-top: 20px; }
.result h3 { font-size: 15px; margin-bottom: 10px; }
.code-list { background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 12px 16px; font-family: Consolas, "Courier New", monospace; font-size: 14px; line-height: 2; max-height: 300px; overflow-y: auto; }
.code-item { display: flex; align-items: center; justify-content: space-between; padding: 2px 0; }
.code-item .code-text { font-weight: 600; color: #0070FF; }
.btn-copy { background: none; border: 1px solid #E2E8F0; border-radius: 4px; padding: 2px 10px; font-size: 12px; color: #475569; cursor: pointer; }
.btn-copy:hover { background: #EFF6FF; color: #0070FF; }
.btn-copy-all { height: 36px; padding: 0 16px; border: 1px solid #0070FF; border-radius: 8px; background: #EFF6FF; color: #0070FF; font-size: 13px; font-weight: 700; cursor: pointer; margin-top: 10px; }
.btn-copy-all:hover { background: #0070FF; color: #fff; }
.warn { margin-top: 20px; padding: 12px 16px; border-radius: 8px; background: #FFFBEB; border: 1px solid rgba(245,158,11,0.3); color: #92400E; font-size: 13px; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { text-align: left; padding: 10px 12px; color: #475569; border-bottom: 1px solid #E2E8F0; font-weight: 600; }
td { padding: 10px 12px; border-bottom: 1px solid #F1F5F9; }
.tag { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.tag-unused { background: #ECFDF5; color: #047857; }
.tag-used { background: #F1F5F9; color: #64748B; }
.tag-expired { background: #FEF2F2; color: #DC2626; }
.empty { text-align: center; padding: 20px; color: #94A3B8; }
</style>
</head>
<body>
<div class="container">
  <h1>兑换码管理工具</h1>
  <p class="subtitle">生成和管理 AI全量初面系统 的会员兑换码</p>

  <!-- 生成兑换码 -->
  <div class="card">
    <h2>批量生成兑换码</h2>
    <form method="POST">
      <input type="hidden" name="action" value="generate">
      <div class="form-row">
        <div class="form-group">
          <label>套餐类型</label>
          <select name="plan_key">
            <?php foreach ($plans as $p): ?>
            <option value="<?= htmlspecialchars($p['plan_key']) ?>">
              <?= htmlspecialchars($p['plan_name']) ?>（¥<?= $p['price'] ?>/<?= $p['interview_quota'] ?>次）
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>生成数量</label>
          <input type="number" name="count" value="10" min="1" max="200">
        </div>
        <button type="submit" class="btn-gen">生成兑换码</button>
      </div>
    </form>

    <?php if ($showResult): ?>
    <div class="result">
      <h3>已生成 <?= count($generatedCodes) ?> 个「<?= htmlspecialchars($planLabel) ?>」兑换码：</h3>
      <div class="code-list" id="codeList">
        <?php foreach ($generatedCodes as $i => $code): ?>
        <div class="code-item">
          <span><?= $i + 1 ?>. <span class="code-text"><?= htmlspecialchars($code) ?></span></span>
          <button class="btn-copy" onclick="copyCode('<?= htmlspecialchars($code) ?>', this)">复制</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button class="btn-copy-all" onclick="copyAll()">一键复制全部</button>
    </div>
    <?php endif; ?>
  </div>

  <!-- 已有兑换码 -->
  <div class="card">
    <h2>已有兑换码（最近 100 条）</h2>
    <?php if (empty($existingCodes)): ?>
      <p class="empty">暂无兑换码记录</p>
    <?php else: ?>
    <div style="overflow-x:auto">
      <table>
        <thead><tr><th>兑换码</th><th>套餐</th><th>状态</th><th>使用/生成时间</th></tr></thead>
        <tbody>
        <?php foreach ($existingCodes as $row): ?>
          <tr>
            <td style="font-family:Consolas,monospace;font-weight:600;color:#0070FF"><?= htmlspecialchars($row['code']) ?></td>
            <td><?= htmlspecialchars($row['plan_name']) ?></td>
            <td>
              <?php if ($row['status'] === 'unused'): ?>
                <span class="tag tag-unused">未使用</span>
              <?php elseif ($row['status'] === 'used'): ?>
                <span class="tag tag-used">已使用</span>
              <?php else: ?>
                <span class="tag tag-expired">已失效</span>
              <?php endif; ?>
            </td>
            <td style="color:#94A3B8;font-size:12px"><?= htmlspecialchars($row['used_at'] ?? $row['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="warn">
    ⚠️ 安全提醒：生成完兑换码后，请从服务器删除此文件（gen_codes.php），避免被他人访问。
  </div>
</div>

<script>
function copyCode(code, btn) {
  navigator.clipboard.writeText(code).then(function() {
    btn.textContent = '已复制';
    setTimeout(function() { btn.textContent = '复制'; }, 1500);
  });
}
function copyAll() {
  var items = document.querySelectorAll('#codeList .code-text');
  var text = '';
  items.forEach(function(el, i) { text += (i+1) + '. ' + el.textContent + '\n'; });
  navigator.clipboard.writeText(text).then(function() {
    var btn = document.querySelector('.btn-copy-all');
    btn.textContent = '已复制全部！';
    setTimeout(function() { btn.textContent = '一键复制全部'; }, 1500);
  });
}
</script>
</body>
</html>
