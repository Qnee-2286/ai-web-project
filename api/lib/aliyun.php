<?php
function aliyun_percent_encode(string $str): string
{
    return str_replace(['+', '*', '%7E'], ['%20', '%2A', '~'], rawurlencode($str));
}

function aliyun_rpc_request(array $config, string $endpoint, array $params): array
{
    $accessKeyId = $config['access_key_id'] ?? '';
    $accessKeySecret = $config['access_key_secret'] ?? '';
    if ($accessKeyId === '' || $accessKeySecret === '') {
        throw new RuntimeException('Aliyun AccessKey is not configured');
    }
    $public = [
        'Format' => 'JSON',
        'AccessKeyId' => $accessKeyId,
        'SignatureMethod' => 'HMAC-SHA1',
        'SignatureVersion' => '1.0',
        'SignatureNonce' => bin2hex(random_bytes(16)),
        'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    $params = array_merge($public, $params);
    ksort($params);
    $canonical = [];
    foreach ($params as $k => $v) {
        $canonical[] = aliyun_percent_encode((string)$k) . '=' . aliyun_percent_encode((string)$v);
    }
    $stringToSign = 'GET&%2F&' . aliyun_percent_encode(implode('&', $canonical));
    $params['Signature'] = base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));
    $url = 'https://' . $endpoint . '/?' . http_build_query($params);
    $context = stream_context_create(['http' => ['timeout' => 8]]);
    $raw = file_get_contents($url, false, $context);
    if ($raw === false) {
        throw new RuntimeException('Aliyun request failed');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Aliyun response is invalid');
    }
    return $data;
}

function send_sms_via_aliyun(array $sms, string $phone, string $code): void
{
    $result = aliyun_rpc_request($sms, 'dysmsapi.aliyuncs.com', [
        'Action' => 'SendSms',
        'Version' => '2017-05-25',
        'RegionId' => $sms['region_id'] ?? 'cn-hangzhou',
        'PhoneNumbers' => $phone,
        'SignName' => $sms['sign_name'] ?? '',
        'TemplateCode' => $sms['template_code'] ?? '',
        'TemplateParam' => json_encode(['code' => $code], JSON_UNESCAPED_UNICODE),
    ]);
    if (($result['Code'] ?? '') !== 'OK') {
        throw new RuntimeException('SMS send failed: ' . ($result['Message'] ?? 'unknown'));
    }
}

/**
 * 将数据库存的 audio_mime_type 转为 DashScope ASR 的 format 参数
 */
function asr_format_from_mime(string $mime): string
{
    $map = [
        'audio/webm' => 'webm',
        'audio/mp4'  => 'm4a',
        'video/mp4'  => 'm4a',
        'audio/mpeg' => 'mp3',
        'audio/ogg'  => 'ogg',
        'audio/wav'  => 'wav',
        'audio/x-wav'=> 'wav',
    ];
    $base = strtolower(trim(explode(';', $mime)[0]));
    return $map[$base] ?? 'webm'; // 默认 webm（前端主流格式）
}

/**
 * 阿里云 DashScope 语音转写（文件转写 API，异步模式）
 * 支持 wav / mp3 / m4a / ogg / webm 格式，单文件最长 5 分钟
 *
 * 流程：提交异步任务 → 轮询等待完成 → 获取转写结果URL → 解析文本
 *
 * @param string $apiKey  百炼平台 DashScope API Key
 * @param string $audioUrl 录音文件的公网可访问 URL（OSS 签名 URL）
 * @param string $format  音频格式（wav/mp3/m4a/ogg/webm）
 * @return string 转写文本
 */
