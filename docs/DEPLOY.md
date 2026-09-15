# CubeShop 部署与运维说明（V1.0）

> 适用版本：Laravel 13.12（PHP 8.4+）+ PostgreSQL 16 + Vue 3 双前端（admin / web）。
> 上线前请逐项核对 [LAUNCH_CHECKLIST.md](./LAUNCH_CHECKLIST.md)。

## 1. 环境要求

| 组件 | 版本要求 | 说明 |
|---|---|---|
| PHP | 8.4+ | 扩展：pdo_pgsql、mbstring、openssl、bcmath、gd（验证码 SVG 无需） |
| PostgreSQL | 14+（推荐 16） | 唯一受支持的生产数据库 |
| Redis | 7.x（可选） | 不部署时 CACHE_STORE/QUEUE_CONNECTION 用 database 降级 |
| Node | 20+ | 仅构建前端产物时需要 |

## 2. 方式一：Docker Compose（推荐）

```bash
# 1. 准备环境配置
cp backend/.env.production.example backend/.env
#    编辑 .env：APP_KEY、DB_PASSWORD、PAY_SIGN_SECRET、CORS_ALLOWED_ORIGINS、APP_URL

# 2. 生成应用密钥
php -d memory_limit=-1 backend/artisan key:generate --force   # 或在容器内执行

# 3. 构建前端产物（挂载给 nginx）
cd admin && npm ci && npm run build && cd ../web && npm ci && npm run build
#    生产需将 admin/dist、web/dist 交给 nginx 托管（见 §5 前端部署）

# 4. 启动核心服务
docker compose up -d postgres redis app nginx

# 5. 启动队列与调度器（订单超时自动取消依赖 scheduler，必须启用）
docker compose --profile queue up -d

# 6. 初始化数据库
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force   # 管理员账号 + 基础分类/示例商品
```

验证：`curl https://你的域名/api/health` 返回 `{"code":0,...}`。

## 3. 方式二：物理机 / 虚拟机

```bash
cd backend
composer install --no-dev --optimize-autoloader
cp .env.production.example .env && php artisan key:generate --force
php artisan migrate --force && php artisan db:seed --force
php artisan config:cache && php artisan route:cache && php artisan event:cache

# 队列与调度（订单超时取消依赖 scheduler，必须常驻）
php artisan queue:work --tries=3 --sleep=1        # 用 supervisor 常驻
php artisan schedule:run                          # crontab：* * * * * 每分钟执行
```

crontab 示例：

```
* * * * * cd /var/www/cubeshop/backend && php artisan schedule:run >> /dev/null 2>&1
```

## 4. 数据初始化

| 内容 | 来源 | 说明 |
|---|---|---|
| 表结构 | `php artisan migrate --force` | 与 `docs/design/CubeShop_Database_Design_v1.0.md` 一致 |
| 管理员账号 | RolePermissionSeeder | admin / Admin@123、operator / Operator@123 —— **上线后立即改密** |
| 三角色权限 | RolePermissionSeeder | super_admin / operator / user，13 个权限码 |
| 基础分类 + 示例商品 | ProductSeeder | 5 个一级分类、8 个演示商品；生产可替换为真实数据 |
| 运营参数 | OrderConfigSeeder | 超时 30 分钟、运费 10 元、满 99 包邮、库存预警 10 件（管理端可改） |

## 5. 前端部署（admin / web）

两个前端均为纯静态产物（`npm run build` → `dist/`），推荐由同一 Nginx 托管并反代 API：

```nginx
# admin.cubeshop.example.com → admin/dist
# www.cubeshop.example.com   → web/dist
server {
    listen 443 ssl http2;
    server_name www.cubeshop.example.com;
    root /var/www/cubeshop/web/dist;
    index index.html;

    location / { try_files $uri $uri/ /index.html; }        # SPA history 路由
    location /api/ { proxy_pass https://api.internal:9000; } # 或直接指向后端域名
}
```

后端 API 独立域名时，必须设置环境变量 `CORS_ALLOWED_ORIGINS` 为两个前端域名的完整来源。

## 6. HTTPS

- 推荐 TLS 在 Nginx / SLB 终结，证书覆盖所有前端与 API 域名。
- 全站 HTTPS 后设置 Nginx 头 `X-Forwarded-Proto: https`，并在 `bootstrap/app.php` 中信任代理（`$middleware->trustProxies(at: '*')`），否则 `APP_URL` 生成的链接与 secure cookie 会退化为 http。
- 支付渠道回调地址必须为 HTTPS（微信/支付宝强制）。

## 7. 日志与监控

| 项 | 配置 | 说明 |
|---|---|---|
| 应用日志 | `LOG_CHANNEL=daily`，保留 14 天 | `storage/logs/laravel-YYYY-MM-DD.log`，50000 级错误不暴露内部细节 |
| 支付/退款日志 | `payment_logs` 表 | 每次渠道请求/回调全量落库，对账依据 |
| 库存流水 | `inventory_logs` 表 | 锁定/释放/扣减/调整全量可追溯 |
| 操作日志 | `sys_operation_logs` 表 | 后台管理操作审计（管理端可查询） |
| 健康检查 | `GET /api/health` | 接入负载均衡 / 拨测监控 |
| 进程监控 | supervisor 管理队列 worker | `queue:work` 异常退出自动拉起 |

## 8. 备份与恢复

```bash
# 每日全量备份（crontab，保留 30 天）
0 2 * * * docker compose -f /path/docker-compose.yml exec -T postgres \
  pg_dump -U cubeshop cubeshop | gzip > /backup/cubeshop-$(date +\%F).sql.gz

# 恢复
gunzip -c /backup/cubeshop-2026-09-15.sql.gz | \
  docker compose exec -T postgres psql -U cubeshop cubeshop
```

同时备份：`backend/storage/app`（上传文件卷 `app_storage`）与 `.env`（密钥单独保管）。

## 9. 升级与回滚

```bash
# 升级
git pull && composer install --no-dev --optimize-autoloader
php artisan migrate --force            # 只增不删的幂等迁移
npm run build（admin / web）           # 重建前端
php artisan config:cache && php artisan queue:restart

# 回滚
git checkout <上一版本标签>
composer install --no-dev --optimize-autoloader
php artisan migrate:rollback --step=1  # 仅当上一版本含迁移且确认可回退
php artisan config:cache && php artisan queue:restart
```

回滚原则：先回代码，再评估数据迁移是否需要回退；`orders/payments/refunds/inventory_logs` 等交易数据永不删除。

## 10. 常用运维命令

```bash
php artisan orders:cancel-expired            # 手动触发超时订单取消（scheduler 每分钟自动）
php artisan orders:cancel-expired --dry-run  # 只预览将取消的订单
php artisan queue:restart                    # 队列平滑重启（发版后必须）
php artisan cache:clear                      # 清缓存（系统配置有 60s 缓存）
php artisan db:seed --force                  # 重新初始化种子（幂等，注意 see 前清库）
```
