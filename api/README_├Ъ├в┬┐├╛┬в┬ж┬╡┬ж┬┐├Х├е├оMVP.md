# AI全量初面系统部署说明

## 1. 数据库

首次部署时，在宝塔 MySQL 中创建数据库 `hi_interview`，导入：

```text
api/database.sql
api/upgrade_20260518_jobs.sql
api/upgrade_20260518_resume.sql
api/upgrade_20260518_ai_questions.sql
api/upgrade_20260518_profile.sql
api/upgrade_20260520_hr_realname_orders.sql
```

如果数据库已经导入过，只需要补导入缺失的升级脚本，不要反复覆盖正式数据。

## 2. 配置文件

服务器上的 `api/config.php` 不会放进上传覆盖包。需要在服务器里手动配置：

- 数据库用户名和密码
- 阿里云短信参数
- 企业邮箱 SMTP 参数
- 腾讯云实名核身参数
- 百炼 API Key

百炼配置示例：

```php
'llm' => [
    'provider' => 'dashscope',
    'api_key' => '你的百炼API Key',
    'endpoint' => 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions',
    'model' => 'qwen-plus',
    'temperature' => 0.3,
],
```

## 3. 正式访问路径

```text
HR端：https://hi.hongzedigital.com/hr/login.html
候选人端：https://hi.hongzedigital.com/candidate/auth.html?token=候选人专属token
```

旧演示目录仅作为历史链接兼容跳转，不再作为正式入口。

## 4. 安全提醒

`api/config.php` 会保存真实密钥，不要发给外部人员，不要上传公开仓库。前端页面中不能出现短信、邮箱、实名、百炼等服务密钥。
