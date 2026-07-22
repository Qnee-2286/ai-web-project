<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

$hr = require_hr($pdo);
$input = json_input();
$jobId = (int)($input['job_id'] ?? 0);

if ($jobId <= 0) {
    respond(false, '请先选择要生成题库的初面任务', [], 422);
}
if (empty($config['llm']['api_key']) || ($config['llm']['api_key'] ?? '') === '你的百炼API Key') {
    respond(false, '百炼API Key未配置，请先在 api/config.php 填写 llm.api_key', [], 422);
}
if (!table_exists($pdo, 'ai_question_sets') || !table_exists($pdo, 'ai_interview_questions')) {
    respond(false, 'AI题库数据表未初始化，请先在宝塔数据库导入 upgrade_20260518_ai_questions.sql', [], 500);
}

$stmt = $pdo->prepare('SELECT * FROM hr_jobs WHERE id=? AND hr_id=? LIMIT 1');
$stmt->execute([$jobId, (int)$hr['id']]);
$job = $stmt->fetch();
if (!$job) {
    respond(false, '初面任务不存在，或当前账号无权访问', [], 404);
}

$system = '你是专业、审慎、以人为本的招聘初面题库设计助手。你只生成面试问题，不替企业做录用决策，不生成公司介绍、薪资福利、岗位职责和任职要求。问题要帮助HR看见真实的人，识别简历包装和假大空表达，同时给表达慢热但有能力的候选人机会。';

$user = implode("\n", [
    '请根据以下岗位信息，生成一组适合线上AI初面的题库初稿。',
    '',
    '题目结构：',
    '1. 3道基础必问题：用于确认基础经历、求职动机和岗位理解。',
    '2. 3道岗位匹配题：围绕岗位职责和任职要求验证实际能力。',
    '3. 2道简历追问题：用于追问项目细节、个人贡献、结果口径，识别过度包装。',
    '4. 1道补充引导题：给表达慢热但有经验的候选人补充说明机会。',
    '',
    '生成要求：',
    '1. 问题要具体、口语化，适合10到15分钟线上初面。',
    '2. 不提违法、歧视或与岗位无关的问题。',
    '3. 不根据年龄、性别、婚育、生育计划等因素判断候选人。',
    '4. 每道题必须说明题型、难度、考察目的。',
    '5. 前3道题 is_required=true，其余题目按需要设置。',
    '6. 只返回JSON对象，不要输出解释文字。',
    '',
    'JSON格式：',
    '{"questions":[{"question":"问题文本","type":"基础必问题/岗位匹配题/简历追问题/补充引导题","difficulty":"基础/中等/深入","purpose":"考察目的","is_required":true}]}',
    '',
    '岗位信息：',
    '公司名称：' . $job['company_name'],
    '岗位名称：' . $job['job_title'],
    '薪资范围：' . $job['salary_min_k'] . '-' . $job['salary_max_k'] . 'K',
    '福利待遇：' . ($job['benefits'] ?: '未填写'),
    '公司介绍：' . ($job['company_intro'] ?: '未填写'),
    '岗位职责：' . $job['responsibilities'],
    '任职要求：' . $job['requirements'],
]);

$messages = [
    ['role' => 'system', 'content' => $system],
    ['role' => 'user', 'content' => $user],
];

try {
    $response = dashscope_chat($config['llm'] ?? [], $messages);
    $content = dashscope_message_content($response);
    $parsed = parse_llm_json_object($content);
    $questions = $parsed['questions'] ?? [];
    if (!is_array($questions) || count($questions) === 0) {
        respond(false, 'AI没有返回可用题目，请稍后重试', ['raw' => $content], 500);
    }

    $pdo->beginTransaction();

    $set = $pdo->prepare('INSERT INTO ai_question_sets(hr_id, job_id, provider, model, status, prompt_hash, raw_response, created_at) VALUES(?,?,?,?, "generated", ?, ?, NOW())');
    $set->execute([
        (int)$hr['id'],
        $jobId,
        $config['llm']['provider'] ?? 'dashscope',
        $config['llm']['model'] ?? 'qwen-plus',
        hash('sha256', json_encode($messages, JSON_UNESCAPED_UNICODE)),
        json_encode($response, JSON_UNESCAPED_UNICODE),
    ]);
    $setId = (int)$pdo->lastInsertId();

    $old = $pdo->prepare('DELETE FROM ai_interview_questions WHERE job_id=? AND source="ai"');
    $old->execute([$jobId]);

    $insert = $pdo->prepare('INSERT INTO ai_interview_questions(set_id, hr_id, job_id, question_text, question_type, difficulty, purpose, sort_order, is_required, source, created_at) VALUES(?,?,?,?,?,?,?,?,?,"ai",NOW())');
    $saved = [];
    $i = 1;
    foreach ($questions as $item) {
        if (!is_array($item)) {
            continue;
        }
        $question = trim((string)($item['question'] ?? ''));
        if ($question === '') {
            continue;
        }
        $type = mb_substr(trim((string)($item['type'] ?? '岗位匹配题')), 0, 40, 'UTF-8');
        $difficulty = mb_substr(trim((string)($item['difficulty'] ?? '中等')), 0, 40, 'UTF-8');
        $purpose = mb_substr(trim((string)($item['purpose'] ?? '用于初面判断')), 0, 255, 'UTF-8');
        $isRequired = (!empty($item['is_required']) || $i <= 3) ? 1 : 0;

        $insert->execute([$setId, (int)$hr['id'], $jobId, $question, $type, $difficulty, $purpose, $i, $isRequired]);
        $saved[] = [
            'id' => (int)$pdo->lastInsertId(),
            'question' => $question,
            'question_text' => $question,
            'type' => $type,
            'question_type' => $type,
            'difficulty' => $difficulty,
            'purpose' => $purpose,
            'is_required' => (bool)$isRequired,
        ];
        $i++;
        if ($i > 9) {
            break;
        }
    }

    if (count($saved) === 0) {
        throw new RuntimeException('AI返回内容中没有可保存的问题');
    }

    $pdo->commit();
    respond(true, 'AI题库初稿已生成', ['set_id' => $setId, 'questions' => $saved]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(false, 'AI生成失败：' . $e->getMessage(), [], 500);
}
