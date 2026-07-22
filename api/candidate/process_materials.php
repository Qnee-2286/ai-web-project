<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

if (function_exists('set_time_limit')) {
    set_time_limit(600);
}

function material_update(PDO $pdo, int $sessionId, array $fields): void
{
    $sets = [];
    $values = [];
    foreach ($fields as $key => $value) {
        $sets[] = $key . '=?';
        $values[] = $value;
    }
    $sets[] = 'updated_at=NOW()';
    $values[] = $sessionId;
    $stmt = $pdo->prepare('UPDATE candidate_interview_materials SET ' . implode(',', $sets) . ' WHERE session_id=?');
    $stmt->execute($values);
}

function ffmpeg_path(): string
{
    $path = trim((string)@shell_exec('command -v ffmpeg 2>/dev/null'));
    if ($path !== '') {
        return $path;
    }
    $check = trim((string)@shell_exec('ffmpeg -version 2>/dev/null'));
    return $check !== '' ? 'ffmpeg' : '';
}

function download_to_file(string $url, string $target): void
{
    $ch = curl_init($url);
    $fp = fopen($target, 'wb');
    if ($ch === false || $fp === false) {
        throw new RuntimeException('创建临时录音文件失败');
    }
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($fp);
    if ($ok === false || $status >= 400 || !is_file($target) || filesize($target) <= 0) {
        @unlink($target);
        throw new RuntimeException('下载录音文件失败' . ($error ? '：' . $error : ''));
    }
}

