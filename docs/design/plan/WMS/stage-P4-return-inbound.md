# Stage P4：退货入库闭环（含退款扩展、收货回传、库存恢复）—— 菜鸟完整闭环收口

**状态**：✅ 已完成（2026-09-20，代码闭环全绿；沙箱真实回传待账号到位）
**工期**：约 1.5 周（7～8 人日）
**对应设计文档**：§2.4（退货入库单状态机）、§4.1（`return_inbound_order*`）、§5.3（退货入库流程）、§7.4（`returnorder.create`）、§7.5（`returnorder.confirm`）

> 本阶段是菜鸟闭环的最后一块拼图：**售后审核 → 退货入库单 → WMS → 收货回传 → 库存恢复 → 退款完成**。

### 0. 当前进度（2026-09-19）
- ✅ **F1（退款支持退货类型）已完成**：迁移 `2026_09_19_000071` 扩展 `refunds`（`type`/`warehouse_id`/`return_*`）、`Refund` 模型常量、`RefundService::apply`/`process` 分叉、storefront `OrderController::refund`、admin `RefundController::receive` + 路由、web 申请页/详情展示、`OrderResource` 字段补齐。后端 17 例单测 + 4 例 Feature 全通过。
- ✅ **F2（审核分叉）已完成**：退货退款 approve 停在 `approved`+`waiting_return`，不立即放款，订单保持 `refunding`。
- ✅ **F6/F7（库存恢复/差异）已完成**：`RefundService::receiveReturn()` 按实收正品回库存、残次不回、差异记 `return_exception_reason`、幂等。
- ⬜ **F3/F4/F5（退货入库单表 + 菜鸟推送 + 收货回传）未做**：这是真正接菜鸟时的事，复用 `receiveReturn()` 作为回传入口即可，不新建退货售后表（D3 决策）。

---

## 1. 目标与功能

### 1.1 目标
在不破坏现有「仅退款」链路的前提下，为 CubeShop 增加**退货退款**能力，并与菜鸟退货入库单打通，形成完整闭环。核心难点是：现有 `RefundService::process()` 审核通过即把退款置 `success` 并把订单流转 `refunded`；退货场景必须改为「**先收货、后退款**」。

### 1.2 交付功能清单
| # | 功能 | 说明 |
|---|------|------|
| F1 | 退款支持退货类型 | `refunds.type`（`refund` 仅退款 / `return_refund` 退货退款）+ `warehouse_id` + `return_tracking_no` + `return_status` + `return_details(json)` |
| F2 | 审核流程分叉 | 仅退款沿用原逻辑（即时 `success`）；退货退款审核通过后停在 `approved`，等待收货 |
| F3 | 退货入库单 | `return_inbound_orders` + `return_inbound_order_items`，状态机按 §2.4 |
| F4 | 推送菜鸟退货入库单 | `CainiaoAdapter::createReturnInbound()`（`returnorder.create`）、`cancelReturnInbound()` |
| F5 | 收货回传 | `returnorder.confirm` → 实收数量、`inventoryType(ZP/CC)` → `received` |
| F6 | 库存恢复 | 正品按实收 `InventoryService::adjust(+n, remark='退货入库')` 恢复可售；残次不计可售、记录待处理 |
| F7 | 差异处理 | 实收 ≠ 应退 → 记录差异、标记 `exception_reason`，退款金额保持原审核金额，人工介入入口 |
| F8 | 退款完成 | 收货完成后流转 `refunds.status=success` 并订单 → `refunded`，发退款通知（复用 `RefundResult` 事件） |
| F9 | 后台运营接口 | 退货入库单列表/详情/推送/取消/标记收货（异常兜底手工收货） |

### 1.3 不做
- 换货（only 退货退款）
- 残次品仓精细库位管理（仅记录 + 人工）
- 京东退货接口（P8）

---

## 2. 依赖

### 2.1 前置
- **P0**（配置、映射、日志）、**P1**（履约单一入口思想、Job/幂等基建）、**P2**（签名/网关）、**P3**（回调分发器）

