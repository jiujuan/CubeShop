# CubeShop 电商系统

单体 Laravel 后端 + Vue 3 管理端的 MVP 电商系统。设计文档见 `docs/design/`。

## 目录结构

```
CubeShop/
├── backend/          # Laravel 13 后端（PHP-FPM）
├── admin/            # Vue 3 + TS + Vite 管理端
├── web/              # Vue 3 + TS + Vite 用户端（storefront）
├── docker/
│   └── nginx/        # Nginx 配置
├── docker-compose.yml
└── docs/             # PRD / 架构 / 数据库 / API / 路线图 / 部署 / 测试
```

## 方式一：Docker Compose 一键启动（推荐）

```bash
docker compose up -d          # 启动 postgres + redis + app + nginx
# 可选服务：
docker compose --profile queue up -d   # 队列 Worker + 调度器
```

- 后端 API：http://localhost:8000/api/health
- 首次启动时 entrypoint 自动执行 `php artisan migrate --force`
- 默认数据库：cubeshop / cubeshop / cubeshop_secret（见 docker-compose.yml）

## 方式二：本地直连开发（无 Docker）

需要：PHP 8.4+（含 pdo_pgsql 扩展）、Composer、Node 20+、PostgreSQL 14+。

```bash
# 1. 数据库：本地创建 cubeshop 库与用户，修改 backend/.env 对应连接

# 2. 后端
cd backend
composer install
php artisan migrate          # 创建全部表结构（与数据库设计文档一致）
php artisan serve            # http://127.0.0.1:8000

# 3. 管理端
cd admin
npm install
npm run dev                  # http://localhost:5173（/api 已代理到 8000）

# 4. 用户端（storefront）
cd web
npm install
npm run dev                  # 默认 Vite 端口（/api 已代理到 8000）
```

> 无 PostgreSQL 时，可临时用 SQLite 验证：`DB_CONNECTION=sqlite DB_DATABASE=<绝对路径>/database.sqlite php artisan migrate`
>
> 种子数据（管理员、权限码、演示分类与商品）：`php artisan migrate:fresh --seed`
> 默认管理员 `admin / Admin@123`（生产环境务必修改）。

## 缓存 / 队列降级说明

架构要求 Redis 可选：`CACHE_STORE` / `QUEUE_CONNECTION` 默认 `database`
（Laravel 标准 cache/jobs 表已含在迁移中）。有 Redis 时改为 `redis` 即可。

## 健康检查

`GET /api/health` 返回统一响应结构：

```json
{ "code": 0, "message": "ok", "data": { "status": "ok", "database": { "ok": true } } }
```

## 开发进度

按 `docs/design/CubeShop_Roadmap_v1.0.md` 推进，**P0-P7 已全部完成**：

- [x] P0 项目脚手架与基础设施
- [x] P1 认证、权限与公共服务（Sanctum + spatie/permission）
- [x] P2 商品与分类
- [x] P3 购物车与库存
- [x] P4 订单与超时取消
- [x] P5 支付与退款
- [x] P6 后台运营（订单管理/发货/导出/概览/库存预警）
- [x] P7 联调、测试与上线准备

## 相关文档

| 文档 | 说明 |
|---|---|
| [docs/DEPLOY.md](docs/DEPLOY.md) | 部署运维：Docker/物理机部署、HTTPS、日志监控、备份恢复、升级回滚 |
| [docs/LAUNCH_CHECKLIST.md](docs/LAUNCH_CHECKLIST.md) | 上线检查清单（配置安全/功能开关/核心链路回归/应急预案） |
| [backend/.env.production.example](backend/.env.production.example) | 生产环境配置模板 |
| [docs/testing/regression_p7.sh](docs/testing/regression_p7.sh) | P0 用例自动化回归脚本（25/25 通过，报告见同目录） |
| [docs/testing/concurrency_test.sh](docs/testing/concurrency_test.sh) | 并发防超卖压测脚本（零超卖验证） |