function aliyun_asr_recognize(string $apiKey, string $audioUrl, string $format = 'webm'): string
{
    if ($apiKey === '') {
        throw new RuntimeException('DashScope API Key 未配置，无法进行语音转写');
    }

    // 第1步：提交异步转写任务
    $payload = [
        'model' => 'paraformer-v2',
        'input' => [
            'file_urls' => [$audioUrl],
        ],
        'parameters' => [
            'format' => $format,
        ],
    ];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init('https://dashscope.aliyuncs.com/api/v1/services/audio/asr/transcription');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'X-DashScope-Async: enable',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('ASR 提交失败: ' . ($curlErr ?: 'unknown'));
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('ASR 提交返回格式异常: ' . mb_substr($raw, 0, 300));
    }

    // 检查提交错误
    $code = (string)($data['code'] ?? '');
    if ($code !== '' && $code !== '200') {
        $msg = $data['message'] ?? 'unknown error';
        throw new RuntimeException("ASR 提交错误[{$code}]: {$msg}");
    }

    $taskId = $data['output']['task_id'] ?? '';
    if ($taskId === '') {
        throw new RuntimeException('ASR 提交成功但未获得 task_id');
    }

    // 第2步：轮询等待任务完成（最长等120秒）
    $maxAttempts = 40;
    $interval = 3;
    $result = null;

    for ($i = 0; $i < $maxAttempts; $i++) {
        sleep($interval);

        $ch = curl_init("https://dashscope.aliyuncs.com/api/v1/tasks/{$taskId}");
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $pollRaw = curl_exec($ch);
        curl_close($ch);

        if ($pollRaw === false) {
            continue;
        }

        $pollData = json_decode($pollRaw, true);
        if (!is_array($pollData)) {
            continue;
        }

        $status = $pollData['output']['task_status'] ?? '';

        if ($status === 'SUCCEEDED') {
            $result = $pollData;
            break;
        } elseif ($status === 'FAILED') {
            $msg = $pollData['output']['message'] ?? 'unknown';
            throw new RuntimeException("ASR 异步任务失败: {$msg}");
        }
        // PENDING / RUNNING 继续等
    }

    if ($result === null) {
        throw new RuntimeException('ASR 转写超时（120秒未完成）');
    }

    // 第3步：从结果中获取转写文本的 URL 并下载
    $transcriptionUrl = $result['output']['results'][0]['transcription_url'] ?? '';
    if ($transcriptionUrl === '') {
        // 兜底：某些版本直接在 output 里返回
        $text = $result['output']['results'][0]['transcription']['text'] ?? '';
        if ($text !== '') {
            return (string)$text;
        }
        throw new RuntimeException('ASR 成功但未返回转写结果');
    }

    $ch = curl_init($transcriptionUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $transRaw = curl_exec($ch);
    curl_close($ch);

    if ($transRaw === false) {
        throw new RuntimeException('获取转写结果文件失败');
    }

    $transData = json_decode($transRaw, true);
    if (!is_array($transData)) {
        throw new RuntimeException('转写结果文件格式异常');
    }

    // 解析转写文本
    $text = '';
    if (isset($transData['transcripts'][0]['text'])) {
        $text = (string)$transData['transcripts'][0]['text'];
    }

    return $text;
}

function send_email_via_aliyun_directmail(array $email, string $to, string $code): void
{
    $subject = $email['subject'] ?? 'Hongze Digital verification code';
    $htmlBody = '<p>Your verification code is: <strong>' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</strong></p><p>The code is valid for 10 minutes.</p>';
    $result = aliyun_rpc_request($email, 'dm.aliyuncs.com', [
        'Action' => 'SingleSendMail',
        'Version' => '2015-11-23',
        'RegionId' => $email['region_id'] ?? 'cn-hangzhou',
        'AccountName' => $email['account_name'] ?? '',
        'FromAlias' => $email['from_alias'] ?? 'Hongze Digital',
        'AddressType' => '1',
        'ReplyToAddress' => 'true',
        'ToAddress' => $to,
        'Subject' => $subject,
        'HtmlBody' => $htmlBody,
    ]);
    if (!empty($result['Code'])) {
        throw new RuntimeException('Email send failed: ' . ($result['Message'] ?? $result['Code']));
    }
}
