# private-diary

一个自托管的单用户私密日记系统：Markdown 写作、标签与全文搜索、日历回顾、二重验证，以及端到端加密的异地备份。

A self-hosted, single-user diary: Markdown writing, tags and full-text search, calendar review, two-factor authentication, and encrypted off-site backups.

[中文](#中文) · [English](#english)

---

## 中文

### 功能特性

- **写作**：按日期一篇，Markdown 编辑与实时预览，1.2 秒防抖自动保存，`Ctrl/⌘ + S` 手动保存
- **整理**：逗号分隔标签、标签筛选、中文全文搜索（FTS5 trigram，短词用子串兜底）
- **回顾**：月历视图、去年今日 / 去年同日
- **附件**：私有图片上传，鉴权路由访问，删除日记时同步清理文件
- **导出与导入**：Markdown ZIP（按年分目录、图片相对链接、`manifest.json`）与结构化 JSON；导入支持按日期幂等、冲突策略与图片恢复
- **统计**：年度篇数/字数/连续天数、写作热力图、每月篇数与常用标签
- **安全**：单用户、无注册入口、登录限速、会话加密、TOTP 二重验证与一次性恢复码
- **备份**：SQLite 一致性快照 + 附件打包 + AES-256-GCM 加密 + 异地磁盘，附带恢复演练与审计

### 技术栈

Laravel 13 · PHP 8.3+ · SQLite · Blade + 原生 JavaScript · league/commonmark · pragmarx/google2fa · S3 兼容存储（可选）

无 Node 构建链，无 Redis，无外部 CDN；所有静态资源本地化。

### 快速开始

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --force
php artisan diary:user          # 按提示创建唯一用户（交互式）
php artisan serve
```

打开 `http://127.0.0.1:8000/login` 登录。

> 请确认 PHP 已启用 `pdo_sqlite`、`openssl`、`mbstring`、`zip` 扩展。`composer install` 会从官方 Packagist 拉取依赖。

### 运行测试

```bash
php artisan test
```

### 安全与备份

1. 登录后到「安全」页启用 TOTP，并**离线保存恢复码**。
2. 生成备份口令并**离线保存**；丢失后无法恢复任何备份：
   ```bash
   # 写入随机口令到 .env 的 BACKUP_PASSPHRASE
   php -r '$f=".env"; $s=file_get_contents($f); $s=preg_replace("/^BACKUP_PASSPHRASE=.*$/m", "BACKUP_PASSPHRASE=".bin2hex(random_bytes(24)), $s); file_put_contents($f,$s);'
   ```
3. 异地存储：把 `.env` 中的 `BACKUP_DISK` 设为 `s3`，并填写以下项（兼容 AWS S3、腾讯云 COS、Cloudflare R2、MinIO 等）：
   | 变量                                            | 说明                 |
   | --------------------------------------------- | ------------------ |
   | `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | 访问密钥               |
   | `AWS_BUCKET`                                  | 桶名                 |
   | `AWS_DEFAULT_REGION`                          | 区域（R2 用 `auto`）    |
   | `AWS_ENDPOINT`                                | 兼容服务的端点，原生 S3 可留空  |
   | `AWS_USE_PATH_STYLE_ENDPOINT`                 | MinIO 等通常设为 `true` |
4. 手动验证备份链路：
   ```bash
   php artisan diary:backup
   php artisan diary:backup:verify        # 可加 --file= 指定某份备份
   ```
5. 配置系统 cron 以启用每日 03:00 备份、每季度 1 日 04:00 恢复演练：
   ```cron
   * * * * * cd /path/to/private-diary && php artisan schedule:run >> /dev/null 2>&1
   ```
6. 生产环境请使用 HTTPS，并确认 Web 服务器不会把 `storage/`、`database/` 暴露到公网。`.env`、SQLite 文件、私密附件与备份均已被 `.gitignore` 排除。

### 贡献

见 [CONTRIBUTING.md](CONTRIBUTING.md)。发现安全问题请按 [SECURITY.md](SECURITY.md) 私下报告，不要公开 Issue。

### 许可证

[MIT](LICENSE) © 2026 private-diary contributors.

---

## English

### Features

- **Write**: one entry per day, Markdown editor with live preview, debounced autosave (1.2s), `Ctrl/⌘ + S` to save
- **Organize**: comma-separated tags, tag filters, Chinese full-text search (SQLite FTS5 trigram with substring fallback)
- **Reflect**: monthly calendar, "on this day last year" on the list and reading pages
- **Attachments**: private image uploads served through authenticated routes; files removed with their entry
- **Export & import**: Markdown ZIP (yearly folders, relative image links, `manifest.json`) and structured JSON; date-based idempotent import with conflict strategies and image restoration
- **Stats**: annual entry/word counts, streaks, contribution-style heatmap, monthly counts and top tags
- **Security**: single user, no registration, login throttling, encrypted sessions, TOTP 2FA with one-time recovery codes
- **Backups**: consistent SQLite snapshot + attachments, AES-256-GCM encryption, off-site disk, restore drills with audit log

### Stack

Laravel 13 · PHP 8.3+ · SQLite · Blade + vanilla JavaScript · league/commonmark · pragmarx/google2fa · optional S3-compatible storage

No Node build step, no Redis, no external CDN.

### Quick start

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --force
php artisan diary:user          # interactive: creates the only user
php artisan serve
```

Then visit `http://127.0.0.1:8000/login`. PHP needs `pdo_sqlite`, `openssl`, `mbstring` and `zip`.

### Tests

```bash
php artisan test
```

### Security & backups

- Enable TOTP under "安全" and **store the recovery codes offline**.
- Generate a backup passphrase and **keep it offline** — backups cannot be restored without it. Set `BACKUP_PASSPHRASE` in `.env`.
- For off-site backups set `BACKUP_DISK=s3` and fill in `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_DEFAULT_REGION`, `AWS_ENDPOINT` (S3-compatible services) and `AWS_USE_PATH_STYLE_ENDPOINT` (usually `true` for MinIO).
- Verify manually with `php artisan diary:backup` and `php artisan diary:backup:verify`.
- Add a system cron for the daily backup and quarterly restore drill:
  ```cron
  * * * * * cd /path/to/private-diary && php artisan schedule:run >> /dev/null 2>&1
  ```
- Use HTTPS in production and never expose `storage/` or `database/` publicly. `.env`, SQLite files, private attachments and backups are gitignored.

### Contributing & license

See [CONTRIBUTING.md](CONTRIBUTING.md) and [SECURITY.md](SECURITY.md). Released under the [MIT license](LICENSE).
