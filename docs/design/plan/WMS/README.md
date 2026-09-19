# 菜鸟 WMS 对接（含退货闭环）开发实施计划

**依据**：`docs/design/CubeShop_WMS_Integration_Cainiao_JD_v1.0.md`（v1.1 设计稿）
**本期范围**：**只做菜鸟**（奇门仓配），打通「支付 → 出库 → 回传运单 → 订单发货」+「退款审核 → 退货入库 → WMS 收货 → 库存恢复」完整闭环
**后续**：菜鸟闭环验收通过后，按同一套 Adapter 契约复制京东云仓（见 `stage-P8-jd-cloud.md` 占位文档）

| 项 | 值 |
|---|---|
| 计划版本 | v1.0 |
| 创建日期 | 2026-09-19 |
| 阶段数 | P0 ~ P7（菜鸟），另 P8（京东，未排期） |
| 总工期预估 | 约 6～7 周（含联调用友/菜鸟沙箱等待窗口） |
| 状态 | 🟨 进行中（P0、P1、P2 已完成；P4 退款领域能力已补） |

---

## 1. 本期目标

1. 后台可按**仓库**配置菜鸟 WMS 对接参数、SKU 映射、自动推送开关（对应设计文档 §3）。
2. 支付成功后自动生成履约发货单并推送菜鸟创建出库单（§5.1）。
3. 菜鸟回传发货结果（运单号）后，平台自动完成订单发货动作（§7.2）。
4. 出库单在出库前可取消并对接菜鸟取消接口（§5.2 / §7.3）。
5. **退货闭环**：退款审核通过 → 生成退货入库单 → 推送菜鸟 → 菜鸟收货回传 → 按实收恢复库存 → 推进退款完成（§5.3 / §7.4 / §7.5）。
6. 全链路幂等、可重试、可观测（§10）：请求日志、推送失败重推、欠对账告警。

**不在本期范围**：京东云仓 Adapter、库内作业可视化、盘点回写、面单打印、海外仓。

---

## 2. 现有 CubeShop 能力盘点（写代码计划前的事实基线）

> 以下均为当前仓库真实存在的文件/方法，后续各阶段的接线点以此为准。

### 2.1 库存
- 表：`inventories`（`sku_id, stock, locked_stock`）、`inventory_logs`
- 服务：`app/Services/Inventory/InventoryService.php`
  - `getStock()` / `isSufficient()` / `getStockMap()`
  - `lock()` 下单锁库存、`release()` 取消释放、`deduct()` 支付确认扣减、`adjust()` 人工调整（记日志）
- 调用点：`PaymentService` 支付成功入账时对每个 `order_items.sku_id` 执行 `deduct()`

### 2.2 发货
- 表：`shippings`（`order_id, company_code, company_name, tracking_no, trace_status, shipped_at, delivered_at`）、`shipping_traces`、`express_companies`（`code/name/channel_code`）
- 服务：
  - `OrderService::acceptForShipment()` —— `paid → pending_ship`（支付成功后由 `PaymentService` 自动调用）
  - `OrderService::shipForShipment()` —— **发货唯一执行点**，事务内流转 `pending_ship → shipped` + 写 `shippings` + 回填 `orders.express_company/tracking_no` + 派发 `OrderShipped` 事件
  - `BatchShipService`（Excel 批量发货）、`TracePullService`（第三方轨迹拉取，去重口径 `shipping_id+occurred_at+context`）
- 订单状态机：`Order::TRANSITIONS`（**禁止 `paid → shipped` 直达**，必须先到 `pending_ship`）

### 2.3 退款/售后
- 表：`refunds`（`refund_no, order_id, order_no, user_id, amount, reason, status, admin_remark, processed_by, processed_at, refund_details`）
- 服务：`RefundService::apply()/maxRefundableAmount()/process(approve|reject)`
- **现状限制**：只有「退款」没有「退货」——无退货类型、无退货物流、无退货明细、审核通过后**不回库存**。WMS 退货闭环必须先补这部分能力（见 P4）。
- **✅ 已补（为 WMS 打基础，2026-09-19）**：扩展 `refunds` 增加 `type(refund/return_refund)`、`warehouse_id`、`return_tracking_no`、`return_express_company`、`return_status`、`return_details`、`return_received_details`、`return_received_at`、`return_exception_reason`；`RefundService` 支持退货退款申请（`apply` opts）、审核分叉（退货退款 approve 停在 `approved`+`waiting_return`、**不立即放款**）、后台 `receiveReturn()` 确认收货按实收正品回库存并推进退款完成（残次不回、差异记录、幂等）。storefront 申请、web 申请页/详情展示、admin 审核+确认收货均已打通。**未做**：WMS 推送/回调/`return_inbound_orders` 表（真正接菜鸟时 P4 Step4-6 的事）。