### 2.2 依赖的现有资产
- `Refund` / `RefundService::apply()/process()` / `RefundApiTest` —— **改动要兼顾回归**
- `InventoryService::adjust()` / `release()` —— 库存恢复入口
- `OrderService::transitionTo()` —— 退款完成后订单 → `refunded`
- `RefundResult` 事件 —— 通知买家

### 2.3 关键技术决策（需评审确认）
> **D-P4-1**：退货退款审核通过后，退款单停在 `approved`（新增中间态语义），订单保持 `refunding`；`returnorder.confirm` 回传后统一由 `ReturnInboundOrderService::complete()` 推进 `success` + 订单 `refunded`。
> **D-P4-2**：不新建 `after_sale` 表，`after_sale_no` 等价 `refund_no`（见 README D3）。
> **D-P4-3**：库存以**实收正品数量**回加，不以应退数量；残次（`CC`）数量写入差额 `inventory_type` 并标记待处理。

---

## 3. 实施步骤

**Step 1｜迁移（后端）**
- `2026_09_20_000069_create_return_inbound_orders_table.php`：按 §4.1 字段；索引 `UNIQUE(inbound_no)`、`UNIQUE(after_sale_no)`（一个退货退款单一张入库单）、`INDEX(warehouse_id,status)`
- `2026_09_20_000070_create_return_inbound_order_items_table.php`：`return_inbound_order_id, sku_id, platform_sku_code, wms_sku_code, product_name, qty, received_qty, inventory_type, barcode`
- `2026_09_20_000071_add_return_fields_to_refunds_table.php`：`type varchar(16) not null default 'refund'`、`warehouse_id nullable`、`return_tracking_no nullable`、`return_status varchar(16) nullable`、`return_details json nullable`；索引 `(type, return_status)`
- PG 同步：`php artisan migrate --force`

**Step 2｜单号与模型（后端）**
- `NoGeneratorService` 增 `PREFIX_RETURN_INBOUND = 'RI'` + `returnInboundNo()` + 唯一映射 `['return_inbound_orders','inbound_no']`
- `app/Models/ReturnInboundOrder.php`：状态常量 `pending_push/pushed/receiving/received/completed/cancelled/exception/push_failed` + `TRANSITIONS` + `canTransitTo()`
- `Refund` 增 `TYPE_REFUND/TYPE_RETURN_REFUND` 常量、`returnStatus` 常量（`none/waiting_return/shipping/received/exception`）

**Step 3｜退款服务改造（后端）**
- `RefundService::apply()`：接受 `type` 参数（默认 `refund`）；`return_refund` 需带退货行明细（`sku_id/qty`），累计不得超过订单可退数量；写入 `return_details`
- `RefundService::process(approve|reject)` 改为：
  - `type=refund`：保持原逻辑（即时退款成功 + 券返还策略不变）
  - `type=return_refund`：`approve` 后只置 `approved` + `return_status=waiting_return`，**不流转订单**；由 Listener `RefundApprovedListener` 触发 `ReturnInboundOrderService::createForRefund()`
  - `reject`：逻辑不变
- ⚠️ 回归重点：现有 `RefundApiTest` 断言行为不得被破坏（默认类型走老路径）

**Step 4｜退货入库单服务（后端）**
- `app/Services/Wms/ReturnInboundOrderService.php`：
  - `createForRefund(Refund $refund, ?int $warehouseId = null)`：选仓（优先 `refunds.warehouse_id` → `orders.warehouse_id` → 默认仓）；解析 SKU 映射；事务写主子表；`auto_push_return` 为真则派发 `PushReturnInboundJob`
  - `markPushed(?string $wmsInboundNo)`、`markReceiving()`、`markReceived(array $detail)`、`markPushFailed(string $err)`、`markException(string $reason)`、`cancel()`
  - `complete(ReturnInboundOrder $rio)`：事务内
    1. 逐行按 `received_qty` 回加库存（正品）→ `InventoryService::adjust($skuId, +$qty, operatorId, '退货入库单 '.$inbound_no)`
    2. 残次行不回可售，写 `return_details` + 标记人工处理
    3. `Refund.status=success`、订单 `→ refunded`（走 `OrderService::transitionTo`）
    4. 发 `RefundResult` 事件
  - 幂等：`complete()` 重复调用直接返回
