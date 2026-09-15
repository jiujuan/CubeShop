# CubeShop 上线检查清单（V1.0）

> 使用方式：上线前逐项核对，全部勾选后方可放量。任何一项未通过即视为存在 P0 阻塞。

## 1. 配置安全

- [ ] `APP_DEBUG=false`（生产，避免堆栈/密钥泄漏）
- [ ] `APP_KEY` 已重新生成（非开发环境默认值）
- [ ] `DB_PASSWORD` 已改为强密码（非 cubeshop_secret 默认值）
- [ ] `PAY_SIGN_SECRET` 已设为 32 字节以上强随机串
- [ ] `CORS_ALLOWED_ORIGINS` 已设置为前端真实域名（**禁止 \***）
- [ ] `SANCTUM_TOKEN_EXPIRATION` 已设置（建议 7 天）
- [ ] 管理员默认密码已修改（admin / operator 账号）
- [ ] `.env` 文件权限 600，不进版本库，已单独备份保管

## 2. 功能开关

- [ ] `PAYMENT_SANDBOX=false` 或确认沙箱模式仅内网可用（沙箱模拟支付接口 `/api/payments/sandbox/{no}` 生产必须关闭或加访问控制）
- [ ] 演示数据是否保留：`ProductSeeder` 示例商品如不上线销售需下架或替换
- [ ] 验证码 debug 模式：确认 `APP_DEBUG=false` 后验证码接口不再返回 `debug_code`（已按此实现，需复核）

## 3. 服务与健康检查

- [ ] `GET /api/health` 返回 200
- [ ] 队列 worker 常驻（supervisor），`queue:restart` 后自动拉起
- [ ] scheduler 每分钟执行（`schedule:run` / 调度器容器），订单超时取消生效
- [ ] 前端两个域名（admin / web）可访问，SPA 刷新路由不 404
- [ ] HTTPS 证书有效，HTTP 自动跳转 HTTPS
- [ ] `trustProxies` 已配置（X-Forwarded-Proto 透传）

## 4. 核心链路回归（上线前最后一轮）

- [ ] 注册 → 登录 → 浏览 → 加购 → 下单 → 支付（沙箱/小额真实）→ 后台发货 → 用户查看「已发货」
- [ ] 未支付订单超时后自动取消且库存释放
- [ ] 已支付订单申请退款 → 后台审核 → 状态与库存正确
- [ ] 并发下单压测通过（`docs/testing/concurrency_test.sh`，5 库存 10 并发恰售 5）
- [ ] 回归测试通过（`docs/testing/regression_p7.sh`，25/25）
- [ ] 支付回调幂等：重复回调不产生重复扣款/状态变更
- [ ] CORS 预检通过，前端登录/下单/上传无跨域报错
- [ ] Token 失效后前端跳转登录页并携带回跳地址

## 5. 数据与备份

- [ ] 数据库每日自动备份（crontab + pg_dump），并做过一次恢复演练
- [ ] `storage/app` 上传文件已纳入备份
- [ ] 交易表（orders/payments/refunds/inventory_logs）无清理任务，日志保留策略确认

## 6. 限流与防刷

- [ ] 登录/验证码限流生效（同 IP 10 次/分、登录 5 次/分，超限返回 429）
- [ ] 下单接口限流生效（同用户 10 次/分）
- [ ] 上传接口大小限制（Nginx `client_max_body_size 20m`）确认

## 7. 应急预案

- [ ] 回滚步骤演练过一次（`DEPLOY.md` §9）
- [ ] 支付渠道异常的降级方案：可临时关闭支付入口（前端隐藏 / 管理端公告）
- [ ] 数据库故障切换/恢复流程负责人与联系方式已明确
- [ ] 密钥（APP_KEY / PAY_SIGN_SECRET / DB 密码）托管在安全位置，至少两人可知