function merge_recordings(PDO $pdo, array $config, int $sessionId, int $candidateId, array $recordings): array
{
    $oss = $config['oss'] ?? [];
    if (!oss_is_configured($oss)) {
        throw new RuntimeException('OSS尚未配置，无法生成整段录音');
    }

    $ffmpeg = ffmpeg_path();
    if ($ffmpeg === '') {
        material_update($pdo, $sessionId, [
            'merge_status' => 'unsupported',
            'error_message' => '服务器未安装 ffmpeg，暂不能合并整段录音。',
        ]);
        return ['status' => 'unsupported', 'url' => ''];
    }

    $tmpDir = sys_get_temp_dir() . '/hi_interview_' . $sessionId . '_' . bin2hex(random_bytes(4));
    if (!mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
        throw new RuntimeException('创建录音整理目录失败');
    }

    $inputArgs = [];
    $filterParts = [];
    $concatInputs = '';
    try {
        foreach ($recordings as $idx => $rec) {
            $objectKey = trim((string)($rec['audio_object_key'] ?? ''));
            if ($objectKey === '') {
                continue;
            }
            $local = $tmpDir . '/answer_' . $idx . '.bin';
            download_to_file(oss_presigned_url($oss, $objectKey, 900), $local);
            $inputArgs[] = '-i ' . escapeshellarg($local);
            $filterParts[] = '[' . $idx . ':a]aresample=16000,pan=mono|c0=c0[a' . $idx . ']';
            $concatInputs .= '[a' . $idx . ']';
        }

        $count = count($inputArgs);
        if ($count === 0) {
            throw new RuntimeException('没有可合并的录音文件');
        }

        $output = $tmpDir . '/interview_answers.m4a';
        $filter = implode(';', $filterParts) . ';' . $concatInputs . 'concat=n=' . $count . ':v=0:a=1[out]';
        $cmd = escapeshellcmd($ffmpeg)
            . ' -y ' . implode(' ', $inputArgs)
            . ' -filter_complex ' . escapeshellarg($filter)
            . ' -map ' . escapeshellarg('[out]')
            . ' -c:a aac -b:a 96k ' . escapeshellarg($output)
            . ' 2>&1';
        $lines = [];
        $exit = 0;
        exec($cmd, $lines, $exit);
        if ($exit !== 0 || !is_file($output) || filesize($output) <= 0) {
            throw new RuntimeException('录音合并失败：' . mb_substr(implode("\n", $lines), 0, 800, 'UTF-8'));
        }

        $prefix = trim((string)($oss['prefix'] ?? 'interview-audio/'), '/') . '/';
        $objectKey = $prefix . 'merged/session-' . $sessionId . '-' . date('YmdHis') . '.m4a';
        oss_put_file($oss, $objectKey, $output, 'audio/mp4');

        material_update($pdo, $sessionId, [
            'merged_audio_object_key' => $objectKey,
            'merged_audio_mime_type' => 'audio/mp4',
            'merged_audio_size' => filesize($output),
            'merge_status' => 'completed',
            'error_message' => null,
        ]);

        return ['status' => 'completed', 'url' => oss_presigned_url($oss, $objectKey, 86400)];
    } finally {
        foreach (glob($tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($tmpDir);
    }
}

function build_report(PDO $pdo, array $config, array $candidate, array $session, array $recordings, array $transcripts): void
{
    if (!table_exists($pdo, 'candidate_interview_reports')) {
        return;
    }

    $llm = $config['llm'] ?? [];
    $apiKey = trim((string)($llm['api_key'] ?? ''));
    $summary = '候选人已完成语音初面，共保存' . count($recordings) . '段语音回答。';
    $basicScore = null;
    $matchScore = null;
    $keywords = '';
    $concerns = '';
    $recommendation = 'hold';
    $rawResponse = null;

    $jobStmt = $pdo->prepare('SELECT * FROM hr_jobs WHERE id=? LIMIT 1');
    $jobStmt->execute([(int)($session['job_id'] ?? 0)]);
    $job = $jobStmt->fetch() ?: [];

    $qaTexts = [];
    foreach ($recordings as $i => $rec) {
        $rid = (int)$rec['id'];
        $text = trim((string)($transcripts[$rid] ?? ''));
        $qaTexts[] = 'Q' . ((int)$rec['sort_order'] ?: ($i + 1)) . '：' . ($rec['question_text'] ?? '面试问题')
            . "\n候选人回答转写：" . ($text !== '' ? $text : '转写未完成或为空')
            . "\n录音时长：" . ((int)($rec['audio_seconds'] ?? 0)) . '秒';
    }

    if ($apiKey !== '' && $apiKey !== '你的百炼API Key') {
        $messages = [
            [
                'role' => 'system',
                'content' => '你是招聘初面报告整理助手。只整理候选人回答线索，不做录用决定。必须返回JSON对象。',
            ],
            [
                'role' => 'user',
                'content' => implode("\n", [
                    '请根据岗位信息和候选人线上初面问答，生成给HR复核用的初面报告。',
                    '要求：',
                    '1. 分数仅作为HR复核线索，不代表自动决策。',
                    '2. 重点识别真实经历、岗位匹配线索、需要人工复核的问题。',
                    '3. recommendation只能是continue、hold、reject之一。',
                    '4. 返回格式：{"summary":"摘要","basic_score":80,"match_score":75,"keywords":"关键词","concerns":"复核点","recommendation":"hold"}',
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
                ]),
            ],
        ];

        try {
            $response = dashscope_chat($llm, $messages);
            $content = dashscope_message_content($response);
            $parsed = parse_llm_json_object($content);
            $summary = ai_report_text_value($parsed['summary'] ?? null, $summary);
            $basicScore = isset($parsed['basic_score']) ? max(0, min(100, (int)$parsed['basic_score'])) : null;
            $matchScore = isset($parsed['match_score']) ? max(0, min(100, (int)$parsed['match_score'])) : null;
            $keywords = ai_report_keywords_value($parsed['keywords'] ?? '');
            $concerns = ai_report_text_value($parsed['concerns'] ?? null, '');
            $recommendation = ai_report_recommendation_value($parsed['recommendation'] ?? null, $recommendation);
            $rawResponse = json_encode($response, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            $concerns = 'AI报告生成失败，请HR先查看录音和转写内容。错误：' . $e->getMessage();
            $rawResponse = json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    } else {
        $concerns = '系统未配置AI分析服务，请HR直接查看录音和转写内容。';
    }

    $report = $pdo->prepare('INSERT INTO candidate_interview_reports(session_id, candidate_id, hr_id, job_id, status, summary, basic_score, match_score, keywords, concerns, recommendation, raw_response, created_at, updated_at)
        VALUES(?,?,?,?, "ready", ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE status="ready", summary=VALUES(summary), basic_score=VALUES(basic_score), match_score=VALUES(match_score), keywords=VALUES(keywords), concerns=VALUES(concerns), recommendation=VALUES(recommendation), raw_response=VALUES(raw_response), updated_at=NOW()');
    $report->execute([
        (int)$session['id'],
        (int)$candidate['id'],
        (int)($candidate['hr_id'] ?? $session['hr_id'] ?? 0),
        (int)($candidate['job_id'] ?? $session['job_id'] ?? 0),
        $summary,
        $basicScore,
        $matchScore,
        $keywords,
        $concerns,
        $recommendation,
        $rawResponse,
    ]);
}

$input = json_input();
$token = trim((string)($input['token'] ?? ($_SESSION['candidate_token'] ?? '')));
$sessionId = (int)($input['session_id'] ?? 0);

$candidate = candidate_by_token($pdo, $token);
if (!$candidate) {
    respond(false, '候选人链接无效，请从HR发送的邀请链接重新进入', [], 401);
}
if ($sessionId <= 0) {
    respond(false, '面试会话无效', [], 422);
}
if (!table_exists($pdo, 'candidate_interview_materials')) {
    respond(false, '面试材料表未初始化，请先导入 upgrade_20260530_candidate_materials.sql', [], 500);
}

$sessionStmt = $pdo->prepare('SELECT * FROM candidate_interview_sessions WHERE id=? AND candidate_id=? LIMIT 1');
$sessionStmt->execute([$sessionId, (int)$candidate['id']]);
$session = $sessionStmt->fetch();
if (!$session) {
    respond(false, '当前面试会话不存在', [], 404);
}

$insertMaterial = $pdo->prepare('INSERT INTO candidate_interview_materials(session_id, candidate_id, created_at, updated_at)
    VALUES(?, ?, NOW(), NOW())
    ON DUPLICATE KEY UPDATE updated_at=NOW()');
$insertMaterial->execute([$sessionId, (int)$candidate['id']]);

ensure_interview_recordings_schema($pdo);
$materialStmt = $pdo->prepare('SELECT * FROM candidate_interview_materials WHERE session_id=? LIMIT 1');
$materialStmt->execute([$sessionId]);
$material = $materialStmt->fetch() ?: [];

$recordingStmt = $pdo->prepare('SELECT id, question_text, question_type, sort_order, audio_object_key, audio_seconds, audio_mime_type, transcript_status, transcript_text FROM candidate_interview_recordings WHERE session_id=? AND candidate_id=? ORDER BY sort_order ASC');
$recordingStmt->execute([$sessionId, (int)$candidate['id']]);
$recordings = $recordingStmt->fetchAll();
if (count($recordings) === 0) {
    respond(false, '还没有保存任何语音回答，不能整理面试材料', [], 422);
}

try {
    if (($material['merge_status'] ?? 'pending') !== 'completed' && ($material['merge_status'] ?? '') !== 'unsupported') {
        material_update($pdo, $sessionId, ['merge_status' => 'processing']);
        merge_recordings($pdo, $config, $sessionId, (int)$candidate['id'], $recordings);
    }
} catch (Throwable $e) {
    material_update($pdo, $sessionId, ['merge_status' => 'failed', 'error_message' => $e->getMessage()]);
}

$transcripts = [];
$asrOk = true;
try {
    material_update($pdo, $sessionId, ['transcript_status' => 'processing']);
    $oss = $config['oss'] ?? [];
    $apiKey = trim((string)($config['llm']['api_key'] ?? ''));
    if (!oss_is_configured($oss) || $apiKey === '') {
        throw new RuntimeException('OSS或百炼API未配置，暂不能转写录音');
    }
    foreach ($recordings as $rec) {
        $rid = (int)$rec['id'];
        if (($rec['transcript_status'] ?? '') === 'completed' && trim((string)($rec['transcript_text'] ?? '')) !== '') {
            $transcripts[$rid] = (string)$rec['transcript_text'];
            continue;
        }
        try {
            $audioUrl = oss_presigned_url($oss, (string)$rec['audio_object_key'], 900);
            $format = asr_format_from_mime((string)($rec['audio_mime_type'] ?? 'audio/webm'));
            $text = aliyun_asr_recognize($apiKey, $audioUrl, $format);
            $transcripts[$rid] = $text;
            $up = $pdo->prepare('UPDATE candidate_interview_recordings SET transcript_status="completed", transcript_text=?, updated_at=NOW() WHERE id=?');
            $up->execute([$text, $rid]);
        } catch (Throwable $e) {
            $asrOk = false;
            $up = $pdo->prepare('UPDATE candidate_interview_recordings SET transcript_status="failed", updated_at=NOW() WHERE id=?');
            $up->execute([$rid]);
            $transcripts[$rid] = '';
        }
    }
    material_update($pdo, $sessionId, ['transcript_status' => $asrOk ? 'completed' : 'failed']);
} catch (Throwable $e) {
    material_update($pdo, $sessionId, ['transcript_status' => 'failed', 'error_message' => $e->getMessage()]);
}

try {
    material_update($pdo, $sessionId, ['report_status' => 'processing']);
    build_report($pdo, $config, $candidate, $session, $recordings, $transcripts);
    material_update($pdo, $sessionId, ['report_status' => 'ready']);
} catch (Throwable $e) {
    material_update($pdo, $sessionId, ['report_status' => 'failed', 'error_message' => $e->getMessage()]);
}

$materialStmt->execute([$sessionId]);
$material = $materialStmt->fetch() ?: [];
$done = in_array(($material['merge_status'] ?? ''), ['completed', 'unsupported', 'failed'], true)
    && in_array(($material['transcript_status'] ?? ''), ['completed', 'failed'], true)
    && in_array(($material['report_status'] ?? ''), ['ready', 'failed'], true);

respond(true, $done ? '面试材料已整理完成' : '面试材料正在整理中', [
    'done' => $done,
    'session_id' => $sessionId,
    'merge_status' => $material['merge_status'] ?? 'pending',
    'transcript_status' => $material['transcript_status'] ?? 'pending',
    'report_status' => $material['report_status'] ?? 'pending',
    'next' => 'complete.html?token=' . urlencode($token) . '&session_id=' . $sessionId,
]);
