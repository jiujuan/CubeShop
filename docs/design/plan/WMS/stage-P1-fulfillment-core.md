# Stage P1：履约发货单内核 + 与订单/库存接线（内部闭环）

**状态**：⬜ 未开始
**工期**：约 1 周（5 人日）
**对应设计文档**：§2.3（发货单状态机）、§4.1（`fulfillment_order* `）、§5.1（出库主流程）、§6（Adapter 标准内部接口）

---

## 1. 目标与功能

### 1.1 目标
在**不依赖任何外部 WMS 真实账号**的前提下，打通平台内部履约链路：
「订单支付成功 → 生成发货单 fulfillment_order → 待推送 → Job 调用 Adapter → 推送成功/失败 → 单号回流导致订单发货」。
本阶段用 P0 的 `MockAdapter` 跑端到端，把状态机、幂等、重试、与订单侧的唯一发货入口接好；P2 只是把 Mock 换成真实菜鸟报文。

### 1.2 交付功能清单
| # | 功能 | 说明 |
|---|------|------|
| F1 | 发货单数据模型 | `fulfillment_orders` + `fulfillment_order_items`，含推送字段与回传字段 |
| F2 | 发货单状态机 | `Created / PendingPush / Pushing / Pushed / Picking / Packed / Shipped / Completed / Cancelled / Exception / PushFailed`，非法流转统一 40009（409） |
| F3 | 订单侧挂载 | `orders.warehouse_id` + `orders.fulfillment_status`（冗余，列表筛选用） |
| F4 | 支付成功触发 | 订单进入 `pending_ship` 后自动创建发货单；`auto_push=true` 时派发推送 Job |
| F5 | 异步推送与重试 | `PushOutboundJob`（`tries` 来自 `push_retry_times`、指数 backoff），幂等 `request_id`，超限转 `PushFailed` |
| F6 | 回传落库能力 | `markShipped()` / `markStatus()`：落运单后调用 `OrderService::shipForShipment()` 完成订单发货（**唯一入口，禁止另写订单状态流转**） |
| F7 | 取消联动 | 订单取消/退款成功且发货单未出库 → 调用取消（先本地置 `Cancelled`，推送过则异步调用 Adapter 取消） |
| F8 | 后台最小运营接口 | 发货单列表/详情/手工重推/取消（页面在 P6） |
| F9 | 幂等保障 | 同一订单只生成一张发货单（唯一索引 + 业务层幂等） |

### 1.3 明确不做
- 不做真实菜鸟报文（P2）
- 不做回调入口（P3）
- 不做退货入库（P4）
- 库存不在本阶段改动（支付成功已 `deduct`，发货不再二次扣减）

---

## 2. 依赖

### 2.1 前置
- **P0 必须完成**（仓库、`WmsConfig`、`MockAdapter`、`WmsAdapterFactory`、`WmsConfigService::resolveSkuCode()`）

### 2.2 依赖的现有资产
- `OrderService::acceptForShipment()` / `shipForShipment()` / `transitionTo()` —— 订单状态唯一执行点
- `PaymentService`（支付成功后调用 `acceptForShipment` 的那段） —— 触发点
- `InventoryService`（不在本阶段调用，但取消/退款路径会用到 release）
- `NoGeneratorService`（单号前缀与唯一校验）
- 队列：`database` driver + `jobs` 表已存在（需 `php artisan queue:work` 或提供同步兜底命令）

---

## 3. 实施步骤

**Step 1｜迁移（后端）**
- `2026_09_20_000064_create_fulfillment_orders_table.php`：字段按设计文档 §4.1 落地，`status` + `push_request_id` + `push_times` + `last_push_at` + `last_push_error` + `tracking_no` + `carrier_code/name` + `buyer_info(json)` + `shipping_info(json)` + `extend(json)` + 时间戳；索引：`UNIQUE(order_id)`（一单一发货单）、`UNIQUE(outbound_no)`、`INDEX(warehouse_id,status)`
- `2026_09_20_000065_create_fulfillment_order_items_table.php`：`fulfillment_order_id, sku_id, platform_sku_code, wms_sku_code, product_name, qty, shipped_qty, barcode`，FK 级联删除
- `2026_09_20_000066_add_wms_fields_to_orders_table.php`：`warehouse_id nullable`（FK → warehouses）、`fulfillment_status nullable`；ⓘ **不要**同时塞太多无关列，保持单一职责

写完执行 `php artisan migrate --force`（PG 开发库）。

**Step 2｜单号（后端）**
- `NoGeneratorService` 增 `PREFIX_FULFILLMENT = 'FO'`（注意：与既有 `CS/PAY/RF/RC/TK` 不冲突），并在 `$map` 中登记 `['fulfillment_orders', 'outbound_no']` 供唯一校验；加 `outboundNo()` 便捷方法