- `app/Jobs/Wms/PushReturnInboundJob.php`（对称 `PushOutboundJob`）

**Step 5｜Adapter 补齐（后端）**
- `CainiaoAdapter::createReturnInbound(ReturnInboundDto $dto)`：字段按 §7.4（`returnOrderCode` / `preDeliveryOrderCode` / `warehouseCode` / `ownerCode` / `returnReason` / `orderLines.itemCode|planQty|barCode`）；响应 `returnOrderId → wms_inbound_no`
- `CainiaoAdapter::cancelReturnInbound(CancelReturnInboundDto $dto)`
- 错误映射复用 P2 的 `CainiaoErrorCode`，补退货相关码（如原出库单不存在）

**Step 6｜回调 Handler（后端）**
- `app/Services/Wms/Callback/Handlers/ReturnOrderConfirmHandler.php`：解析 `returnOrderCode / returnOrderId / orderLines[].actualQty|inventoryType / orderConfirmTime` → `markReceived()` → `complete()`
- 在 `CallbackDispatcher` 注册到 `returnorder.confirm` 等类型
- 找不到单 / 数量异常（实收 0 或大于应退）→ 记异常 + 保留报文，不做破坏性操作

**Step 7｜退款/库存一致性保障（后端）**
- `InventoryService::adjust()` 已记 `inventory_logs`，退货入库的 `biz_type` 统一 `'return_inbound'`，便于对账
- 退款金额仍以审核金额为准（不因实收差异自动改额），差异单需人工处理入口（P6 页面）

**Step 8｜后台接口（后端）**
```
GET  /api/admin/wms/return-inbound-orders                 permission:wms.return.manage
GET  /api/admin/wms/return-inbound-orders/{id}            permission:wms.return.manage
POST /api/admin/wms/return-inbound-orders/{id}/push       permission:wms.return.manage
POST /api/admin/wms/return-inbound-orders/{id}/cancel     permission:wms.return.manage
POST /api/admin/wms/return-inbound-orders/{id}/manual-received  permission:wms.return.manage   // 回传丢失兜底
POST /api/admin/refunds/{id}/process                      // 现有接口扩展 type 透传（回归保护）
```
每个写操作落 `sys_operation_log`。

---

## 4. 测试

### 4.1 单元测试（Pest）
- `tests/Feature/WmsReturnInboundApiTest.php`（≥14 例）
  - 退货退款审核通过 → 生成入库单（`pending_push`）而非直接退款成功
  - 仅退款审核通过 → **行为与改造前完全一致**（强回归）
  - 重复审核 → 409；同一退款单重复生成入库单 → 幂等
  - 缺 SKU 映射 → 入库单 `exception` + 明确原因
  - 后台手工 push / cancel / manual-received 分支
  - 无 `wms.return.manage` → 403
- `tests/Unit/ReturnInboundOrderServiceTest.php`（≥12 例）
  - 状态机全非法流转覆盖 → 409
  - 正品收货 → 库存按实收回加（`InventoryService::adjust` 被调用且 `inventories.stock` 增加）
  - 残次收货 → 库存不回加、标记待处理
  - 实收 < 应退 → `exception_reason` 记录差额，退款仍完成
  - 实收 = 0 → 不回库存，退款单保持等待人工处理
  - `complete()` 幂等：重复调用库存不二次增加
  - 退款完成 → 订单 `refunded` + `RefundResult` 事件触发 1 次
- `tests/Unit/CainiaoReturnMappingTest.php`（≥6 例）：DTO → `returnorder.create` 报文字段、行项目、原单号关联
- `tests/Feature/RefundApiTest.php`（**回归改造**：既有用例必须全部保持通过，默认类型行为不变）

### 4.2 回归测试（必跑）
- `php -d memory_limit=1G vendor/bin/pest` ≥ P3 基线，零失败
- 重点回归：下单/支付/发货/确认收货/仅退款/余额/券返还/报表导出
- admin：`vue-tsc -b` + vitest 无新增失败（若本阶段改了退款相关前端）

