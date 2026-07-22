<?php
require_once __DIR__ . '/../bootstrap.php';

$hr = require_hr($pdo);
$hrId = (int)$hr['id'];

function ensure_hr_job_drafts_table(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS hr_job_drafts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        hr_id INT UNSIGNED NOT NULL,
        draft_key VARCHAR(80) NOT NULL,
        payload_json MEDIUMTEXT NULL,
        updated_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_hr_draft (hr_id, draft_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function clean_draft_key(string $key): string
{
    $key = trim($key);
    if ($key === '') {
        return 'new';
    }
    return preg_replace('/[^a-zA-Z0-9:_-]/', '', substr($key, 0, 80)) ?: 'new';
}

ensure_hr_job_drafts_table($pdo);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    $draftKey = clean_draft_key((string)($_GET['draft_key'] ?? 'new'));
    $stmt = $pdo->prepare('SELECT payload_json, updated_at FROM hr_job_drafts WHERE hr_id=? AND draft_key=? LIMIT 1');
    $stmt->execute([$hrId, $draftKey]);
    $row = $stmt->fetch();
    $payload = $row ? json_decode((string)$row['payload_json'], true) : null;
    respond(true, '草稿读取成功', [
        'draft_key' => $draftKey,
        'payload' => is_array($payload) ? $payload : null,
        'updated_at' => $row['updated_at'] ?? null,
    ]);
}

require_post();
$input = json_input();
$action = trim((string)($input['action'] ?? 'save'));
$draftKey = clean_draft_key((string)($input['draft_key'] ?? 'new'));

if ($action === 'clear') {
    $stmt = $pdo->prepare('DELETE FROM hr_job_drafts WHERE hr_id=? AND draft_key=?');
    $stmt->execute([$hrId, $draftKey]);
    respond(true, '草稿已清除', ['draft_key' => $draftKey]);
}

$payload = $input['payload'] ?? [];
if (!is_array($payload)) {
    respond(false, '草稿内容格式不正确', [], 422);
}

$allowed = [
    'job_id', 'company_name', 'job_title', 'work_location', 'question_bank',
    'salary_min_k', 'salary_max_k', 'salary_unit', 'benefits',
    'company_intro', 'responsibilities', 'requirements',
];
$clean = [];
foreach ($allowed as $key) {
    if (array_key_exists($key, $payload)) {
        $clean[$key] = is_scalar($payload[$key]) ? (string)$payload[$key] : '';
    }
}
$clean['_saved_at'] = date('Y-m-d H:i:s');

$stmt = $pdo->prepare('INSERT INTO hr_job_drafts(hr_id, draft_key, payload_json, created_at, updated_at)
    VALUES(?,?,?,NOW(),NOW())
    ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json), updated_at=NOW()');
$stmt->execute([$hrId, $draftKey, json_encode($clean, JSON_UNESCAPED_UNICODE)]);

respond(true, '草稿已自动保存', [
    'draft_key' => $draftKey,
    'payload' => $clean,
]);
