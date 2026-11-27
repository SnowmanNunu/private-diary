# 贡献指南 / Contributing

欢迎提交问题与 PR。这个项目很小，保持简单是首要原则。

Issues and PRs are welcome. Keep it small and simple.

## 开发流程 / Workflow

1. 从 `main` 建分支，例如 `feature/tag-count` 或 `fix/search-escaping`。
2. 本地安装依赖并迁移数据库（见 README）。
3. 改动后运行 `php artisan test`，必要时运行 `./vendor/bin/pint` 统一格式。
4. PR 中说明改动动机、影响范围，以及是否涉及数据库迁移。

## 注意 / Please note

- 不要提交 `.env`、`database/*.sqlite`、`storage/app/private/` 下的附件或备份，以及任何密钥。
- 新增功能请同时补测试；涉及搜索、附件、2FA、备份的改动尤其需要验证数据隔离。
- 单用户是这个项目的核心假设：不要引入注册、多用户权限体系或新的外部服务依赖，除非先在 Issue 中讨论。
- 中文与英文文档保持同步。