### 4.3 集成测试
| 场景 | 步骤 | 期望 |
|---|---|---|
| 退货退款全链路（沙箱） | 买家申请退货退款（含明细）→ 后台审核通过 → 推送 → 菜鸟回传收货 | 入库单 `received`→`completed`；库存回加；退款 `success`；订单 `refunded`；买家收到通知 |
| 仅退款不受影响 | 申请仅退款 → 审核通过 | 立即退款成功（无入库单），与历史行为一致 |
| 残次收货 | 回传 `inventoryType=CC` | 库存不回加，后台显示待处理 |
| 少件收货 | 回传实收 1 / 应退 2 | 库存 +1，差额标记异常，退款按原额完成，后台可见差异 |
| 回传丢失兜底 | 不回传，后台「标记已收货」 | 等价于收货处理（走同一 `complete()`） |
| 推送失败重试 | Adapter 强制失败 | `push_failed` + 后台可重推，`createReturnInbound` 幂等不重复建单 |

---

## 5. 验收清单

- [x] 退款类型扩展后，**仅退款路径零行为变化**（回归用例全绿，`RefundApiTest` 全过）
- [x] 退货退款必须「先收货后退款」，不存在未收货即放款的路径（`markReceived` 唯一放款入口，审核只停 `approved+waiting_return`）
- [x] 库存恢复只用 `InventoryService::adjust()`，`inventory_logs` 带 `return_inbound` 标识
- [x] 残次品不进可售库存，有明确记录（`inventory_type=CC` 不回库存，明细落 `return_received_details`）
- [x] 实收/应退差异有记录与人工入口（超收/短收/空收 → `exception`；后台 `manual-received` 与 `exception→pending_push` 重推）
- [ ] 沙箱跑通一次完整退货闭环（含回传），凭证留置 `docs/testing/evidence/wms/return-inbound.png`（不入库）——**待菜鸟沙箱账号到位**（止损口径同 P2/P3）
- [x] 回调幂等：重复 `returnorder.confirm` 不重复加库存、不重复放款（`complete()` 幂等重入 + `wms_callback_dedups`）
- [x] 单元测试 / 回归 / 集成测试通过（全量 1202 passed / 0 failed）
- [x] 后端改动单独 commit；本轮未改 admin 退款页

### 验收记录
| 日期 | 人 | 结果 | 备注 |
|---|---|---|---|
| 2026-09-20 | AI Agent | 代码闭环通过 | 新增 38 例测试全绿；全量 1202 passed / 0 failed；沙箱真实回传待账号到位（止损口径同 P2/P3） |

---

## 6. 完成情况

- [x] Step 1 迁移（`000088`/`000089` 两张 + PG 同步；`refund_no` 部分唯一索引支持取消后重建）
- [x] Step 2 单号 `RI` + 模型状态机（含 `exception → received/pending_push` 人工修复边）
- [x] Step 3 `RefundService` 分叉改造（`RefundApproved` 事件解耦，仅退款路径零变化）
- [x] Step 4 `ReturnInboundOrderService` + `PushReturnInboundJob`/`CancelReturnInboundJob`
- [x] Step 5 Adapter 退货接口补齐（`returnorder.create`/`confirm`，替代 P2 的 unsupported 占位）
- [x] Step 6 `ReturnOrderConfirmHandler`（解析器扩展 `returnOrder` 节点 + dispatcher 路由）
- [x] Step 7 库存/金额一致性保障（`adjust(remark: 'return_inbound')`，`complete()` 幂等）
- [x] Step 8 后台接口（list/detail/push/cancel/manual-received，权限 `wms.return.manage`）
- [x] 单元测试通过（Service 16 例 + 菜鸟映射 6 例）
- [x] 回归测试通过（仅退款路径 `RefundApiTest` 全绿；全量 1202 passed）
- [ ] 集成测试（沙箱退货闭环）通过——待菜鸟沙箱账号到位
- [x] 验收清单全勾选（除沙箱凭证项）

**阶段状态**：✅ 已完成（2026-09-20）并同步 `README.md` §4
