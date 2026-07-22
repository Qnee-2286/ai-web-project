# HR实名核身生产配置

## 配置项

在服务器 `api/config.php` 中配置：

```php
'realname' => [
    'provider' => 'tencent',
    'secret_id' => '腾讯云 SecretId',
    'secret_key' => '腾讯云 SecretKey',
    'rule_id' => '腾讯云人脸核身 RuleId',
    'redirect_base' => 'https://hi.hongzedigital.com',
    'callback_secret' => '请改成一串只有服务器知道的随机字符串',
],
```

## 业务流程

1. HR登录后进入 `/hr/realname.html`。
2. HR在弹窗中填写姓名、身份证号，并勾选授权。
3. 后端调用腾讯云 `DetectAuth`，拿到一次性核身链接。
4. 前端把核身链接渲染成二维码，HR使用本人微信扫码完成认证。
5. 腾讯云跳回 `/api/auth/tencent_faceid_callback.php`。
6. 后端用 `BizToken` 查询腾讯云最终结果，更新HR实名状态。

## 数据边界

平台不保存完整身份证号、人脸影像、身份证照片。平台只保存认证状态、认证时间、认证流水号、HR账号ID和必要的证件哈希。

腾讯云增强版人脸核身约 1.2 元/次，后续需要计入单次面试成本、免费试用成本和会员毛利测算。