### 2.4 基础设施
- 队列：`config/queue.php` 默认 `database`，`jobs` 表迁移已存在（无独立 worker 脚本，需自行保障 `queue:work`）
- 单号：`app/Services/Common/NoGeneratorService.php`（`PREFIX_ORDER='CS'`、`PREFIX_REFUND='RF'`、`PREFIX_TICKET='TK'`，含表/列唯一校验 + 重试）
- 权限：`database/seeders/RolePermissionSeeder::PERMISSIONS` 为唯一定义源；⚠️ **新增权限码必须同改 Seeder（新装）与幂等迁移（存量）**
- 审计：操作日志 `sys_operation_log`（`$this->operationLog->record(...)`）+ `order_logs`
- 加密：无专用工具，用框架 `Crypt::encryptString()`；参考 SEC-01 约定——密钥配置无默认值、Mock 空密钥 fail-closed

### 2.5 尚不存在（需新建）
- **✅ 已建（P0，2026-09-19）**：仓库 `warehouses`、WMS 配置 `wms_configs`、SKU 映射 `wms_sku_mappings`、WMS 调用日志 `wms_api_logs`，以及后台 `/api/admin/wms/*` 配置能力（含 Mock 连通性测试、回调地址生成、权限码 `wms.*`）。
- **✅ 已建（P1，2026-09-19）**：履约发货单 `fulfillment_orders` / `fulfillment_order_items`（一单一发货单、状态机、推送重试、`wms:drain` 兜底），`orders.warehouse_id` / `orders.fulfillment_status` 挂载列，以及后台 `/api/admin/wms/fulfillment-orders*` 运营接口。
- **✅ 已建（P2，2026-09-19，无新表）**：菜鸟 Adapter（`CainiaoAdapter`）+ 奇门签名（`Signature`）+ HTTP 网关（`CainiaoGateway`）+ 错误码映射（`CainiaoErrorCode`）+ 报文归一（`CainiaoNormalizer`）+ 脱敏（`PayloadMasker`）+ `config/wms.php` + `wms:probe` 命令；工厂改为「凭证齐备才走真实网关」（sandbox/prod 同一实现），生产缺凭证 fail-closed。
- **仍需新建**：退货入库单 `return_inbound_order`（P4），以及独立于 `/api/admin` 之外的公开回调入口 `/api/wms/callback/*`（P3）。

---

## 3. 设计稿 → 本项目的技术决策（差异收敛）

| 编号 | 设计文档 | 本项目决策 | 理由 |
|---|---|---|---|
| D1 | 表均带 `tenant_id`，多租户 | **暂不引入租户列**；预留注释与后续迁移 | CubeShop 当前单商户，引入会增加全表外键与查询复杂度，收益为零 |
| D2 | 配置挂在仓库上 | 新建 `warehouses` 表 + `wms_configs(unique(warehouse_id))`；Seeder 预置 1 个默认仓库并可为订单回填 `warehouse_id` | 保证既有多仓扩展位，又不需要一次性改造全部商品/库存模型 |
| D3 | 退货以「售后单 `after_sale_*`」为源 | **扩展 `refunds`**：增加 `type(refund/return_refund)`、`warehouse_id`、`return_tracking_no`、`return_status`、`return_details`；`after_sale_no` 等价 `refund_no` | 避免与现有 `RefundService`/`refund.view`/`refund.process` 权限双轨分裂 |
| D4 | 独立的履约单状态机 | 新增 `fulfillment_orders` / `return_inbound_orders` 及其状态机；**订单侧一律复用 `OrderService::shipForShipment()` / `transitionTo()`，不另写订单状态流转** | 遵守项目既有「订单唯一执行点」铁律 |
| D5 | 消息队列异步 + 死信 | 一期用 `database` 驱动 + `Job` 重试（`tries`/`backoff`），失败落 `PushFailed` 状态由后台人工重推；预留切 `redis` 的配置位 | 当前环境无 Redis 依赖，避免为基建买单 |
| D6 | `app_secret_enc` 字段 | `Crypt::encryptString()` 存 `TEXT`；接口返回一律 `***` 掩码，**只写不读** | 与 SEC-01「密钥不落地明文」一致 |
| D7 | 回调 `POST /api/v1/wms/callback/cainiao` | 项目内统一：`POST /api/wms/callback/cainiao`（公开路由，加 `throttle`），内部按 method 路由到 handler | 与现有 `/api/...` 前缀风格一致，避免另起 `/api/v1` |
| D8 | 字段 `JSONB` | 迁移用 `json`（Laravel `jsonb()` 在 PG 才是 jsonb，SQLite(test) 只当 text）——实际写法用 `$table->json()` 兼容双库 | Pest 跑 SQLite in-memory，避免类型不兼容 |
| D9 | 一仓一 WMS | 保留 provider 枚举与工厂，京东配置字段先建（可空），P8 启用 | 迁移一次到位，避免二次改表 |

---

## 4. 阶段总览与进度

