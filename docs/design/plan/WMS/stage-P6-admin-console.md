# Stage P6：后台运营页面（发货单 / 退货入库单 / 日志 / 重推）

**状态**：✅ 已完成（2026-09-20；沙箱真实联调待账号到位，口径同 P2～P5）
**工期**：约 1 周（5 人日）
**对应设计文档**：§3.3 页面 4（发货单 / 退货入库单列表）、§9.1（后台 API）、§10（日志保留）

---

## 1. 目标与功能

把 P1～P5 已经可用的接口做成运营可用的后台页面，让非研发也能完成日常运维：看单据、重推、取消、看失败原因、看库存差异。

| # | 页面 | 功能要点 |
|---|------|----------|
| F1 | 履约中心 / 发货单列表 | 按状态/仓库/单号/订单号筛选；状态徽标中文；`PushFailed` 行显示错误摘要 + 「重推」按钮；`TablePagination` 复用 |
| F2 | 发货单详情抽屉 | 基本信息（订单号、仓库、WMS 单号、运单号、承运商）、行项目（应发/实发）、推送流水（`push_times`、最后错误）、对应 API 日志（脱敏后） |
| F3 | 履约中心 / 退货入库单列表 | 同上，增加「关联退款单号」「退货原因」「实收/应退」列 |
| F4 | 退货入库单详情 | 行项目含 `inventory_type(ZP/CC)`；显示库存回加结果；差异提示与「手动标记收货」入口 |
| F5 | WMS 调用日志 | 按方向/接口/成功失败/时间筛选；详情弹窗展示脱敏报文；支持复制 `request_id` 便于对账 |
| F6 | 库存差异页 | 差异列表（P5 接口）、「按 WMS 校准」操作需二次确认；操作结果 toast + 审计 |
| F7 | 健康看板（轻量） | 展示 `wms:health` 结果：推送成功率、失败单量、回传延迟、队列积压（若已有 dashboard 则作为卡片嵌入） |
| F8 | 权限与菜单 | `wms.config.manage` / `wms.order.view` / `wms.order.manage` / `wms.return.manage` 三级控制；按钮级按权限隐藏 |

---

## 2. 依赖
- **前置**：P1（发货单接口）、P4（退货单接口）、P5（库存差异/健康接口）
- **可在 P1 之后提前开工**（接口先于页面完成即可），建议与 P4/P5 并行
- **前端规范**：页面骨架 `rounded-lg bg-white p-5 shadow-sm` + 纯文本 `h2` + 内联筛选行 + 朴素表格；统一用 `components/TablePagination.vue`；侧栏 icon 双注册（lucide import + icons 映射）

---

## 3. 实施步骤

**Step 1｜接口补齐（后端，若 P1/P4/P5 未覆盖）**
- 发货单/退货单详情接口补 `logs` 字段（最近 5 条 `wms_api_logs`）、`retry` 次数
- 重推接口返回统一结构；批量重推（可选，勾选多条）
- 所有写接口落 `sys_operation_log`

**Step 2｜API 封装（admin 前端）**
- `admin/src/api/wms.ts`：`getFulfillmentOrders/getFulfillmentOrder/pushFulfillmentOrder/cancelFulfillmentOrder`、`getReturnInboundOrders/.../manualReceived`、`getWmsLogs`、`getInventoryDiffs/resolveDiff`、`getWmsHealth`
- 类型定义与后端 field 对齐（`fulfillmentStatus` 中文映射放在前端 ` STATUS_LABELS` 常量）

**Step 3｜页面（admin 前端）**
- `admin/src/views/wms/FulfillmentOrderListView.vue`、`ReturnInboundOrderListView.vue`、`WmsApiLogView.vue`、`WmsInventoryDiffView.vue`
- 详情用 Drawer/Modal（与项目现有交互风格一致，不引入新 UI 库）
- 空态/加载态/错误态三态齐全；错误态给出 `request_id` 便于查日志
- ⚠️ 移动端不要求适配；宽度按后台 ≥1280 设计

**Step 4｜菜单与权限（admin 前端）**
- 侧栏「履约中心」分组：发货单、退货入库单、WMS 日志、库存差异、健康
- 路由/按钮权限：`v-permission` 指令或既有权限判断方式（与现有 pages 保持一致）

