<?php
function oss_is_configured(array $oss): bool
{
    return ($oss['provider'] ?? '') === 'aliyun'
        && trim((string)($oss['endpoint'] ?? '')) !== ''
        && trim((string)($oss['bucket'] ?? '')) !== ''
        && trim((string)($oss['access_key_id'] ?? '')) !== ''
        && trim((string)($oss['access_key_secret'] ?? '')) !== '';
}

function oss_object_url(array $oss, string $objectKey): string
{
    $encoded = implode('/', array_map('rawurlencode', explode('/', ltrim($objectKey, '/'))));
    return 'https://' . $oss['bucket'] . '.' . $oss['endpoint'] . '/' . $encoded;
}

function oss_authorization(array $oss, string $method, string $objectKey, string $date, string $contentType = ''): string
{
    $resource = '/' . $oss['bucket'] . '/' . ltrim($objectKey, '/');
    $toSign = $method . "\n\n" . $contentType . "\n" . $date . "\n" . $resource;
    $signature = base64_encode(hash_hmac('sha1', $toSign, $oss['access_key_secret'], true));
    return 'OSS ' . $oss['access_key_id'] . ':' . $signature;
}

function oss_put_file(array $oss, string $objectKey, string $localPath, string $contentType): array
{
    if (!oss_is_configured($oss)) {
        throw new RuntimeException('OSS 录音存储尚未配置');
    }
    if (!is_file($localPath)) {
        throw new RuntimeException('待上传录音文件不存在');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('服务器未启用 cURL，无法上传录音文件');
    }

    $date = gmdate('D, d M Y H:i:s \G\M\T');
    $handle = fopen($localPath, 'rb');
    if ($handle === false) {
        throw new RuntimeException('录音文件读取失败');
    }
    $headers = [
        'Date: ' . $date,
        'Content-Type: ' . $contentType,
        'Authorization: ' . oss_authorization($oss, 'PUT', $objectKey, $date, $contentType),
    ];
    $curl = curl_init(oss_object_url($oss, $objectKey));
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_UPLOAD => true,
        CURLOPT_INFILE => $handle,
        CURLOPT_INFILESIZE => filesize($localPath),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    fclose($handle);

    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('录音保存失败，请稍后重试' . ($error !== '' ? '：' . $error : ''));
    }

    $etag = '';
    if (preg_match('/\r\nETag:\s*"?([^"\r\n]+)"?/i', (string)$response, $matches)) {
        $etag = $matches[1];
    }
    return ['object_key' => $objectKey, 'etag' => $etag];
}

/**
 * 生成 OSS 预签名 URL（GET 请求，临时访问链接）
 * @param array $oss OSS 配置
 * @param string $objectKey 对象 key
 * @param int $expires 过期时间（秒），默认 1800（30 分钟）
 * @return string 预签名 URL
 */
function oss_presigned_url(array $oss, string $objectKey, int $expires = 1800): string
{
    if (!oss_is_configured($oss)) {
        throw new RuntimeException('OSS 尚未配置');
    }
    $expiresTs = time() + $expires;
    $resource = '/' . $oss['bucket'] . '/' . ltrim($objectKey, '/');
    $toSign = "GET\n\n\n{$expiresTs}\n{$resource}";
    $signature = base64_encode(hash_hmac('sha1', $toSign, $oss['access_key_secret'], true));
    $params = http_build_query([
        'OSSAccessKeyId' => $oss['access_key_id'],
        'Expires' => $expiresTs,
        'Signature' => $signature,
    ]);
    return oss_object_url($oss, $objectKey) . '?' . $params;
}

function oss_delete_object(array $oss, string $objectKey): void
{
    if (!oss_is_configured($oss) || trim($objectKey) === '' || !function_exists('curl_init')) {
        return;
    }
    $date = gmdate('D, d M Y H:i:s \G\M\T');
    $curl = curl_init(oss_object_url($oss, $objectKey));
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_HTTPHEADER => [
            'Date: ' . $date,
            'Authorization: ' . oss_authorization($oss, 'DELETE', $objectKey, $date),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    curl_exec($curl);
    curl_close($curl);
}
