# CubeShop WMS 监控指标与排障 SOP（Stage P7 / F4）

适用对象：SRE / 值班 / 运营。所有命令均在 `backend/` 目录下执行（`php artisan`）。

---

## 1. 监控指标与告警阈值

| 指标 | 采集点 | 健康阈值 | 超限含义 | 告警通道 |
|---|---|---|---|---|
| 推送成功率 | `wms_api_logs`（api_name=createOutbound, success=1） | ≥ 99% / 日 | 网关或凭证异常 | 审计 + 站内信（`wms.order.view` 持有者） |
| 推送失败单量 | `fulfillment_orders.status=push_failed` | 0 | 需人工介入重推 | 同上 |
| 异常单量 | `fulfillment_orders.status=exception` | 0 | 多为缺 SKU 映射，补映射后重推 | 同上 |
| 回传延迟 | `pushed → shipped` 间隔 | < 24h | 回调丢失或 WMS 侧未回传 | 巡检发现 `pushing_timeout` |
| 回调失败率 | `wms_api_logs`（direction=inbound, success=0） | < 1% | 验签/格式问题 | 审计日志可检索 |
| 库存差异数 | `wms_inventory_diffs`（status=pending） | 0 | 需对账 `wms:reconcile` | 对账任务产生 pending 即告警 |
| 队列积压 | `jobs` 表 / Redis | < 1000 | worker 掉线或消费慢 | `queue:failed` + 积压曲线 |

> 健康巡检 `php artisan wms:health` 一次性返回 6 项检查（config / pushing_timeout /
> push_failed / exception / fail_rate / queue_backlog），仅在有 warning/error 时推送告警，避免噪音。

---

## 2. 排障 SOP

### 2.1 单据卡在 pushed（回调丢失）
**现象**：`fulfillment_orders` 长时间 `pushed`，订单未 `shipped`，无新 `wms_api_logs` 入站。

1. 确认 WMS 侧是否已发货：`php artisan wms:query-outbound <outbound_no>`
   - 返回 `SHIPPED` → 命令按 confirm 语义自动补录运单 + 订单发货；
   - 返回非 SHIPPED → 等 WMS 发货或人工核实。
2. 若需强制重推回调处理：`php artisan queue:retry <id>` 或后台「重推」。

### 2.2 推送失败（push_failed / exception）
**现象**：列表筛 `push_failed` / `exception`，`last_push_error` 有原因。

1. 看错误分类：
   - 「网络异常」→ 网关抖动，后台「重推」重试；
   - 「未配置 WMS 货品编码」→ 补 `WmsSkuMapping` 后「重推」（P7 已修：重推会重新解析映射）；
   - 「缺凭证 / 缺仓库货主编码」→ 后台配置补全，再重推。
2. 批量：`POST /api/admin/wms/fulfillment-orders/{id}/push`（幂等，已推送单返回 409 不产生外呼）。

### 2.3 验签失败（SIGNATURE_INVALID）
**现象**：`wms_api_logs` 入站 `error_msg like '%SIGNATURE_INVALID%'`，订单无变化。

1. 比对菜鸟后台配置的 `app_secret` 与本平台 `wms_configs.app_secret` 是否一致；
2. 确认回调 URL 的 `token` 参数未变（地址变更需同步菜鸟后台）；
3. `php artisan wms:probe` 可快速验证当前配置能否连上沙箱/生产网关。

### 2.4 库存不一致（wms_inventory_diffs 有 pending）
**现象**：对账发现平台库存与 WMS 快照 delta ≠ 0。

1. 查看差异明细：`GET /api/admin/wms/inventory/diffs`；
2. 确认处置：默认同步**绝不**改平台库存（防 WMS 脏数据超卖），仅留快照；
3. 人工校准：`php artisan wms:sync-inventory --apply`（或后台校准入口），走 `InventoryService::adjust(bizType: wms_sync|wms_reconcile)`；
4. 重算 delta 以「处置当下」库存为准，避免覆盖正常出入库。

### 2.5 队列堆积 / 任务失败
1. 看失败任务：`php artisan queue:failed`；
2. 重试：`php artisan queue:retry all`（或指定 id）；
3. 确认 worker 常驻：`php artisan queue:work --queue=wms,default --timeout=60`。

---

## 3. 日常巡检命令速查

| 命令 | 作用 |
|---|---|
| `php artisan wms:health` | 6 项健康巡检，有问题时输出明细 |
| `php artisan wms:probe` | 用当前配置试连沙箱/生产网关，验证凭证与地址 |
| `php artisan wms:query-outbound <outbound_no>` | 主动查询出库状态，SHIPPED 时补录 |
| `php artisan wms:sync-inventory [--apply]` | 拉取 WMS 库存快照（默认不改平台库存） |
| `php artisan wms:reconcile` | 按最新库存重算差异并生成 pending 记录 |
| `php artisan queue:failed` / `queue:retry` | 失败任务排查与重试 |
