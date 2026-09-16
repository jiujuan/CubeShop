# 订单状态拆分「待发货」（pending_ship）证据

> 需求：后台订单管理页需要「待发货」这一环——`已支付` 后面应是 `待发货`，`待发货` 之后才是 `已发货`。
> 触发方式（用户确认）：**系统自动、即时**（支付成功即进入发货队列），**同时保留人工处理入口**。

## 1. 结论

履约主链路由 `待支付 → 已支付 → 已发货 → 已完成` 调整为：

```
待支付 → 已支付 → 待发货 → 已发货 → 已完成
```

`paid` 与 `pending_ship` 的语义边界：

| 状态 | 中文 | 含义 | 进入方式 |
|------|------|------|----------|
| `paid` | 已支付 | 货款已到账 | 支付成功（系统写入，同事务内立即自动流转到 `pending_ship`） |
| `pending_ship` | 待发货 | 订单已进入发货队列 | ①系统自动（默认路径）；②后台「受理备货」人工兜底 |

- 支付成功后系统在**同一事务**内写两条流水：`→ 已支付`、`→ 待发货`，运营正常情况下只面对「待发货」。
- `paid` 是异常滞留态（自动流转失败、退款被驳回回流 `refunding → paid`），由后台人工「受理备货」处理。
- 状态机**不再允许** `paid → shipped` 直达，发货队列口径唯一。

## 2. 改动清单

### 后端

| 文件 | 改动 |
|------|------|
| `app/Models/Order.php` | 新增 `STATUS_PENDING_SHIP`；`TRANSITIONS` 插入待发货、移除 `paid → shipped`；`STATUS_LABELS` 增「待发货」；买家端 `TAB_STATUS_MAP.pending_ship = [paid, pending_ship]`；`actions.can_refund` 补待发货 |
| `database/migrations/2026_09_16_000029_add_pending_ship_order_status.php` | 存量 `paid` 订单搬迁为 `pending_ship`，并补写一条 `order_logs`（保证买家端时间轴节点可点亮）；同步 `orders.status` 列注释 |
| `app/Services/Order/OrderService.php` | 新增 `acceptForShipment()`（幂等，`paid → pending_ship`），系统与人工两条路径共用 |
| `app/Services/Payment/PaymentService.php` | `applySuccess()` 支付成功后追加自动流转到 `pending_ship`（`operator_type=system`） |
| `app/Http/Controllers/Admin/OrderController.php` | 新增 `accept()`（受理备货，权限 `order.ship`）；`ship()` 语义收敛为仅待发货可发货 |
| `routes/api.php` | 新增 `POST /admin/orders/{id}/accept` |
| `app/Http/Controllers/Admin/DashboardController.php` | 待发货数改计 `pending_ship`；销售额口径改为复用 `ReportService::SALES_STATUSES`（消除双份口径） |
| `app/Services/Report/ReportService.php` | `SALES_STATUSES` 补 `pending_ship`（**关键**：否则待发货订单的销售额会凭空消失）；待办 `ship` 改计 `pending_ship` |
| `app/Http/Controllers/Admin/UserController.php` | 有效订单状态（消费统计）补 `pending_ship` |

### 前端

| 文件 | 改动 |
|------|------|
| `admin/src/api/order.ts` | 状态类型/标签/配色补 `pending_ship`；**移除上一版为「paid 改叫待发货」引入的 `ORDER_TAB_LABELS`**（现已无必要）；新增 `acceptOrder()` |
| `admin/src/views/order/OrderView.vue` | Tab 与筛选下拉回到 `ORDER_STATUS_LABELS`（键顺序即履约主链路）；待发货行显示「发货」、已支付行显示「受理备货」；新增受理确认弹窗；支持 `?status=` 直达并应用筛选 |
| `admin/src/views/dashboard/IndexView.vue` | 待办卡链接 `/orders?status=paid` → `/orders?status=pending_ship` |
| `web/src/api/order.ts` | 状态类型/标签补 `pending_ship` |
| `web/src/components/OrderTimeline.vue` | 主链路插入「等待发货」节点（提交订单 → 支付成功 → 等待发货 → 商家发货 → 交易完成） |
| `web/src/views/OrderDetailView.vue`、`PayView.vue` | 「已付款」判断补 `pending_ship` |

### 文档

`CubeShop_API_v1.0.md`（§8.3 新增受理备货接口、§10 状态流转速查重写、状态与取消限制补 pending_ship、权限表说明）、
`CubeShop_Database_Design_v1.0.md`、`CubeShop_schema.sql` + `backend/database/sql/CubeShop_schema.sql`、`CubeShop_PRD_v1.0.md`（状态流转句）。

## 3. 验证结果

| 项 | 结果 |
|----|------|
| 后端全量（SQLite `:memory:`） | ✅ **439 passed**（1443 assertions） |
| 后端全量（PostgreSQL `cubeshop_test`） | ✅ **439 passed** |
| admin Vitest | ✅ **67 passed** |
| web Vitest | ✅ **83 passed** |
| admin / web 生产构建（含 vue-tsc） | ✅ 通过 |
| 真实 HTTP 冒烟（真实 PG 开发库） | ✅ **PASS 34 / FAIL 0** |
| 开发库迁移实测 | ✅ `paid → pending_ship` 搬迁 2 笔，状态分布 `completed:12 / shipped:2 / cancelled:14 / pending_ship:2` |

### 新增/调整的关键用例

- `AdminApiTest`：`TC-ADMIN-005b 已支付订单人工受理备货后进入待发货`、`待发货订单重复受理备货幂等`、`待支付订单受理备货被状态机拒绝`
- `OrderServiceTest`：`合法流转：待支付→已支付→待发货→已发货→已完成`、`已支付订单不能跳过待发货直接变为已发货`
- `OrderLifecycleApiTest`：`TC-LIFE-002` 校验四条流水（含系统自动受理）与操作人类型；`TC-LIFE-015` 详情流水条数与标签
- `OrderCenterApiTest`：买家端「待发货」Tab 合并统计 `paid + pending_ship`
- `admin/tests/order-view.test.ts`：Tab 顺序、下拉同步、发货/受理按钮分流、`?status=` 直达、非法状态忽略
- 冒烟脚本：新增「支付后自动流转到待发货」「受理备货幂等」「后台状态标签 待发货」3 项

## 4. 已知取舍

1. **历史流水仍只有 3 条**：`orders:backfill-logs` 依据时间戳反推流水，而订单表没有 `pending_ship_at` 列，
   故回填不会生成「待发货」流水（`TC-LIFE-016` 仍断言 3 条）。迁移已为**现存 paid 订单**补写该流水；
   更早的历史订单（已发货/已完成）时间轴会在「等待发货」节点显示未点亮，属可接受的展示差异。
2. **退款被驳回回流到 `paid`**（沿用既有 `refunding → paid`，未改动）意味着这类订单需人工「受理备货」；
   若希望回流直接进发货队列，可后续把目标状态改为 `pending_ship`（会同时影响 2 个既有用例与后台退款文案）。
3. **仪表盘/报表的「待发货」只计 `pending_ship`**，与后台订单页 Tab 的口径一致；滞留的 `paid` 订单在「已支付」Tab 处理。
