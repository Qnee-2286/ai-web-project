<?php
/**
 * 补处理脚本：对已提交但未生成转写/AI报告的面试，重新运行 ASR + LLM
 *
 * 使用方式（在服务器命令行执行）：
 *   php api/admin/reprocess_interviews.php
 *
 * 或通过浏览器访问（需要 HR 登录）：
 *   https://hi.hongzedigital.com/api/admin/reprocess_interviews.php?key=你的密钥
 */

// 简单鉴权：命令行直接执行，HTTP 访问需要 key 参数
$isCLI = (php_sapi_name() === 'cli');
if (!$isCLI) {
    require_once __DIR__ . '/../bootstrap.php';
    $key = trim((string)($_GET['key'] ?? ''));
    $expectedKey = $config['admin_key'] ?? 'hi2026reprocess';
    if ($key !== $expectedKey) {
        http_response_code(403);
        echo json_encode(['error' => '无效的访问密钥'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    // CLI 模式，手动加载
    define('CLI_MODE', true);
    require_once __DIR__ . '/../bootstrap.php';
}

$ossConfig = $config['oss'] ?? [];
$llmConfig = $config['llm'] ?? [];
$apiKey = $llmConfig['api_key'] ?? '';

if (!oss_is_configured($ossConfig)) {
    echo "❌ OSS 未配置，无法获取录音\n";
    exit(1);
}
if ($apiKey === '') {
    echo "❌ LLM API Key 未配置，无法生成报告\n";
    exit(1);
}

echo "=== 面试补处理脚本 ===\n";
echo "时间: " . date('Y-m-d H:i:s') . "\n\n";

// 异步 ASR 需要较长时间，设置超时
if (function_exists('set_time_limit')) {
    set_time_limit(0);
}
if (function_exists('ini_set')) {
    ini_set('max_execution_time', '0');
}

// 支持 force=1 强制重跑（覆盖已有报告）
$force = isset($_GET['force']) && ($_GET['force'] === '1' || $_GET['force'] === 'true');
if ($force) {
    echo "⚠️ 强制重跑模式：会覆盖已有报告\n\n";
}

// 1. 找出所有已完成但没有报告的 session
if ($force) {
    $sessionStmt = $pdo->query('
        SELECT s.id, s.candidate_id, s.hr_id, s.job_id, s.completed_at
        FROM candidate_interview_sessions s
        WHERE s.status = "completed"
        ORDER BY s.completed_at ASC
    ');
} else {
    $sessionStmt = $pdo->query('
        SELECT s.id, s.candidate_id, s.hr_id, s.job_id, s.completed_at
        FROM candidate_interview_sessions s
        WHERE s.status = "completed"
          AND s.id NOT IN (SELECT session_id FROM candidate_interview_reports WHERE status = "ready")
        ORDER BY s.completed_at ASC
    ');
}
$sessions = $sessionStmt->fetchAll();
echo "找到 " . count($sessions) . " 个需要处理的面试会话\n\n";

$processed = 0;
$failed = 0;

foreach ($sessions as $session) {
    $sessionId = (int)$session['id'];
    echo "--- 处理 Session #{$sessionId} (完成于 {$session['completed_at']}) ---\n";

    // 2. 获取录音
    $recStmt = $pdo->prepare('SELECT id, question_text, question_type, sort_order, audio_object_key, audio_seconds, audio_mime_type, transcript_status, transcript_text FROM candidate_interview_recordings WHERE session_id=? ORDER BY sort_order ASC');
    $recStmt->execute([$sessionId]);
    $recordings = $recStmt->fetchAll();

    if (count($recordings) === 0) {
        echo "  ⚠️ 无录音记录，跳过\n";
        continue;
    }

    // 3. ASR 转写（只处理 pending/failed 的）
    $transcripts = [];
    foreach ($recordings as $rec) {
        $recId = (int)$rec['id'];
        $tStatus = $rec['transcript_status'] ?? 'pending';
        $tText = $rec['transcript_text'] ?? '';

        // 已经转写成功的，直接用
        if ($tStatus === 'completed' && $tText !== '') {
            $transcripts[] = ['id' => $recId, 'text' => $tText, 'status' => 'completed'];
            echo "  ✓ 录音 #{$recId} 已有转写，跳过\n";
            continue;
        }

        $objectKey = $rec['audio_object_key'] ?? '';
        if ($objectKey === '') {
            $transcripts[] = ['id' => $recId, 'text' => '', 'status' => 'skipped'];
            echo "  ⊘ 录音 #{$recId} 无 OSS 文件，跳过\n";
            continue;
        }

        try {
            $audioUrl = oss_presigned_url($ossConfig, $objectKey, 600);
            $audioFormat = asr_format_from_mime((string)($rec['audio_mime_type'] ?? 'audio/webm'));
            echo "  → 转写录音 #{$recId} (format={$audioFormat})...";
            $text = aliyun_asr_recognize($apiKey, $audioUrl, $audioFormat);
            $transcripts[] = ['id' => $recId, 'text' => $text, 'status' => 'completed'];
            $up = $pdo->prepare('UPDATE candidate_interview_recordings SET transcript_status="completed", transcript_text=?, updated_at=NOW() WHERE id=?');
            $up->execute([$text, $recId]);
            echo " ✓ (" . mb_strlen($text) . "字)\n";
        } catch (Throwable $e) {
            $transcripts[] = ['id' => $recId, 'text' => '', 'status' => 'failed'];
            $up = $pdo->prepare('UPDATE candidate_interview_recordings SET transcript_status="failed", updated_at=NOW() WHERE id=?');
            $up->execute([$recId]);
            echo " ✗ ({$e->getMessage()})\n";
        }
    }

    // 4. LLM 生成报告
    $jobStmt = $pdo->prepare('SELECT * FROM hr_jobs WHERE id=? LIMIT 1');
    $jobStmt->execute([(int)($session['job_id'] ?? 0)]);
    $job = $jobStmt->fetch();

    $qaTexts = [];
    foreach ($recordings as $i => $rec) {
        $t = '';
        foreach ($transcripts as $tr) {
            if ((int)$tr['id'] === (int)$rec['id'] && ($tr['text'] ?? '') !== '') {
                $t = $tr['text'];
                break;
            }
        }
        $qaTexts[] = 'Q' . ((int)$rec['sort_order'] ?: ($i + 1)) . '：' . ($rec['question_text'] ?? '面试问题')
            . "\n" . '（语音回答，时长' . ((int)($rec['audio_seconds'] ?? 0)) . '秒）'
            . ($t !== '' ? "\n转写内容：" . $t : "\n（转写未完成，仅根据题目和回答时长评估）");
    }

    $summary = '候选人已完成语音初面，共保存 ' . count($recordings) . ' 段语音回答。';
    $basicScore = null;
    $matchScore = null;
    $keywords = '';
    $concerns = '';
    $recommendation = 'hold';
    $rawResponse = null;

    $messages = [
        ['role' => 'system', 'content' => '你是招聘初面报告整理助手。你只整理候选人回答线索，不做录用决定。必须返回JSON对象。'],
        ['role' => 'user', 'content' => implode("\n", [
            '请根据岗位信息和候选人线上初面问答，生成给HR复核用的初面报告。',
            '要求：',
            '1. 不向候选人展示匹配分、推进建议或淘汰判断。',
            '2. 分数只作为HR复核线索，不代表自动决策。',
            '3. 重点识别真实经历、岗位匹配线索、表达不清但可能有经验的部分、需要人工复核的问题。',
            '4. 即使候选人回答简短或转写不完整，也必须基于已有信息给出初步评估——分数可以偏低，但不可省略。',
            '5. recommendation只能是continue、hold、reject之一。',
            '6. 如果候选人回答很简短或内容很少，应在concerns中明确指出，并在summary中如实说明。',
            '7. 返回格式：{"summary":"摘要","basic_score":80,"match_score":75,"keywords":"关键词1,关键词2","concerns":"需要HR复核的点","recommendation":"hold"}',
            '',
            '岗位信息：',
            '公司：' . ($job['company_name'] ?? '未知'),
            '岗位：' . ($job['job_title'] ?? '未知'),
            '薪资：' . ($job['salary_min_k'] ?? '-') . '-' . ($job['salary_max_k'] ?? '-') . 'K',
            '福利：' . ($job['benefits'] ?? '未填写'),
            '职责：' . ($job['responsibilities'] ?? '未填写'),
            '要求：' . ($job['requirements'] ?? '未填写'),
            '',
            '候选人问答：',
            implode("\n\n", $qaTexts),
        ])],
    ];

    try {
        echo "  → 生成AI报告...";
        $response = dashscope_chat($llmConfig, $messages);
        $content = dashscope_message_content($response);
        $parsed = parse_llm_json_object($content);
        $summary = ai_report_text_value($parsed['summary'] ?? null, $summary);
        $basicScore = isset($parsed['basic_score']) ? max(0, min(100, (int)$parsed['basic_score'])) : null;
        $matchScore = isset($parsed['match_score']) ? max(0, min(100, (int)$parsed['match_score'])) : null;
        $keywords = ai_report_keywords_value($parsed['keywords'] ?? '');
        $concerns = ai_report_text_value($parsed['concerns'] ?? null, '');
        $recommendation = ai_report_recommendation_value($parsed['recommendation'] ?? null, $recommendation);
        $rawResponse = json_encode($response, JSON_UNESCAPED_UNICODE);
        echo " ✓ (basic={$basicScore}, match={$matchScore}, rec={$recommendation})\n";
    } catch (Throwable $e) {
        $concerns = 'AI报告生成失败，请HR先查看录音和题目记录。';
        $rawResponse = json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        echo " ✗ ({$e->getMessage()})\n";
    }

    // 5. 写入报告
    if (table_exists($pdo, 'candidate_interview_reports')) {
        $report = $pdo->prepare('INSERT INTO candidate_interview_reports(session_id, candidate_id, hr_id, job_id, status, summary, basic_score, match_score, keywords, concerns, recommendation, raw_response, created_at, updated_at)
            VALUES(?,?,?,?, "ready", ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE status="ready", summary=VALUES(summary), basic_score=VALUES(basic_score), match_score=VALUES(match_score), keywords=VALUES(keywords), concerns=VALUES(concerns), recommendation=VALUES(recommendation), raw_response=VALUES(raw_response), updated_at=NOW()');
        $report->execute([
            $sessionId,
            (int)$session['candidate_id'],
            (int)($session['hr_id'] ?? 0),
            (int)($session['job_id'] ?? 0),
            $summary,
            $basicScore,
            $matchScore,
            $keywords,
            $concerns,
            $recommendation,
            $rawResponse,
        ]);
    }

    $processed++;
    echo "\n";
}

echo "\n=== 处理完成 ===\n";
echo "成功: {$processed}\n";
echo "失败: {$failed}\n";
