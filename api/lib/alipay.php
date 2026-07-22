<?php

function alipay_config(array $config): array
{
    $pay = $config['payment']['alipay'] ?? [];
    $required = ['app_id', 'merchant_private_key', 'alipay_public_key', 'notify_url', 'return_url'];
    foreach ($required as $key) {
        if (empty($pay[$key])) {
            throw new RuntimeException('支付宝支付未配置: payment.alipay.' . $key);
        }
    }
    $pay['gateway'] = $pay['gateway'] ?? 'https://openapi.alipay.com/gateway.do';
    return $pay;
}

function alipay_normalize_key(string $key, string $type): string
{
    $key = trim($key);
    $looksLikePemPath = strpos($key, '/') === 0 || strpos($key, '\\') !== false || preg_match('/\.pem$/i', $key);
    $originalPath = $key;
    if (@is_file($key)) {
        $content = @file_get_contents($key);
        if ($content === false) {
            throw new RuntimeException('支付宝密钥文件无法读取: ' . $key);
        }
        $key = trim((string)$content);
    } elseif ($looksLikePemPath) {
        $local = dirname(__DIR__, 2) . '/private_uploads/payment/' . basename($originalPath);
        if (@is_file($local) && @is_readable($local)) {
            $content = @file_get_contents($local);
            if ($content === false) {
                throw new RuntimeException('支付宝密钥文件无法读取: ' . $local);
            }
            $key = trim((string)$content);
        } else {
            throw new RuntimeException('支付宝密钥文件不存在或无权限读取: ' . $originalPath . '；请上传到 ' . $local);
        }
    }
    if (strpos($key, '-----BEGIN') === 0) {
        return $key;
    }
    $body = chunk_split(str_replace(["\r", "\n", ' '], '', $key), 64, "\n");
    if ($type === 'private') {
        return "-----BEGIN RSA PRIVATE KEY-----\n" . $body . "-----END RSA PRIVATE KEY-----";
    }
    return "-----BEGIN PUBLIC KEY-----\n" . $body . "-----END PUBLIC KEY-----";
}

function alipay_sign_string(array $params): string
{
    ksort($params, SORT_STRING);
    $parts = [];
    foreach ($params as $key => $value) {
        if ($key === 'sign' || $value === '' || $value === null) {
            continue;
        }
        $parts[] = $key . '=' . $value;
    }
    return implode('&', $parts);
}

function alipay_sign(array $params, string $privateKey): string
{
    $key = openssl_pkey_get_private(alipay_normalize_key($privateKey, 'private'));
    if (!$key) {
        throw new RuntimeException('支付宝应用私钥不可用');
    }
    $signature = '';
    openssl_sign(alipay_sign_string($params), $signature, $key, OPENSSL_ALGO_SHA256);
    return base64_encode($signature);
}

function alipay_verify(array $params, string $publicKey): bool
{
    $signature = (string)($params['sign'] ?? '');
    if ($signature === '') {
        return false;
    }
    $key = openssl_pkey_get_public(alipay_normalize_key($publicKey, 'public'));
    if (!$key) {
        throw new RuntimeException('支付宝公钥不可用');
    }
    return openssl_verify(alipay_sign_string($params), base64_decode($signature), $key, OPENSSL_ALGO_SHA256) === 1;
}

function alipay_page_pay_form(array $config, array $order, array $plan): string
{
    $pay = alipay_config($config);
    $bizContent = [
        'out_trade_no' => $order['order_no'],
        'total_amount' => number_format((float)$order['amount'], 2, '.', ''),
        'subject' => $plan['plan_name'],
        'product_code' => 'FAST_INSTANT_TRADE_PAY',
    ];
    $params = [
        'app_id' => $pay['app_id'],
        'method' => 'alipay.trade.page.pay',
        'format' => 'JSON',
        'charset' => 'utf-8',
        'sign_type' => 'RSA2',
        'timestamp' => date('Y-m-d H:i:s'),
        'version' => '1.0',
        'notify_url' => $pay['notify_url'],
        'return_url' => $pay['return_url'] . '?order_no=' . rawurlencode($order['order_no']),
        'biz_content' => json_encode($bizContent, JSON_UNESCAPED_UNICODE),
    ];
    $params['sign'] = alipay_sign($params, $pay['merchant_private_key']);

    $url = $pay['gateway'] . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    return '<!doctype html><html><head><meta charset="utf-8"><title>跳转支付宝支付</title></head><body>'
        . '<script>location.replace(' . json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ');</script>'
        . '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">继续前往支付宝支付</a>'
        . '</body></html>';
}
