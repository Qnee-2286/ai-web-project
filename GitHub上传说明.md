# GitHub 上传说明

这是整理后的当前完整源码目录，来源为：

1. 完整基础包：`_build_membership_0707_final/final`
2. 最新会员支付补丁：`_inspect_payment_cleanup_20260708`

本目录已做以下清理：

- 已删除真实 `api/config.php`
- 已删除 `private_uploads/`、`api/private_uploads/` 等用户上传和运行数据
- 已删除重复的 `api/api/` 目录
- 已删除 `.sql`、`.zip`、`.bak`、`.log` 等数据库/备份/运行文件
- 已补充 `.gitignore`
- 已补充 `api/config.example.php`

## 上传 GitHub 前

建议只上传本目录内容，不要上传外层的 SQL 归档目录。

## 服务器部署时

服务器上仍然需要单独维护：

- `api/config.php`
- 支付宝/微信 PEM 密钥文件
- 数据库真实账号密码
- OSS、短信、百炼/千问、实名核验等真实密钥

这些文件不要提交到 GitHub。

## SQL

SQL 已单独归档到同级目录：`数据库脚本_单独归档_不上传GitHub`。
该目录只用于内部备份或工程师部署参考，不建议上传 GitHub。
