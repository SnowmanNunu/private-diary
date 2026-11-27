# 安全策略 / Security Policy

## 报告漏洞 / Reporting a vulnerability

请勿在公开 Issue 中描述漏洞细节。通过代码托管平台的私有漏洞报告功能（GitHub Security Advisory / Gitee 私密 Issue）联系维护者，并尽量提供复现步骤与影响范围。

Please do not disclose vulnerabilities in public issues. Use private vulnerability reporting (GitHub Security Advisory or a private Gitee issue) with reproduction steps and impact details.

## 支持范围 / Supported versions

仅 `main` 分支的最新提交获得安全修复。

Security fixes are applied to the latest commit on `main`.

## 部署建议 / Deployment notes

- 强制 HTTPS；不要在生产环境开启 `APP_DEBUG`。
- 登录后立即启用 TOTP，并离线保存恢复码。
- `BACKUP_PASSPHRASE` 与云存储密钥只放在服务器环境变量中，且不要提交到仓库。
- 确保 Web 根目录指向 `public/`，`storage/` 与 `database/` 不可被公网直接访问。
- 备份加密后再上传；每季度执行一次 `php artisan diary:backup:verify`，确认备份真的可恢复。
