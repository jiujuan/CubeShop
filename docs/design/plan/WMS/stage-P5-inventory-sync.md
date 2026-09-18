# Stage P5：库存查询/同步、对账、异常处理与降级运维

**状态**：⬜ 未开始
**工期**：约 0.5 周（3 人日）
**对应设计文档**：§5.4（库存同步）、§7.6（inventory.query）、§10（可靠性与运维）、§12（库存不一致风险）

---

## 1. 目标与功能

| # | 功能 | 说明 |
|---|------|------|
| F1 | 库存主动查询 | `CainiaoAdapter::queryInventory()` 已落地（P2），本阶段包装成服务与命令，支持按 SKU 批量 |
| F2 | 定时同步 | `schedule` 每 N 分钟拉取启用仓库的可用库存，写 `wms_inventory_snapshots`；**默认不自动改平台可售**（只存快照 + 告警） |
| F3 | 对账任务 | 每日比对「平台可用库存 vs WMS 可用量」，差异写 `wms_inventory_diffs` 并生成告警/运营待办 |
| F4 | 差异处理入口 | 后台可按 SKU 一键校准（调用 `InventoryService::adjust()` 并留备注），或导出差异表人工处理 |
| F5 | 异常单巡检 | 定时扫描：长期 `Pushing`（>30 分钟）、`PushFailed`、`Exception`、回传超时未完成发货的单据 → 汇总告警 |
| F6 | 降级开关 | `auto_push` / `auto_push_return` 可一键关闭（配置页已有），关闭后单据停 `Created`，由人工确认推送；开关变更落审计日志 |
| F7 | 日志保留策略 | `wms_api_logs` 保留 ≥90 天，`prune` 命令 + schedule；敏感字段脱敏策略不变 |
| F8 | 健康巡检命令 | `php artisan wms:health`：检查配置缺失、队列积压、失败率、时间戳 |

---

## 2. 依赖
- **前置**：P2（queryInventory）、P4（库存回加路径确定）、P0（配置与日志表）
- **现有资产**：`InventoryService::adjust()/getStockMap()`、`Console/Kernel` 或 Laravel 11+ `routes/console.php` 调度、通知能力（`Notification` 模型、`notifyUser()` 类 helper）
- **外部**：菜鸟沙箱/生产的库存接口配额

---

## 3. 实施步骤

**Step 1｜迁移（后端）**
- `2026_09_20_000072_create_wms_inventory_snapshots_table.php`：`warehouse_id, sku_id, wms_sku_code, available_qty, synced_at`，`INDEX(warehouse_id, synced_at)`
- `2026_09_20_000073_create_wms_inventory_diffs_table.php`：`warehouse_id, sku_id, platform_qty, wms_qty, diff, status(pending/resolved/ignored), handled_by, handled_at, created_at`
- 同步 PG：`php artisan migrate --force`

**Step 2｜同步服务（后端）**
- `app/Services/Wms/WmsInventorySyncService.php`：
  - `syncWarehouse(WmsConfig $config, array $skuIds = []): int`（返回条数；批量分页，避免一次拉太多）
  - 只写快照，**不改可售**；可选 `--apply` 参数才按差异调 `InventoryService::adjust()`
- `app/Services/Wms/WmsReconcileService.php`：`daily()` 生成差异记录；`resolve(int $diffId, int $operatorId, bool $applyInventory)` 处理

**Step 3｜命令与调度（后端）**
- `php artisan wms:sync-inventory {warehouseId?} {--apply}`、`php artisan wms:reconcile {--date=}`、`php artisan wms:health`、`php artisan wms:prune-logs {--days=90}`
- `routes/console.php`（或 `Console/Kernel`）注册：`sync-inventory` 每 30 分钟、`reconcile` 每日 02:30、`prune-logs` 每日 04:00
- ⚠️ 定时任务必须可关闭：用 `SystemConfig` 或 env 控制开关，默认开启但本地不常驻

**Step 4｜异常巡检（后端）**
- `WmsHealthCheckService::collect()`：统计 `Pushing` 超时、`PushFailed` 数、`Exception` 数、回调失败率、队列积压
- 结果写 `sys_operation_log` + 通过站内通知推给有 `wms.order.manage` 的账号

**Step 5｜后台接口（后端）**
```
GET  /api/admin/wms/inventory/snapshots                permission:wms.config.manage
GET  /api/admin/wms/inventory/diffs                    permission:wms.config.manage
POST /api/admin/wms/inventory/diffs/{id}/resolve       permission:wms.config.manage
GET  /api/admin/wms/health                             permission:wms.config.manage
```
（页面在 P6 补，本阶段至少保证接口可查）

---

## 4. 测试

### 4.1 单元测试（Pest）
- `tests/Unit/WmsInventorySyncServiceTest.php`（≥6 例）：分页拉取、相同 SKU 快照覆盖、异常仓跳过、`--apply` 与非 apply 行为差异、重量级批量不超内存
- `tests/Unit/WmsReconcileServiceTest.php`（≥5 例）：无差异不生成记录、差异生成、重复对账不重复开单、`resolve` 后幂等
- `tests/Feature/WmsHealthApiTest.php`（≥5 例）：健康接口返回结构、缺配置 warning、401/403、报表数据准确
- `tests/Feature/WmsScheduleTest.php`（≥1 例）：`$schedule` 有对应任务（用 `Artisan::fake()` 或直接跑命令）

### 4.2 回归测试（必跑）
- `php -d memory_limit=1G vendor/bin/pest` ≥ P4 基线
- 库存相关既有用例（`InventoryService` 各方法、`OrderApiTest` 超卖保护）全部通过
- 调度命令本地手动跑一次确认无副作用

### 4.3 集成测试
| 场景 | 步骤 | 期望 |
|---|---|---|
| 沙箱库存同步 | `php artisan wms:sync-inventory 1` | 快照表写入每个 SKU 的可用量；平台库存不变 |
| 制造差异 | 手动改平台库存后跑对账 | 差异表生成 1 行，`pending` |
| 校准 | 后台调 `resolve(apply=true)` | 平台库存改为 WMS 值，`inventory_logs` 有备注 |
| 健康巡检 | `php artisan wms:health` | 输出配置/队列/失败率，异常项可读 |
| 降级 | 关闭 `auto_push` 后下单 | 发货单停在 `created`，不自动推送；开关变更有审计记录 |

---

## 5. 验收清单

- [ ] 同步任务默认**不自动改平台库存**（防止 WMS 侧脏数据污染售卖）
- [ ] 对账差异可追踪、可处理，`inventory_logs` 有来源备注
- [ ] 健康巡检能发现：配置缺失、队列积压、失败率异常
- [ ] 降级开关真实生效且变更有审计
- [ ] 定时任务的启停有开关，本地开发不被干扰
- [ ] `wms_api_logs` 清理策略生效且脱敏规则未被绕过
- [ ] 单元测试 / 回归 / 集成测试通过

### 验收记录
| 日期 | 人 | 结果 | 备注 |
|---|---|---|---|
|  |  |  |  |

---

## 6. 完成情况

- [ ] Step 1 迁移（2 张 + PG 同步）
- [ ] Step 2 同步/对账服务
- [ ] Step 3 命令与调度
- [ ] Step 4 健康巡检 + 告警
- [ ] Step 5 后台接口
- [ ] 单元测试通过
- [ ] 回归测试通过
- [ ] 集成测试通过
- [ ] 验收清单全勾选

**阶段状态**：⬜ 未开始 → 完成后改为 ✅ 并同步 `README.md` §4
