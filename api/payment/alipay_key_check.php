<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/alipay.php';

header('Content-Type: text/html; charset=utf-8');

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

try {
    $pay = alipay_config($config);
    $privatePem = alipay_normalize_key((string)$pay['merchant_private_key'], 'private');
    $private = openssl_pkey_get_private($privatePem);
    if (!$private) {
        throw new RuntimeException('支付宝应用私钥不可用');
    }

    $details = openssl_pkey_get_details($private);
    $publicPem = (string)($details['key'] ?? '');
    if ($publicPem === '') {
        throw new RuntimeException('无法从应用私钥导出应用公钥');
    }

    $message = 'hi-alipay-sign-check-' . date('YmdHis');
    $signature = '';
    openssl_sign($message, $signature, $private, OPENSSL_ALGO_SHA256);
    $verify = openssl_verify($message, $signature, $publicPem, OPENSSL_ALGO_SHA256) === 1;
    $publicBody = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $publicPem);
    $copyText = trim($publicPem);

    echo '<!doctype html><html><head><meta charset="utf-8"><title>支付宝应用公钥自检</title>';
    echo '<style>body{font-family:Arial,"Microsoft YaHei",sans-serif;max-width:960px;margin:36px auto;padding:0 20px;color:#111827}textarea{width:100%;height:220px;font-family:Consolas,monospace;font-size:13px;line-height:1.6}code{background:#f3f4f6;padding:2px 6px;border-radius:4px}.ok{color:#047857}.bad{color:#b91c1c}.box{border:1px solid #e5e7eb;border-radius:8px;padding:18px;margin:16px 0}</style>';
    echo '</head><body>';
    echo '<h2>支付宝应用公钥自检</h2>';
    echo '<div class="box">';
    echo '<p>自检结果：<strong class="' . ($verify ? 'ok' : 'bad') . '">' . ($verify ? '通过' : '失败') . '</strong></p>';
    echo '<p>应用公钥 SHA256 指纹：<code>' . h(hash('sha256', $publicBody)) . '</code></p>';
    echo '<p>应用公钥前 32 位：<code>' . h(substr($publicBody, 0, 32)) . '</code></p>';
    echo '<p>应用公钥后 32 位：<code>' . h(substr($publicBody, -32)) . '</code></p>';
    echo '</div>';
    echo '<p><strong>把下面这个“应用公钥”复制到支付宝开放平台后台的接口加签方式里。</strong></p>';
    echo '<textarea readonly onclick="this.select()">' . h($copyText) . '</textarea>';
    echo '<p>注意：这里显示的是“应用公钥”，不是支付宝公钥，也不是应用私钥。</p>';
    echo '</body></html>';
} catch (Throwable $e) {
    echo '<!doctype html><html><head><meta charset="utf-8"><title>支付宝应用公钥自检</title></head><body>';
    echo '<h2>支付宝应用公钥自检失败</h2>';
    echo '<p>' . h($e->getMessage()) . '</p>';
    echo '</body></html>';
}