| 阶段 | 主题 | 工期 | 状态 |
|------|------|------|------|
| [P0](stage-P0-foundation.md) | 仓库/WMS 配置/SKU 映射数据模型 + 后台配置能力 | 1 周 | ✅ 已完成（2026-09-19） |
| [P1](stage-P1-fulfillment-core.md) | 履约发货单内核 + 与订单/库存接线（无外部依赖可跑通） | 1 周 | ✅ 已完成（2026-09-19） |
| [P2](stage-P2-cainiao-adapter.md) | 菜鸟 Adapter：签名/网关/创建出库/取消出库 + Mock 模式下check | 1.5 周 | ✅ 已完成（2026-09-19，沙箱真实跑通待账号） |
| [P3](stage-P3-callback.md) | 回调入口：验签/幂等 + 发货回传 + 出库状态回传 | 1 周 | ⬜ 未开始 |
| [P4](stage-P4-return-inbound.md) | **退货入库闭环**（含退款扩展、库存恢复） | 1.5 周 | 🟨 进行中（退款领域能力已扩展，WMS 推送/回调/`return_inbound_orders` 待接入） |
| [P5](stage-P5-inventory-sync.md) | 库存查询/同步 + 对账任务 + 降级开关与监控 | 0.5 周 | ⬜ 未开始 |
| [P6](stage-P6-admin-console.md) | 后台运营页面：发货单/退货入库单/日志/重推 | 1 周 | ⬜ 未开始 |
| [P7](stage-P7-uat-and-delivery.md) | 沙箱联调、异常演练、性能、交付与验收 | 1 周 | ⬜ 未开始 |
| [P8](stage-P8-jd-cloud.md) | 京东云仓 Adapter（**菜鸟验收通过后启动**） | 2 周 | ⏸ 冻结（未排期） |

**状态图例**：⬜ 未开始 · 🟨 进行中 · ✅ 已完成 · ⏸ 冻结

---

## 5. 通用工程约定（每个阶段都要遵守）

1. **迁移双库**：新增迁移后必须 `cd backend && php artisan migrate --force` 同步本地 PG 开发库（`cubeshop`）；Pest 只跑 SQLite 内存库不同步开发库。
2. **测试基线**：后端基线 **994 passed**（全量命令 `php -d memory_limit=1G vendor/bin/pest`）；每个阶段收尾必须全量回归且只允许净增用例，不允许出现新的失败。
3. **前端校验**：`admin`/`web` 各自 `npx vue-tsc -b` 零错误 + `npx vitest run --fileParallelism=false`（串行避开历史 flaky）。
4. **权限码**：新增权限必须同时改 `RolePermissionSeeder::PERMISSIONS` 与对存量的幂等迁移，并在 admin 侧栏注册。
5. **日志与审计**：所有后台写操作走 `operationLog->record()`；所有 WMS 出入站报文写入 `wms_api_logs`（含 `request_id`、脱敏后的关键字段）。
6. **提交粒度**：按「后端 / admin / web」拆 commit，逐文件核对归属，**禁止 `git add -A`**，提交信息不带 `Co-authored-by` 尾注。
7. **接口命名**：后台 `/api/admin/wms/...`（走 `permission:wms.*`），公开回调 `/api/wms/callback/...`（走 `throttle` + 验签，不放 `auth:sanctum` 组内）。

---

## 6. 风险登记

| 风险 | 影响 | 应对 | 负责阶段 |
|---|---|---|---|
| 奇门接口字段/版本变化 | Adapter 报错、联调返工 | Adapter 隔离 + 字段 mapping 配置化（`extra_config` 存 版本号），保留文档快照 | P2/P7 |
| 沙箱账号/白名单申请周期长 | 阻塞联调 | P0 起并行申请；先用 Mock Adapter 打通内部闭环。**⚠️ 截至 P2 完成，沙箱账号仍未到位**，P2 用 fixture + `Http::fake()` 锁定，真实跑通待账号 | P0/P2 |
| 回调验签失败/重放 | 收不到回传、订单不发货 | 本地先打印验签中间串核对；`nonce` 缓存去重；失败落 `wms_api_logs` 可重放 | P3 |
| 一单多包裹 | 运单号覆盖 | `packages` 数组全量落 `shipping_packages`，主单取首个 | P3 |
| 退货实收 ≠ 应退 | 库存/金额偏差 | 按实收恢复库存 + 差异记录，退款金额以原退款单为准，人工介入 | P4 |
| 队列 worker 未启动 | 出库单卡在 PendingPush | 提供 `wms:drain` 同步兜底命令 + 健康巡检 | P2/P5 |
| 库存双边不一致 | 超卖 | 平台库存为准售卖；WMS 库存仅做展示与校准，差异告警不自动改可售 | P5 |

---

## 7. 完成标记规则

每个阶段的「完成情况」区必须全部勾选后，才允许把该阶段状态从 🟨 改为 ✅，并同时更新本文件 §4 进度表。判定门槛：

- [x] 实施步骤 1..N 全部执行且代码已提交（非本地未提交）
- [x] 单元测试通过（新增用例全绿）
- [x] 回归测试通过（后端全量 pest ≥ 994 + 净增；前端 vitest 无新增失败）
- [x] 集成测试通过（按阶段集成脚本跑通，含一次失败/重试路径）
- [x] 验收清单全部勾选（含人工核对项）
- [x] 阶段验收人签字（本地自测场景下为使用者确认）