**Step 3｜模型与状态机（后端）**
- `app/Models/FulfillmentOrder.php`：
  - 状态常量：`STATUS_CREATED/PENDING_PUSH/PUSHING/PUSHED/PICKING/PACKED/SHIPPED/COMPLETED/CANCELLED/EXCEPTION/PUSH_FAILED`
  - `TRANSITIONS` 数组 + `STATUS_LABELS`（中文，列表/导出用）
  - `canTransitTo()` 静态方法（返回 bool）
  - 关系：`items()`、`order()`、`warehouse()`
- `app/Models/FulfillmentOrderItem.php`
- ⚠️ Laravel 12 属性写法：`protected function casts(): array`（勿再用 `$casts` 属性，避免与项目新写法不一致——以现有模型为准）

**Step 4｜履约核心服务（后端）**
- `app/Services/Wms/FulfillmentOrderService.php`：
  - `createForOrder(Order $order, ?int $warehouseId = null): FulfillmentOrder`
    - 幂等：存在则直接返回；若已 `Cancelled` 且订单回到待发货，则允许重建或复用（明确规则：**同一订单已取消的发货单不复用，按 `order_id + status != cancelled` 唯一**）
    - 快照：`buyer_info` 取自 `orders.address_snapshot`，`shipping_info` 存备注/承运商
    - 行：取 `order_items` 逐行，`wms_sku_code` 由 `WmsConfigService::resolveSkuCode()` 解析（`manual` 缺映射 → 抛 40009 并记录 `Exception`）
    - 事务内一起写主子表，避免半成品
  - `markPendingPush() / markPushing() / markPushed(?string $wmsOutboundNo) / markPushFailed(string $err) / markPicking() / markPacked()`
  - `markShipped(FulfillmentOrder $fo, string $carrierCode, string $carrierName, string $trackingNo, array $shippedQty = [], ?\DateTimeInterface $shippedAt = null): FulfillmentOrder`
    - 内部调用 `OrderService::shipForShipment()` 完成订单状态流转 + 写 `shippings`
    - 重复回传按 `tracking_no` 相同视为幂等成功（不报错）
  - `cancel(FulfillmentOrder $fo, string $reason, ?int $operatorId)`：仅 `PendingPush/Pushing/Pushed/Picking` 可取消
  - `retryPush()`：重置 `PushFailed → PendingPush` 并重新派发 Job（含调用方记录操作日志）
  - 统一私有 `transitionTo()`：事务 + `lockForUpdate` + `canTransitTo`（非法流转 `BusinessException::conflict` 40009）

**Step 5｜触发点接线（后端）**
- 推荐做法：**事件解耦**。在 `OrderService::acceptForShipment()` 成功流转后派发新事件 `OrderAcceptedForShipment`（携带 `$order`），由 `app/Listeners/Wms/CreateFulfillmentOrder.php` 监听并执行 `createForOrder()`；若配置 `enabled && auto_push` 则 `PushOutboundJob::dispatch()`
- 备选（若不愿改 OrderService）：在 `PaymentService` 调 `acceptForShipment()` 之后补一行调用
- ⚠️ **禁止在订单事务内做 HTTP 调用**：Listener 里只建单 + 派发 Job，Job 在事务提交后执行（`afterCommit` 语义：`dispatch()->afterCommit()` 或在 Listener 标记 `public $afterCommit = true`）

**Step 6｜推送 Job（后端）**
- `app/Jobs/Wms/PushOutboundJob.php`：
  - `public int $tries`（由 `push_retry_times` 传入，上限兜底 10）、`backoff([10, 60, 300])`
  - 流程：`PendingPush → Pushing` → 组装 `OutboundDto`（`request_id = Str::uuid()`）→ `WmsAdapterFactory::make($config)->createOutbound($dto)` → 成功 `Pushed` + 记 `wms_outbound_no`；失败累加 `push_times`、写 `last_push_error`，未超限保留 `PushFailed`（或 PendingPush 等待下次）并重试，超限置 `PushFailed`
  - 幂等：同一 `outbound_no` + `request_id` 已在 WMS 侧存在时视为成功（P2 处理 `code=重复单据` 语义）
  - 失败报文必须写 `wms_api_logs`（脱敏）
- 兜底命令：`php artisan wms:drain`（同步执行 PendingPush 队列，供无 worker 环境/手工重推）

**Step 7｜后台接口（后端 + 路由）**
```
GET  /api/admin/wms/fulfillment-orders               permission:wms.order.view
GET  /api/admin/wms/fulfillment-orders/{id}          permission:wms.order.view
POST /api/admin/wms/fulfillment-orders/{id}/push     permission:wms.order.manage
POST /api/admin/wms/fulfillment-orders/{id}/cancel   permission:wms.order.manage
```
- 控制器 `app/Http/Controllers/Admin/WmsFulfillmentController.php`
- 列表支持按 `status / warehouse_id / outbound_no / order_no` 筛选；分页走 `ApiResponse::paginated()`（后台可给 total）
- 每个写操作落 `sys_operation_log`

