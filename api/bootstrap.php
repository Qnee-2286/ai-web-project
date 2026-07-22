<?php
$config = require __DIR__ . '/config.php';

function hi_payment_local_key_path(string $current, string $filename): string
{
    $local = dirname(__DIR__) . '/private_uploads/payment/' . $filename;
    if (@is_file($local) && @is_readable($local)) {
        return $local;
    }
    return $current;
}

if (isset($config['payment']['alipay']['merchant_private_key'])) {
    $config['payment']['alipay']['merchant_private_key'] = hi_payment_local_key_path(
        (string)$config['payment']['alipay']['merchant_private_key'],
        'alipay_private_key.pem'
    );
}
if (isset($config['payment']['wechat']['merchant_private_key'])) {
    $config['payment']['wechat']['merchant_private_key'] = hi_payment_local_key_path(
        (string)$config['payment']['wechat']['merchant_private_key'],
        'apiclient_key.pem'
    );
}
if (isset($config['payment']['wechat']['platform_public_key'])) {
    $config['payment']['wechat']['platform_public_key'] = hi_payment_local_key_path(
        (string)$config['payment']['wechat']['platform_public_key'],
        'pub_key.pem'
    );
}

if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($string, $start) : substr($string, $start, $length);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int
    {
        return strlen($string);
    }
}

date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Shanghai');

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name($config['app']['session_name'] ?? 'HI_INTERVIEW_SESSION');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/aliyun.php';
require_once __DIR__ . '/lib/smtp.php';
require_once __DIR__ . '/lib/tencentcloud.php';
require_once __DIR__ . '/lib/realname_orders.php';
require_once __DIR__ . '/lib/dashscope.php';
require_once __DIR__ . '/lib/oss.php';

set_exception_handler(function (Throwable $e): void {
    respond(false, 'Server API error, please try again later', [
        'error' => $e->getMessage(),
    ], 500);
});

$pdo = db($config);
