<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

require_platform_admin($pdo);
$input = json_input();
$candidateId = (int)($input['candidate_id'] ?? 0);
$isTest = !empty($input['is_test']) ? 1 : 0;
if ($candidateId <= 0) {
    respond(false, '候选人记录无效。', [], 422);
}
$stmt = $pdo->prepare('UPDATE candidates SET is_test=?, updated_at=NOW() WHERE id=?');
$stmt->execute([$isTest, $candidateId]);
respond(true, $isTest ? '已标记为测试记录。' : '已恢复为真实业务记录。', [
    'candidate_id' => $candidateId,
    'is_test' => $isTest,
]);