**Step 8｜联内自测脚本**
- `docs/testing/smoke_test.sh` 增加 WMS 内部闭环冒烟：下单支付（可用既有订单接口）→ 断言 `fulfillment_orders` 生成 → `queue:work --once` → 断言状态 `Pushed`（Mock）→ 模拟回传 → 断言订单 `shipped`

---

## 4. 测试

### 4.1 单元测试（Pest）
- `tests/Feature/WmsFulfillmentApiTest.php`（≥12 例）
  - 支付成功后自动创建发货单（Mock 事件/Listener）
  - 重复支付回调不会重复建单
  - `manual` 模式缺 SKU 映射 → 发货单 `Exception` 且有明确原因
  - 未启用 WMS（enabled=false）→ 不建单，订单流程不受影响（回归保护）
  - 后台列表筛选、详情行项目齐全
  - 取消：合法状态成功 / `Shipped` 状态取消 → 409
  - 重推：`PushFailed → PendingPush` 且 `push_times` 递增
  - 无 `wms.order.manage` → 403
- `tests/Unit/FulfillmentOrderStateMachineTest.php`（≥10 例）：每条非法流转均抛 409；`canTransitTo` 覆盖矩阵；`STATUS_LABELS` 覆盖所有状态
- `tests/Unit/PushOutboundJobTest.php`（≥5 例，用 `Queue::fake()` + `Bus::fake()`）：
  - 成功 → `Pushed`；Adapter 抛异常 → 重试次数到达后 `PushFailed`；`tries` 来自配置
  - Job 幂等：同一 `request_id` 重复执行不产生第二张外部单据（Mock adapter 计数）
- `tests/Feature/OrderShipExistingFlowTest.php`（回归，≥3 例）：确认 `BatchShipService` 手工发货、导出、通知链路未被破坏

### 4.2 回归测试（必跑）
- `php -d memory_limit=1G vendor/bin/pest` ≥ **P0 基线 + 本阶段新增**，零失败
- `git stash`-free 校验：核心链路用例 `tests/Feature/OrderApiTest`、`PaymentApiTest`、`RefundApiTest`、`ShippingApiTest` 全绿
- admin/web：`vue-tsc -b` 0 error + vitest 无新增失败

### 4.3 集成测试
| 场景 | 步骤 | 期望 |
|---|---|---|
| Mock 全链路 | 下单 → 支付（mock gateway） → 触发 Listener | `fulfillment_orders` 1 行，`status=pending_push` |
| 异步推送 | `php artisan queue:work --once` | 状态 `pushed`，`wms_api_logs` 1 条 outbound success |
| 失败重试 | Mock adapter 强制抛错 | 3 次重试后 `push_failed`，`last_push_error` 有值 |
| 回传发货（预留接口） | 调用 `FulfillmentOrderService::markShipped()`（P3 前用 tinker 触发） | 订单 → `shipped`，`shippings` 落运单，`OrderShipped` 事件发出 |
| 关闭 WMS | `enabled=false` 下单支付 | 不生成发货单，原有流程完全不变 |

---

## 5. 验收清单

- [ ] 3 张迁移合入并已在 PG 开发库执行
- [ ] 支付成功自动生成发货单（Mock 全链路跑通）
- [ ] 状态机非法流转统一返回 409/40009，且 `Transition` 有日志
- [ ] `markShipped()` **只通过** `OrderService::shipForShipment()` 改订单状态（代码评审确认无第二处写 order.status）
- [ ] 一单一发货单，重复回调不产生重复单据
- [ ] Job 重试与 `wms:drain` 兜底命令可用
- [ ] 关闭 WMS 配置后，既有履约链路行为零变化（重要！）
- [ ] 单元测试 / 回归 / 集成测试全部通过
- [ ] 代码按「后端」一个 commit 提交（本阶段无前端改动则跳过前端 commit）

### 验收记录
| 日期 | 人 | 结果 | 备注 |
|---|---|---|---|
|  |  |  |  |

---

## 6. 完成情况

- [ ] Step 1 迁移（3 张 + PG 同步）
- [ ] Step 2 单号前缀 `FO`
- [ ] Step 3 模型与状态机
- [ ] Step 4 `FulfillmentOrderService`
- [ ] Step 5 事件/Listener 接线（`afterCommit`）
- [ ] Step 6 `PushOutboundJob` + `wms:drain`
- [ ] Step 7 后台接口 + 路由
- [ ] Step 8 冒烟脚本补充
- [ ] 单元测试通过
- [ ] 回归测试通过
- [ ] 集成测试通过
- [ ] 验收清单全勾选

**阶段状态**：⬜ 未开始 → 完成后改为 ✅ 并同步 `README.md` §4
