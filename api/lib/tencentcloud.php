<?php
function tencent_sha256_hex(string $payload): string
{
    return hash('sha256', $payload);
}

function tencent_hmac_sha256(string $key, string $message): string
{
    return hash_hmac('sha256', $message, $key, true);
}

function tencent_cloud_request(array $realname, string $action, array $payload): array
{
    $secretId = $realname['secret_id'] ?? '';
    $secretKey = $realname['secret_key'] ?? '';
    if ($secretId === '' || $secretKey === '') {
        throw new RuntimeException('Tencent Cloud SecretId or SecretKey is not configured');
    }

    $host = 'faceid.tencentcloudapi.com';
    $service = 'faceid';
    $version = '2018-03-01';
    $timestamp = time();
    $date = gmdate('Y-m-d', $timestamp);
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $canonicalHeaders = "content-type:application/json; charset=utf-8\nhost:{$host}\nx-tc-action:" . strtolower($action) . "\n";
    $signedHeaders = 'content-type;host;x-tc-action';
    $canonicalRequest = "POST\n/\n\n{$canonicalHeaders}\n{$signedHeaders}\n" . tencent_sha256_hex($body);

    $credentialScope = "{$date}/{$service}/tc3_request";
    $stringToSign = "TC3-HMAC-SHA256\n{$timestamp}\n{$credentialScope}\n" . tencent_sha256_hex($canonicalRequest);
    $secretDate = tencent_hmac_sha256('TC3' . $secretKey, $date);
    $secretService = tencent_hmac_sha256($secretDate, $service);
    $secretSigning = tencent_hmac_sha256($secretService, 'tc3_request');
    $signature = hash_hmac('sha256', $stringToSign, $secretSigning);
    $authorization = "TC3-HMAC-SHA256 Credential={$secretId}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

    $headers = [
        'Authorization: ' . $authorization,
        'Content-Type: application/json; charset=utf-8',
        'Host: ' . $host,
        'X-TC-Action: ' . $action,
        'X-TC-Timestamp: ' . $timestamp,
        'X-TC-Version: ' . $version,
    ];

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ]);
    $raw = file_get_contents('https://' . $host, false, $context);
    if ($raw === false) {
        throw new RuntimeException('Tencent Cloud request failed');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Tencent Cloud response is invalid');
    }
    $response = $data['Response'] ?? [];
    if (isset($response['Error'])) {
        $code = $response['Error']['Code'] ?? 'Unknown';
        $message = $response['Error']['Message'] ?? 'Tencent Cloud API error';
        throw new RuntimeException($code . ': ' . $message);
    }
    return $response;
}

function tencent_detect_auth(array $realname, string $name, string $idCard, string $redirectUrl, string $extra): array
{
    return tencent_cloud_request($realname, 'DetectAuth', [
        'RuleId' => (string)($realname['rule_id'] ?? ''),
        'IdCard' => $idCard,
        'Name' => $name,
        'RedirectUrl' => $redirectUrl,
        'Extra' => $extra,
    ]);
}

function tencent_get_detect_info(array $realname, string $bizToken): array
{
    return tencent_cloud_request($realname, 'GetDetectInfoEnhanced', [
        'RuleId' => (string)($realname['rule_id'] ?? ''),
        'BizToken' => $bizToken,
        'InfoType' => '1',
    ]);
}
