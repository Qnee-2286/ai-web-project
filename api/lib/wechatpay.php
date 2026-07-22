<?php

function wechatpay_config(array $config): array
{
    $pay = $config['payment']['wechat'] ?? [];
    $required = ['appid', 'mchid', 'merchant_serial_no', 'merchant_private_key', 'api_v3_key', 'notify_url'];
    foreach ($required as $key) {
        if (empty($pay[$key])) {
            throw new RuntimeException('微信支付未配置: payment.wechat.' . $key);
        }
    }
    $pay['gateway'] = rtrim($pay['gateway'] ?? 'https://api.mch.weixin.qq.com', '/');
    return $pay;
}

function wechatpay_private_key(string $key)
{
    $key = trim($key);
    $looksLikePemPath = strpos($key, '/') === 0 || strpos($key, '\\') !== false || preg_match('/\.pem$/i', $key);
    $originalPath = $key;
    if (@is_file($key)) {
        $content = @file_get_contents($key);
        if ($content === false) {
            throw new RuntimeException('微信支付商户私钥文件无法读取: ' . $key);
        }
        $key = $content;
    } elseif ($looksLikePemPath) {
        $local = dirname(__DIR__, 2) . '/private_uploads/payment/' . basename($originalPath);
        if (@is_file($local) && @is_readable($local)) {
            $content = @file_get_contents($local);
            if ($content === false) {
                throw new RuntimeException('微信支付商户私钥文件无法读取: ' . $local);
            }
            $key = $content;
        } else {
            throw new RuntimeException('微信支付商户私钥文件不存在或无权限读取: ' . $originalPath . '；请上传到 ' . $local);
        }
    }
    return openssl_pkey_get_private($key);
}

function wechatpay_request(array $config, string $method, string $path, array $body): array
{
    $pay = wechatpay_config($config);
    $bodyJson = json_encode($body, JSON_UNESCAPED_UNICODE);
    $timestamp = (string)time();
    $nonce = bin2hex(random_bytes(16));
    $message = strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $bodyJson . "\n";
    $privateKey = wechatpay_private_key($pay['merchant_private_key']);
    if (!$privateKey) {
        throw new RuntimeException('微信支付商户私钥不可用');
    }
    $signature = '';
    openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    $authorization = 'WECHATPAY2-SHA256-RSA2048 mchid="' . $pay['mchid']
        . '",nonce_str="' . $nonce
        . '",signature="' . base64_encode($signature)
        . '",timestamp="' . $timestamp
        . '",serial_no="' . $pay['merchant_serial_no'] . '"';

    $ch = curl_init($pay['gateway'] . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_POSTFIELDS => $bodyJson,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $authorization,
            'Accept: application/json',
            'Content-Type: application/json; charset=utf-8',
            'User-Agent: hi-interview-payment/1.0',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('微信支付请求失败: ' . $error);
    }
    $data = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('微信支付下单失败: ' . ($data['message'] ?? $response));
    }
    return is_array($data) ? $data : [];
}

function wechatpay_create_native(array $config, array $order, array $plan): string
{
    $pay = wechatpay_config($config);
    $body = [
        'appid' => $pay['appid'],
        'mchid' => $pay['mchid'],
        'description' => mb_substr($plan['plan_name'], 0, 40, 'UTF-8'),
        'out_trade_no' => $order['order_no'],
        'notify_url' => $pay['notify_url'],
        'amount' => ['total' => (int)round((float)$order['amount'] * 100), 'currency' => 'CNY'],
    ];
    $data = wechatpay_request($config, 'POST', '/v3/pay/transactions/native', $body);
    if (empty($data['code_url'])) {
        throw new RuntimeException('微信支付未返回二维码链接');
    }
    return $data['code_url'];
}

function wechatpay_decrypt_notify(array $config, array $resource): array
{
    $pay = wechatpay_config($config);
    $ciphertext = base64_decode((string)($resource['ciphertext'] ?? ''), true);
    $nonce = (string)($resource['nonce'] ?? '');
    $associatedData = (string)($resource['associated_data'] ?? '');
    if ($ciphertext === false || $nonce === '') {
        throw new RuntimeException('微信支付回调数据不完整');
    }
    $tag = substr($ciphertext, -16);
    $ciphertext = substr($ciphertext, 0, -16);
    $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $pay['api_v3_key'], OPENSSL_RAW_DATA, $nonce, $tag, $associatedData);
    if ($plain === false) {
        throw new RuntimeException('微信支付回调解密失败');
    }
    $data = json_decode($plain, true);
    if (!is_array($data)) {
        throw new RuntimeException('微信支付回调 JSON 解析失败');
    }
    return $data;
}

function wechatpay_header(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return (string)($_SERVER[$key] ?? '');
}

function wechatpay_verify_notify_signature(array $config, string $rawBody): bool
{
    $pay = wechatpay_config($config);
    $publicKey = trim((string)($pay['platform_public_key'] ?? ''));
    if ($publicKey === '') {
        throw new RuntimeException('微信支付平台公钥未配置: payment.wechat.platform_public_key');
    }
    $looksLikePemPath = strpos($publicKey, '/') === 0 || strpos($publicKey, '\\') !== false || preg_match('/\.pem$/i', $publicKey);
    $originalPath = $publicKey;
    if (@is_file($publicKey)) {
        $content = @file_get_contents($publicKey);
        if ($content === false) {
            throw new RuntimeException('微信支付平台公钥文件无法读取: ' . $publicKey);
        }
        $publicKey = $content;
    } elseif ($looksLikePemPath) {
        $local = dirname(__DIR__, 2) . '/private_uploads/payment/' . basename($originalPath);
        if (@is_file($local) && @is_readable($local)) {
            $content = @file_get_contents($local);
            if ($content === false) {
                throw new RuntimeException('微信支付平台公钥文件无法读取: ' . $local);
            }
            $publicKey = $content;
        } else {
            throw new RuntimeException('微信支付平台公钥文件不存在或无权限读取: ' . $originalPath . '；请上传到 ' . $local);
        }
    }
    $serial = wechatpay_header('Wechatpay-Serial');
    $expectedSerial = (string)($pay['platform_serial_no'] ?? '');
    if ($expectedSerial !== '' && $serial !== '' && !hash_equals($expectedSerial, $serial)) {
        throw new RuntimeException('微信支付平台证书序列号不匹配');
    }
    $timestamp = wechatpay_header('Wechatpay-Timestamp');
    $nonce = wechatpay_header('Wechatpay-Nonce');
    $signature = wechatpay_header('Wechatpay-Signature');
    if ($timestamp === '' || $nonce === '' || $signature === '') {
        return false;
    }
    if (abs(time() - (int)$timestamp) > 300) {
        return false;
    }
    $message = $timestamp . "\n" . $nonce . "\n" . $rawBody . "\n";
    $key = openssl_pkey_get_public($publicKey);
    if (!$key) {
        throw new RuntimeException('微信支付平台公钥不可用');
    }
    return openssl_verify($message, base64_decode($signature), $key, OPENSSL_ALGO_SHA256) === 1;
}