**Step 5｜本地冒烟**
- admin `npm run dev` 打开各页面，确认筛选、分页、详情、重推、取消链路正常（用 Mock 数据 + 本地 API）

---

## 4. 测试

### 4.1 单元测试（vitest，admin）
- `admin/tests/wms-console.test.ts`（≥10 例）
  - 列表按状态筛选请求参数正确
  - `PushFailed` 行显示错误摘要与重推按钮；点击后调用 `pushFulfillmentOrder` 且 loading 态正确
  - 详情抽屉展示行项目与推送流水
  - 退货单详情展示 `inventory_type` 与差异提示
  - 日志页脱敏：渲染文本中不含 AppSecret/完整手机号
  - 权限不足时操作按钮不渲染
  - 接口异常时有可读错误态（含 request_id）
  - 空数据显示空态而非崩溃

### 4.2 回归测试（必跑）
- admin：`npx vue-tsc -b` 0 error；`npx vitest run --fileParallelism=false` 无新增失败（当前基线 173+）
- web：不受影响，但仍跑一次全量确认
- 后端：`php -d memory_limit=1G vendor/bin/pest` 无失败

### 4.3 集成测试
| 场景 | 步骤 | 期望 |
|---|---|---|
| 人工重推 | 制造一条 `push_failed`（停掉 worker + 强制失败）→ 页面点重推 | 状态回到 `pushed`，日志新增一条 |
| 取消出库 | 对 `pushed` 单据点取消 | 状态 `cancelled`，菜鸟侧同步取消 |
| 差异校准 | 造 1 条差异 → 点「按 WMS 校准」→ 二次确认 | 平台库存变更，`inventory_logs` 有备注 |
| 权限隔离 | 无权限账号登录 | 菜单与按钮不可见，直接访问路由被拦截 |
| 日志排查 | 详情页复制 `request_id` → 日志页搜索 | 命中对应出入站报文（脱敏） |

---

## 5. 验收清单

- [x] 四个页面（发货单/退货入库单/日志/库存差异）+ 健康看板，详情交互全部可用
- [x] 所有操作按钮受权限码控制（发货 `wms.order.view` / `wms.order.manage`，退货 `wms.return.manage`，日志/差异/健康 `wms.config.manage`）
- [x] 脱敏生效：报文出口再过一层 `PayloadMasker`，单测断言页面文本不含 AppSecret / 完整手机号
- [x] 错误态给出 `request_id`（统一 `errorText()` 从响应 data 取并拼进提示文案）
- [x] 表格/筛选/空态风格与既有后台页面一致（`TablePagination` 复用，白卡 + 纯文本 `h2` + 内联筛选行）
- [x] `vue-tsc` 零错误、vitest 212 passed（基线 201 → +11）、build 通过
- [ ] 集成测试通过——沙箱真实联调待菜鸟账号到位（同 P2～P5 止损口径）

### 验收记录
| 日期 | 人 | 结果 | 备注 |
|---|---|---|---|
| 2026-09-20 | AI Agent | 代码闭环通过 | 后端 1261 passed（+11）；admin 212 passed / vue-tsc 0 error / build 通过；冒烟 61/61（新增 5l 履约段）。沙箱真实联调待账号到位 |

---

## 6. 完成情况

- [x] Step 1 后端接口补齐：详情带最近 5 条调用流水摘要（`WmsLogSummary` trait）、批量重推 `batch-push`（逐条成败 + 审计）、`GET /admin/wms/logs{/{id}}`、仓库下拉 `warehouse-options`
- [x] Step 2 `admin/src/api/wms.ts`：发货单 / 退货单 / 日志 / 差异 / 健康接口 + 状态中文与色阶常量
- [x] Step 3 五个页面（发货单、退货入库单、WMS 日志、库存差异、健康看板）+ 详情交互
- [x] Step 4 菜单「仓库与物流」并入履约中心五项，按钮级 `v-permission`
- [x] Step 5 本地冒烟 61/61（新增 5l 履约段）
- [x] 单元测试（vitest）通过：`tests/wms-console.test.ts` 11 例
- [x] 回归测试通过：admin 212 / web 198 / 后端 1261，全绿
- [ ] 集成测试通过——沙箱真实联调待菜鸟账号到位
- [x] 验收清单全勾选（除沙箱联调项）

**阶段状态**：✅ 已完成（2026-09-20）并同步 `README.md` §4
