# 积分 / 签到 / 会员成长 技术方案（V1）

> 状态：**待评审**（分析已完成，等待决策点拍板后进入实现）
> 规则载体：全部走 `system_configs`（`points.*` / `member.*`）
> 勘察基线：迁移最大号 **000135**（新迁移自 000136 起）、Laravel v13.12、PHP 8.3
> ⚠️ 上一份《电子发票方案》尚未实现，不占用迁移号

---

## 1. 目标与非目标

| 编号 | 目标 | 验收口径 |
|---|---|---|
| G1 | 积分账户（流水驱动余额） | 只经 `PointsService` 写入；每笔有 before/after 与关联单据 |
| G2 | 每日签到，连续天数递增给分 | 同日幂等、断签重置、跨天按业务时区 |
| G3 | 结算页积分抵扣 | 与满减/券同一套分摊框架，分摊到行 |
| G4 | 退款按比例回退积分 | 部分退款按比例、整单全退；幂等不重复退 |
| G5 | 会员成长 + 分层定价 | 成长值累计 → 等级 → 会员价，承载到下单行单价 |
| G6 | 规则全部后台可配 | `points.*` / `member.*`，落在系统配置页 |

**非目标**：积分兑换商品/抽奖商城、付费会员卡、积分转赠、积分抵运费、成长值兑换券（均后置）。

---

## 2. 现状勘察（代码事实，实现时直接引用）

| 项 | 结论 | 位置 |
|---|---|---|
| 优惠计算 | **纯函数** `PricingCalculator::price()`，叠加顺序常量 `STACK_ORDER = ['promotion','coupon']`，输出 `orders.amount_details`；`DETAILS_VERSION = 1` | `app/Services/Marketing/PricingCalculator.php` |
| 分摊不变量 | `assertInvariants()` 校验 Σ分摊闭合、行实付 ≥ 0；尾差归最大行（`AmountAllocator` / `spreadFreight`） | 同上 |
| **篡改校验（最大陷阱）** | 支付回调按 `PricingCalculator::recomputePayAmount($details)` 重算 `pay_amount`，不一致即拒单 → 引入积分抵扣必须**改公式且按 `v` 分支** | `app/Services/Payment/PaymentService.php:703` |
| 结算入口 | `CouponService::priceOrder()` → `PricingCalculator::price()`；`buildContext()` 组装行（含 public_id 回解） | `app/Services/Marketing/CouponService.php:307` |
| 下单 | `OrderService::createFromCart()`（券/满减在事务内落库 + 状态流转）；`OrderController::store()` 仅收 `user_coupon_id`/`promotion_id` | `app/Services/Order/OrderService.php:71` |
| 账本范式（可直接照抄） | `BalanceService::account($userId, lock:true)` → `lockForUpdate`；`credit()/debit()` 写 `balance_before/after`；`UserBalanceLog` 有 `type/related_type/related_id/remark/created_by` | `app/Services/Payment/BalanceService.php`、`app/Models/UserBalanceLog.php` |
| 券返还钩子（退款对称点） | `markRefundSuccess()`：整单全额退款时 `releaseCoupon()` + 操作日志 | `app/Services/Refund/RefundService.php:597` |
| 订单行快照 | `OrderItem` 有 `product_title/sku_specs/price/quantity/total_amount/coupon_share/promotion_share` | `app/Models/OrderItem.php` |
| 配置读口范式 | `*Settings` 类：`SWITCHES` 常量（键→默认值）+ 注入 `ConfigService`；JSON/逗号串存 `system_configs.config_value`（字符串） | `app/Services/Sms/SmsSettings.php`、`SearchConfig` |
| ConfigService | `get/getInt/getDecimal/all/set/flush`，整表缓存 300s | `app/Services/Common/ConfigService.php` |
| 计划任务 | `routes/console.php` 里 `Schedule::command(...)->xxx()->withoutOverlapping()` | `routes/console.php` |
| 用户表 | `users` **无等级/成长值/积分字段** | `app/Models/User.php` |
| SKU 价格 | `product_skus` 仅 `price`（无会员价/划线价）；出口 `ProductResource`/`ProductSkuResource` 直接吐 `price` | `app/Models/ProductSku.php`、`app/Http/Resources/*` |
| 前端结算页 | 券/满减预览由接口给值，本地算 `应付预览`，提交后与服务端 `pay_amount` 核对（差 >0.01 弹窗） | `web/src/views/CheckoutView.vue:112、213` |
| 后台营销页 | `views/operation/MarketingView.vue`（优惠券 + 满减，`marketing.manage`） | `admin/src/views/operation/` |
| 通知 | `NotificationService::send()` / `sendToPermission()` | `app/Services/Notification/NotificationService.php` |

---

## 3. 领域模型

### 3.1 表清单（迁移自 000136 起，落地前 `ls` 复核）

| # | 迁移 | 表 | 说明 |
|---|---|---|---|
| 000136 | `create_user_points_table` | `user_points` | 积分账户（余额冗余，流水驱动） |
| 000137 | `create_user_point_logs_table` | `user_point_logs` | 积分流水 |
| 000138 | `sync_member_permission` | — | 权限幂等同步（S1 已落地，随账户一起做，故提前） |
| 000139 | `create_user_checkins_table` | `user_checkins` | 签到记录（含连续天数） |
| 000140 | `create_user_growth_logs_table` | `user_growth_logs` | 成长值流水 |
| 000141 | `add_member_fields_to_users` | `users` | `growth_value` / `member_level_id` / `level_settled_at` |
| 000142 | `add_points_fields_to_orders` | `orders` / `order_items` | `points_used` / `points_discount` / 行 `points_share` |

### 3.2 `user_points`

| 字段 | 说明 |
|---|---|
| user_id（唯一） | 一个买家一个积分账户 |
| balance | 当前可用积分（冗余，由流水驱动） |
| frozen | 冻结中（下单占用未支付）—— 仅两段式方案需要（见 D4） |
| total_earned / total_spent | 累计获得 / 累计消耗（对账用） |
| updated_at | — |

- **唯一写入口**：`PointsService`；任何其它地方 `->increment()` 都视为违规（与 `InventoryService::adjust()` 同纪律）
- 并发：`lockForUpdate` 锁账户行（照抄 `BalanceService::account(lock:true)`）

### 3.3 `user_point_logs`

| 字段 | 说明 |
|---|---|
| user_id / type | `signin` / `order_earn` / `order_deduct` / `refund_return` / `admin_adjust` / `expire` |
| points | 正=获得，负=消耗 |
| balance_before / balance_after | 账本体例 |
| related_type / related_id | `order` / `refund` / `checkin` / 空（人工调整） |
| **biz_key** | **幂等键**（如 `order:123:deduct`、`refund:456:return`），unique —— 防回调重放重复加分 |
| expire_at | 该笔积分的过期时间（过期策略启用时） |
| remark / created_by / created_at | — |

### 3.4 `user_checkins`

| 字段 | 说明 |
|---|---|
| user_id / checkin_date | `date`（**只到日**，用 `App\Casts\DateOnly`，勿用默认 `date` cast —— SQLite 会写 `Y-m-d 00:00:00` 导致等值判定失效） |
| streak | 本次签到后的连续天数（1、2、3…） |
| points | 本次实发积分 |
| unique(user_id, checkin_date) | 幂等：同日重复签到 → 409 |

### 3.5 `users` 新增

`growth_value`（int，累计成长值）、`member_level_id`（int，当前等级，**落库**而非实时推导）、`level_settled_at`（上次结算时间）

> 等级**落库**而非实时推导：降级要有保护期与可审计的变动记录；实时推导会在「规则一改全员等级跳变」时不可解释。

---

## 4. 积分规则（`points.*`，全部 `system_configs`）

| 配置键 | 默认 | 说明 |
|---|---|---|
| `points.enabled` | `1` | 总开关，关了全站不显示积分入口 |
| `points.name` | `积分` | 积分名称（后台可改成「金币」等） |
| `points.earn_rate` | `100` | 消费 X 元得 1 分（`0` = 不返积分）；按**实付**计算 |
| `points.earn_on_freight` | `0` | 运费是否计积分 |
| `points.redeem_rate` | `100` | 多少分抵 1 元 |
| `points.redeem_min` | `100` | 起抵积分（不足不可用） |
| `points.redeem_max_percent` | `50` | 单笔最多抵商品金额的百分比（防 0 元单） |
| `points.redeem_max_points` | `0` | 单笔最多用多少分（`0` = 不限） |
| `points.refund_return` | `1` | 退款是否回退积分 |
| `points.expire_enabled` / `points.expire_months` | `0` / `12` | 积分过期（V1 建议关闭，见 D8） |
| `points.signin_enabled` | `1` | 签到开关 |
| `points.signin_base` | `5` | 首日基础分 |
| `points.signin_step` | `2` | 每多连续一天递增 |
| `points.signin_max` | `30` | 单日封顶 |
| `points.signin_cycle` | `7` | 连续周期（第 N 天归 1，形成 7 日循环） |
| `points.signin_bonus` | `{}` | 里程碑奖励 JSON：`{"7":50,"30":200}` |

**签到积分公式**（纯函数，可单测）：
`points = min(base + (streak-1) * step, max) + (bonus[streak] ?? 0)`

---

## 5. 会员成长与分层定价（`member.*`）

| 配置键 | 默认 | 说明 |
|---|---|---|
| `member.enabled` | `1` | 会员体系开关 |
| `member.levels` | `[{"level":1,"name":"普通会员","min_growth":0,"discount":100},{"level":2,"name":"银卡","min_growth":1000,"discount":98},{"level":3,"name":"金卡","min_growth":5000,"discount":95}]` | 等级定义 JSON；`discount` 用**实付百分比**（与券 `percent` 同口径，100 = 无折扣） |
| `member.growth_per_yuan` | `1` | 每实付 1 元得多少成长值 |
| `member.growth_signin` | `0` | 签到是否给成长值 |
| `member.settle_mode` | `login` | 等级结算时机：`login`（登录时）/`daily`（每日命令） |
| `member.grace_days` | `30` | 降级保护期 |
| `member.price_mode` | `discount` | 会员价形态：`discount`（按等级折扣算）/`sku_price`（SKU 维度会员价，后置） |

### 分层定价如何「承载」（关键设计）

**在 `OrderContext` 构造之前把行单价换成会员价**，而不是在 `PricingCalculator` 里做：

```
CartService/OrderService 取行 → MemberPrice::apply(price, level) → OrderContext(price=会员价)
                                                                  ↓
                                            PricingCalculator（纯函数，无需感知会员）
```

理由：
1. `PricingCalculator` 保持**纯函数、无用户态**（现有设计约束，注释已明确「三期积分抵扣复用同套框架」）；
2. 会员价一旦进 `OrderContext.price`，商品总额、满减梯度、券门槛、积分抵扣上限、运费分摊**全部自动跟随**，无需到处打补丁；
3. 行单价落到 `OrderItem.price` 快照，退款/对账天然一致。

- 展示层：`ProductResource` / `ProductSkuResource` 登录且有等级时追加 `member_price`，web 详情页/列表展示「会员价」+ 划线原价
- ⚠️ 会员价与满减/券叠加后仍要过 `assertInvariants`（行实付 ≥ 0）—— 折扣只降单价，不产生负分摊，安全

---

## 6. 结算页抵扣链路（改动最大、风险最高）

### 6.1 计算器扩展

```
STACK_ORDER = ['promotion', 'coupon', 'points']   // 积分放最后，抵的是「券后剩余」
```

- 抵扣上限：`min(可用积分 / redeem_rate, 券后剩余商品额 × redeem_max_percent%, redeem_max_points)`
- 抵扣额按行分摊（复用 `AmountAllocator`，尾差归最大行），行增 `points_share`
- `amount_details` 增 `points_discount`（元）与 `points_used`（分），行增 `points_share`

### 6.2 ⚠️ `DETAILS_VERSION` 必须升到 2，且 `recomputePayAmount` 按版本分支

`PaymentService` 用 `recomputePayAmount()` 做**篡改校验**：`pay_amount ≠ 重算值` 直接拒单。

```
v1: pay_amount = goods − discount + freight
v2: pay_amount = goods − discount − points_discount + freight
```

若不加版本分支：
- 新订单（v2）用旧公式重算 → 偏大 → **支付回调全部拒单**（P0 事故）
- 历史订单（v1）若 `points_discount` 缺省为 0 → 公式数值一致，但**必须显式分支**才安全

处理方式：`recomputePayAmount()` 内按 `$details['v']` 分流，v1 走旧公式；并补测试「v1 明细重算结果不变」。

### 6.3 积分扣减时点（需拍板，见 D4）

| 方案 | 行为 | 评价 |
|---|---|---|
| A 下单即扣 | 下单事务内 `debit`；取消/超时 `credit` 返还 | 简单；但未支付订单会「先扣分」，投诉风险 |
| **B 两段式（推荐）** | 下单时 `frozen`（占用），支付成功转实际扣减，取消/超时/支付失败释放 | 与资金语义一致（对称券的「占用/返还」），代价是多一个冻结字段与释放命令 |
| C 支付成功才扣 | 不冻结，支付回调扣 | 简单但可超扣（并发两单都用同一批分） |

推荐 **B**，与 `releaseCoupon`（未支付取消原样返还）同一套语义；若觉得重，可退到 A（V1 数据量小，风险可控）。

### 6.4 前端

- `CheckoutView`：新增「使用积分」开关行（可用积分 / 抵扣 ¥x / 上限提示），本地预览公式追加 `- pointsDiscount`
- 提交后仍与服务端 `pay_amount` 核对（现有逻辑不变）
- 接口：`POST /api/checkout/preview` 之类（复用现有可用券接口，把积分可用额度一并返回）

### 6.5 未支付/取消/超时

- `OrderService::cancel()` / `cancelExpired()`：释放冻结 / 返还已扣积分（`biz_key = order:{id}:release`，幂等）
- 对称现有 `releaseCoupon()`

---

## 7. 退款按比例回退

钩子：`RefundService::markRefundSuccess()`（在现有「整单返还券」逻辑旁）

| 场景 | 回退积分 |
|---|---|
| 整单全额退款 | 回退本单全部 `points_used` |
| 部分退款（金额型） | `round(points_used × 退款额 / 订单实付, 0)`（向下取整，避免多退） |
| 行级退款 | 按行 `points_share` 累加回退（更精确，优先） |

- 幂等键：`biz_key = refund:{id}:return`，回调重放不重复加分
- 回退上限：`min(应退, 本单已扣)` —— 防止多次部分退款累计超退
- 若 `points.refund_return = 0`：不回退，但记日志说明
- 同时回退成长值（对称，写 `user_growth_logs`），并触发等级重算（可能降级，受 `grace_days` 保护）

---

## 8. 权限与后台页面

| 权限码 | 能力 | 授予 |
|---|---|---|
| `member.view` | 会员/积分/规则查看、流水查询 | super_admin + operator |
| `member.manage` | 规则修改、人工调整积分/成长值、补发签到 | super_admin + operator |

（`RolePermissionSeeder::PERMISSIONS` 与迁移 000138 幂等同步双写）

| 页面 | 位置 | 内容 |
|---|---|---|
| 会员与积分（新） | `admin/src/views/operation/MemberView.vue`，菜单「运营管理」组 | Tab：规则配置（`points.*` / `member.*`，系统配置页自动承载）/ 会员列表（等级筛选）/ 积分流水 |
| 用户详情弹层 | `UserListView.vue` | 加「积分 / 等级」区块 + 人工调整（原因必填、二次确认、写操作日志） |
| 营销页 | `MarketingView.vue` | 不动（券/满减与积分在结算页自然叠加） |

---

## 9. API 设计

### 买家端（`/api`，`auth:sanctum`）

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/points` | 我的积分（余额 / 累计获得 / 累计消耗 / 即将过期） |
| GET | `/points/logs` | 积分流水 |
| GET | `/checkin` | 签到状态（今日是否已签、连续天数、今日可得、明日可得） |
| POST | `/checkin` | 签到（同日重复 → 409） |
| GET | `/member` | 我的会员（等级、成长值、下一级进度、等级折扣） |
| GET | `/checkout/options` | 结算可用权益（券 + 满减 + **积分可抵额度**）—— 与现有可用券接口合并返回 |
| POST | `/orders` | 新增可选 `use_points`（1/0），返回 `points_discount` |

### 后台（`/api/admin`）

| 方法 | 路径 | 权限 |
|---|---|---|
| GET | `/admin/members` | `member.view` |
| GET | `/admin/points/logs` | `member.view` |
| POST | `/admin/users/{id}/points/adjust` | `member.manage`（增减 + 原因必填） |
| POST | `/admin/users/{id}/checkin/supplement` | `member.manage`（补签） |
| GET | `/admin/points/export` | `member.view` |

> 路由：静态路径注册在 `/admin/members/{id}` 之前（沿用既有坑位纪律）。

---

## 10. 目录归属（按项目 D0 四问）

| 单元 | 归属 | 理由 |
|---|---|---|
| `App\Support\Member\SigninReward` | Support | 纯算法（连续天数 → 积分），可独立单测 |
| `App\Support\Member\MemberLevel` | Support | 等级解析（成长值 → 等级 → 折扣），纯函数 |
| `App\Support\Member\MemberPrice` | Support | 会员价换算（单价 × 等级折扣），纯函数 |
| `App\Support\Member\PointsRules`（常量/上限口径） | Support | 对齐 `ImportRules` / `ShippingRules` |
| `App\Services\Member\PointsService` | Services | 账本写入、锁、幂等、状态语义 |
| `App\Services\Member\CheckinService` | Services | 签到（DB + 跨天判定） |
| `App\Services\Member\MemberService` | Services | 成长值累计、等级结算、降级保护 |
| `App\Services\Member\PointsSettings` / `MemberSettings` | Services | `points.*` / `member.*` 唯一读口（对齐 `SmsSettings`） |

依赖单向 Services → Support。

---

## 11. 测试计划

| 层 | 用例 |
|---|---|
| 单测 | `SigninReward`（连续递增/封顶/里程碑/周期归 1）、`MemberLevel`（阈值边界、降级保护）、`MemberPrice`（取整、0 折扣）、`PricingCalculator` **v1 重算结果不变**（回归红线）、v2 三层叠加不变量 |
| 特性 | 签到同日重复 409、断签重置、跨天（改 `checkin_date`）、并发签到只记一次、积分账户并发扣减不超扣 |
| 抵扣 | 起抵门槛、单笔百分比上限、积分不足、抵扣后行实付 ≥ 0、下单占用→取消释放（方案 B）、支付成功确认扣减 |
| 退款 | 整单全退回退全部分、部分退款按比例、行级退款按 `points_share`、回调重放幂等不重复退、多次部分退款累计不超退 |
| 会员 | 成长值累计、升级、降级保护期、等级折扣落到行单价、`amount_details` 全链路数值闭合 |
| 权限 | `member.view` 不能调整积分；人工调整写操作日志 |
| 前端 | admin 会员页/规则页/用户详情区块；web 结算页抵扣开关、签到入口、积分明细 |
| 收尾 | 后端 pest 全量 + admin vitest 全量 + web 串行 + 双端 build |

---

## 12. 里程碑

| Phase | 内容 |
|---|---|
| P1 | 迁移 000136–000142 + 模型 + 权限 + `ConfigGroup` 登记 `points` / `member` |
| P2 | 积分账户与流水（`PointsService`）+ 后台人工调整 + 用户详情区块 |
| P3 | 签到（`CheckinService` + 规则 + 买家页入口） |
| P4 | 结算页抵扣（`PricingCalculator` v2 + 三段叠加 + 下单占用/释放 + 前端） |
| P5 | 退款按比例回退 + 成长值回退 |
| P6 | 会员成长与分层定价（等级配置、会员价注入、前端展示）+ 全量回归 |

---

## 13. 待拍板决策点

| # | 决策点 | 建议 |
|---|---|---|
| **D1** | 积分扣减时点：A 下单即扣 / **B 冻结两段式** / C 支付后扣 | **B**（与券「占用/返还」同语义；嫌重则退 A） |
| **D2** | 抵扣叠加位次：积分在券后（推荐）/ 券前 | **券后**（`STACK_ORDER` 追加 `points`） |
| **D3** | 抵扣范围：仅商品 / 含运费 | **仅商品**（运费不抵，避免 0 元运费单逻辑复杂化） |
| **D4** | 单笔抵扣上限：百分比（默认 50%）+ 起抵分（100） | 同意（防 0 元单与薅羊毛） |
| **D5** | 消费返积分基数：实付 / 商品额 | **实付**（扣掉券满减后） |
| **D6** | 积分过期：V1 不启用 / 启用（12 个月） | **V1 不启用**（先跑通链路，过期涉及清理命令与用户告知） |
| **D7** | 签到周期：无限递增 / 7 日循环 | **7 日循环**（运营节奏清晰，成本可控） |
| **D8** | 补签：V1 不做 / 后台手工补签 | **后台手工补签**（`member.manage`） |
| **D9** | 会员价形态：等级折扣（推荐）/ SKU 维度会员价 | **等级折扣**（零 SKU 改造；将来要 SKU 会员价再加字段） |
| **D10** | 等级折扣与券/满减是否可叠加 | **可叠加**（折扣先进单价，后续优惠按折后算） |
| **D11** | 权限：新增 `member.view/manage` / 复用 `marketing.manage` | **新增两档**（与 refund 两档同体例） |
| **D12** | 成长值是否随退款扣回 | **是**（防止刷单刷等级） |

---

## 14. 风险与陷阱

| 风险 | 影响 | 兜底 |
|---|---|---|
| **`recomputePayAmount` 未做版本分支** | 支付回调全线拒单（P0） | `DETAILS_VERSION` 升 2 + v1/v2 分支 + 红线单测 |
| 会员价注入后满减/券门槛变化 | 用户「够格变不够格」 | 门槛按**原始命中金额**（现有口径不变），会员价只影响行金额 → 折后更容易不够格，需在结算页明示折扣后金额 |
| 签到跨时区/跨天 | 连续天数错乱 | 统一 `date('Y-m-d')` 按 `APP_TIMEZONE`；`DateOnly` cast（**禁用默认 `date` cast**） |
| 并发签到/并发抵扣 | 重复加分、超扣 | `unique(user_id, checkin_date)` + 账户行 `lockForUpdate` + `biz_key` 幂等 |
| 部分退款多次累计超退 | 积分被薅 | 回退上限 = 本单已扣 − 已退，流水留痕 |
| 积分抵扣影响发票金额 | 与发票方案（待实现）口径冲突 | 发票按**实付**开，积分抵扣视同折扣；发票方案落地时对齐 |
| 规则改动导致等级跳变 | 用户投诉 | 等级落库 + 降级保护期 + 等级变动留痕 |
| `system_configs` 整表缓存 300s | 改规则不即时生效 | `ConfigService::set()` 已 flush；批量删记得显式 flush |

---

## 15. 决策结果（D1–D12 全部采用默认，已固化）

| # | 决策 | 落地口径 |
|---|---|---|
| D1 | 积分扣减时点 | **冻结两段式**：下单占用 `user_points.frozen` → 支付成功确认扣减 → 取消/超时/支付失败释放 |
| D2 | 抵扣叠加位次 | `STACK_ORDER = ['promotion','coupon','points']`（券后抵扣） |
| D3 | 抵扣范围 | **仅商品金额**，运费不抵 |
| D4 | 抵扣上限 | `min(可用积分 / redeem_rate, 券后商品剩余 × 50%, redeem_max_points)`；起抵 `redeem_min = 100` 分 |
| D5 | 返分基数 | 订单**实付 `pay_amount`**（`earn_on_freight = 0`，运费不计分） |
| D6 | 积分过期 | **不启用**（`expire_enabled = 0`） |
| D7 | 签到周期 | **7 日循环**（第 8 天归 1）；`base=5 / step=2 / max=30`；里程碑 `bonus = {"7":50}` |
| D8 | 补签 | **后台手工补签**（`member.manage`），V1 不做补签卡 |
| D9 | 会员价形态 | **等级折扣**（`price_mode = discount`），等级定义 JSON 存 `member.levels` |
| D10 | 叠加规则 | 会员折扣与券/满减**可叠加**（折扣先进单价，后续优惠按折后算） |
| D11 | 权限 | 新增 `member.view`（查看/导出）与 `member.manage`（改规则、调整积分、补签）两档 |
| D12 | 成长值 | **随退款扣回**（防刷单刷等级） |

---

## 16. 分步实施计划（S1 → S7，从最简单开始）

> 排序原则：**先做「只增不改」的能力**（账户 → 签到 → 发分 → 等级），
> **把动金额主链路的两步（抵扣、退款回退）压到最后** —— 它们才是 P0 风险所在。
> 每步独立可提交、独立可验收；每步跑**定向测试 + 构建**，只有 S7 收尾才跑全量。

### S1 积分账户与流水（地基，不改任何现有链路）

| 项 | 内容 |
|---|---|
| 范围 | 迁移 `user_points` / `user_point_logs`（000136/000137）+ 权限迁移（`member.view` / `member.manage`）；模型 `UserPoint` / `UserPointLog`；`Services/Member/PointsService`（account 锁 / credit / debit / freeze / confirm / release / balance）；`Support/Member/PointsRules`（类型字典、biz_key 规则）；`Services/Member/PointsSettings`（`points.*` 唯一读口）；`ConfigGroup` 登记 `points` |
| 后台 | 用户详情弹层加「积分」区块 + 人工调整（增减 + 原因必填 + 二次确认 + 操作日志） |
| 验收 | 余额 ≡ 流水累计；并发调整不超扣；无 `member.manage` 时调整入口不可见且接口 403 |
| 测试 | `PointsService` 单测（并发/幂等/负余额拒绝）+ 后台接口特性 + admin 用户详情区块用例 |
| 提交 | backend 一个、admin 一个 |

### S2 签到（第一个完整闭环）

| 项 | 内容 |
|---|---|
| 范围 | 迁移 `user_checkins`（000139）；`Support/Member/SigninReward`（纯算法）；`Services/Member/CheckinService`（状态 / 签到 / 补签）；买家 `GET|POST /checkin`；后台补签接口；`points.signin_*` 配置项 |
| 前端 | web 账户中心签到卡片（连续天数 / 今日可得 / 明日可得 / 签到按钮） |
| 验收 | 同日重复 409；断签归 1；**第 8 天归 1**（7 日循环）；凌晨跨天正确；并发只记一次；补签留痕 |
| 测试 | `SigninReward` 单测 + 签到特性（跨天/并发/幂等/循环）+ web 用例 |
| 依赖 | S1 |

### S3 消费返积分（第一次接支付链路，但**不改金额**）

| 项 | 内容 |
|---|---|
| 范围 | 支付成功钩子发分（实付 × `earn_rate`，运费不计）；`biz_key = order:{id}:earn` 幂等；积分流水买家可见（复用 S1） |
| 验收 | 重复回调不重复发分；仅 `paid` 后发；取消/未支付不发 |
| 测试 | 发分幂等 + 金额边界（0 元单、含运费单） |
| 依赖 | S1 |

### S4 会员成长与分层定价（先展示，再进单）

| 项 | 内容 |
|---|---|
| 范围 | 迁移 `user_growth_logs`（000140）+ `users` 加三字段（000141）；`Support/Member/MemberLevel` / `MemberPrice`（纯函数）；`Services/Member/MemberService`（成长累计 / 等级结算 / 降级保护 `grace_days`）；`member.*` 配置；`ProductResource` / `ProductSkuResource` 追加 `member_price` |
| 关键 | **会员价在 `OrderContext` 构造前注入行单价**（`MemberPrice::apply`），`PricingCalculator` 保持不感知会员 |
| 后台 | 会员列表页（等级筛选）+ 用户详情等级区块 |
| 验收 | 升级/降级保护期生效；会员价进入 `amount_details.goods_amount`；**`DETAILS_VERSION` 仍为 1，v1 不变量不变** |
| 测试 | `MemberLevel` / `MemberPrice` 单测 + 下单金额链路回归 + 后台会员列表用例 |
| 依赖 | S1 |

### S5 结算页积分抵扣（最难，单独一步，先写测试再改代码）

| 项 | 内容 |
|---|---|
| 范围 | `PricingCalculator` 追加 `points` 段；**`DETAILS_VERSION 1→2` 且 `recomputePayAmount` 按 `v` 分支**；行加 `points_share`；迁移 000142（`orders.points_used` / `points_discount`、`order_items.points_share`）；下单冻结 → 支付成功确认扣减 → 取消/超时释放；买家结算可用额度接口；`POST /orders` 支持 `use_points` |
| 前端 | `CheckoutView` 抵扣开关行（可用积分 / 抵扣 ¥ / 上限提示），预览公式追加 `- pointsDiscount` |
| 验收 | **红线：v1 明细重算结果不变**；三层叠加不变量通过；上限与起抵生效；未支付订单释放冻结 |
| 测试 | 版本兼容红线单测 + 三层叠加单测 + 抵扣特性（起抵/上限/不足/释放）+ web 结算页用例 |
| 依赖 | S1、S3（建议 S3 后做） |
| 风险 | 改错会导致支付回调拒单 → 先补 v1 回归用例，再动公式 |

### S6 退款按比例回退

| 项 | 内容 |
|---|---|
| 范围 | `RefundService::markRefundSuccess()` 挂钩：整单全退全回；金额型部分退 `round(points_used × 退款额 / 实付, 0)`（向下取整）；行级退按 `points_share`；上限 `= 本单已扣 − 已退`；`biz_key = refund:{id}:return` 幂等；成长值同步扣回 + 等级重算 |
| 验收 | 回调重放不重复退；多次部分退款累计不超退；`points.refund_return=0` 时不退但有日志 |
| 测试 | 整单/部分/行级/重放/超退五组特性用例 |
| 依赖 | S3、S5 |

### S7 收尾（规则页 + 全量回归）

| 项 | 内容 |
|---|---|
| 范围 | 后台「会员与积分」页（Tab：规则配置 / 会员列表 / 积分流水 + 导出）；积分流水导出 CSV；全量回归（backend pest + admin vitest + web 串行 + 双端 build）；文档与项目记忆更新 |
| 验收 | 规则改动后 `ConfigService` 缓存即时失效；三端全绿 |

### 步骤间的对应关系

```
S1 账户 ─┬─ S2 签到
         ├─ S3 发分 ── S5 抵扣 ── S6 退款回退 ── S7 收尾
         └─ S4 会员成长/会员价（可与 S3 并行推进）
```

- **可以并行的**：S2 与 S4 互不依赖（都只依赖 S1），想快点就两条线交替做
- **必须串行的**：S3 → S5 → S6（发分先于抵扣，抵扣先于回退）
- **建议单独预留时间的**：S5（动 `PricingCalculator` 与支付校验）


---

## 17. S1 实施记录（已完成，2026-09-27）

提交：backend `75dee17` / admin `d4de50b`。

### 落地内容

| 项 | 实现 | 与方案的差异 |
|---|---|---|
| 迁移 | 000136 `user_points`、000137 `user_point_logs`、000138 `sync_member_permission` | 权限迁移**提前**到 S1（原计划 000142），后续步骤编号顺延一位 |
| 模型 | `UserPoint`（主键即 user_id，不建外键，与 `user_balances` 同体例）、`UserPointLog` | — |
| 规则 | `Support/Member/PointsRules`：类型字典 + `directionOf()` + `bizKey()` + `ADJUST_MAX=100000` | 类型常量放 Support 而非模型，`UserPointLog` 不重复定义（避免双真源） |
| 服务 | `Services/Member/PointsService`：`account / summary / credit / debit / freeze / confirm / release / adjust / logs` | `freeze/confirm/release` 三个原语 S5 才接线，本步只提供 + 单测钉死语义 |
| 接口 | `GET /admin/users/{id}/points`（member.view）、`POST /admin/users/{id}/points/adjust`（member.manage） | 挂在 users 之下而非顶层资源（与 `/users/{userId}/addresses` 同组织方式） |
| 前端 | 用户详情弹层「会员积分」区块 + 调整弹窗 | — |

### 与方案的**有意偏差**

1. **`PointsSettings` / `ConfigGroup` 顺延到 S2**：S1 没有任何需要读配置的逻辑，先写会变成没人调用的死代码；
   等 S2 做签到规则（base/step/max）时连同 `points` 前缀登记一起落，一次成型。
2. **流水记两个变动量**（`points` 可用变动 + `frozen_points` 冻结变动，各自存 before/after）：
   只记一个量的话，「冻结 → 确认消耗」会被记成 0 分流水而丢失信息，对账时无法解释 frozen 为何减少。
3. **退款返还不计入累计获得**（`refund_return` 方向为 none）：它退的是之前抵扣掉的积分，
   计入 `total_earn` 会让「累计获得」虚高。
4. **member.view / member.manage 两档都给运营**：运营已有的 `payment.offline.review` 能直接加真金白银的余额，
   积分调整（流水 + 操作日志 + 原因必填）的可追溯性不弱于它，单独收紧没有实际意义。

### 测试

- 后端 `tests/Unit/PointsServiceTest.php`：**12 passed**（入账/出账/冻结→确认/释放/人工调整/幂等/负余额/账户与流水一致）
- 后端 `tests/Feature/Admin/UserPointApiTest.php`：**8 passed**（含客服角色无 member.* 的 403 隔离、原因必填 422、操作日志留痕）
- admin `tests/user-points.test.ts`：**7 passed**（权限显隐、接口失败整块不展示、调整提交与就地刷新、两项校验）
- 回归：权限相关 6 个既有测试文件全绿（78 passed）
- `vue-tsc --noEmit` 与 `npm run build` 均通过

### 下一步（S2 签到）需要的编号

迁移自 **000139** 起。


---

## 18. S2 实施记录（已完成，2026-09-27）

后端提交 `39c3f12`；前端 web / admin 见下方提交。

### 落地内容（后端）

| 项 | 实现 | 与方案的差异 |
|---|---|---|
| 迁移 | 000139 `user_checkins`（`unique(user_id, checkin_date)` + `checkin_date` 走 `App\Casts\DateOnly`，禁用默认 date cast） | — |
| 算法 | `Support/Member/SigninReward`：纯函数 `reward/streak/nextStreak/milestoneDays`；7 日循环 `nextStreak()` 第 8 天归 1 | 与 §5 默认一致：base=5 / step=2 / max=30 / bonus={7:50} |
| 配置 | `Services/Member/PointsSettings`（`points.*` 唯一读口，注入 `ConfigService`）；`ConfigGroup` 登记 `points`/`member` 合并为「会员与积分」Tab | 顺延到 S2 落地的 `PointsSettings` 一并补齐（S1 无读配置逻辑，提前写是死代码） |
| 服务 | `Services/Member/CheckinService`：`status / checkin / backfill / recent`；连续天数落库；补签重算后续链但**不追溯补发积分差额**（防套利） | 有效连续天数（`status.streak`）与「上次签到天数」分开：断签后链断归 0，否则展示会虚高 |
| 接口 | 买家 `GET /checkin`、`POST /checkin`（限流 `throttle:30,1`）；后台 `POST /admin/users/{id}/checkins/backfill`（member.manage + 操作日志） | `POST /checkin` 返回实发积分 + 完整 status，前端无需二次拉取 |
| 积分 | 发放唯一走 `PointsService::credit`，`biz_key = checkin:{user}:{date}` 幂等 | 与 S1 同写口 |

### 落地内容（前端）

| 端 | 实现 |
|---|---|
| web | 新增 `web/src/api/points.ts`（`getCheckinStatus`/`postCheckin` + 类型）；账户中心概览插入「每日签到」卡片（连续天数 / 今日可得 / 明日可得 / 累计 / 可用积分 / 签到按钮）；功能未开启展示禁用提示；重复签到（冲突）刷新卡片并提示 |
| admin | `admin/src/api/points.ts` 增 `backfillUserCheckin`；用户详情「会员积分」区块（member.view 可见）新增「补签」入口（member.manage）；日期弹窗默认昨天、上限昨天、前端兜底拒绝今天/未来；成功后回拉积分体现新流水与余额并 toast |

### 测试

- 后端 `tests/Unit/SigninRewardTest.php`：**7 passed**
- 后端 `tests/Feature/CheckinApiTest.php`：**9 passed**（重复 409 / 跨天 / 并发 / 7 日循环 / 未开启 400 / 401）
- 后端 `tests/Feature/Admin/UserCheckinApiTest.php`：**6 passed**（无权限 403 / 补签昨天 / 已签 409 / 今天未来 422 / 补签后链重算不追溯 / 404）
- web `tests/checkin-card.test.ts`：**4 passed**（展示 / 签到刷新 / 未开启 / 冲突）
- web `tests/account-center.test.ts`：**7 passed**（补 `@/api/points` mock，避免全量并发走真实 axios）
- admin `tests/user-points.test.ts`：**11 passed**（补 4 例补签：入口显隐 / 选历史日期提交回拉刷新 / 未来日期拦截）
- `vue-tsc -b`（web + admin）均通过

### 下一步（S3 消费返积分）

依赖 S1；`biz_key = order:{id}:earn` 幂等，运费不计。


---

## 19. S3 实施记录（已完成，2026-09-27）

第一次接支付链路，但**不改任何金额**（`DETAILS_VERSION` 仍为 1，`recomputePayAmount` 不动）。

### 落地内容

| 项 | 实现 | 说明 |
|---|---|---|
| 配置 | `PointsSettings` 增 `points.name` / `points.earn_rate` / `points.earn_on_freight` 读口（`name()` / `earnRate()` / `earnOnFreight()`），并入 `SWITCHES` 白名单供 S7 规则页维护 | 无新迁移，配置走 `system_configs` 缺省值 |
| 发分服务 | `Services/Member/OrderPointsService::award(Order)`：`enabled` + `earn_rate > 0` + 基数 > 0 三道闸；基数 = `pay_amount`（元），`earn_on_freight=0` 时 `bcsub` 扣掉运费；`floor(bcdiv(基数, earn_rate))` 发分；`biz_key = order:{id}:earn` | 金额运算全走 BC 数学函数，避免浮点误差 |
| 钩子 | `OrderService::transitionTo()` 在 `$target === STATUS_PAID` 落库后调 `awardOrderPoints()`；**try/catch + report，发分失败绝不阻断支付主链路** | 覆盖所有进入 paid 的路径：支付回调（PaymentService:603）、后台标记支付、退款驳回回退 paid（RefundService:331，幂等键防二次发分）、历史回填命令 |
| 买家接口 | `PointsController`：`GET /user/points`（enabled/name/account/最近流水）+ `GET /user/points/logs`（分页，per_page 上限 50），沿用 `/user/*` 前缀约定（与 §9 的 `/points` 命名有意偏差，对齐 `/user/balance` 体例） | auth:sanctum + account.active 组内 |
| web | `api/points.ts` 增 `getMyPoints` / `getMyPointLogs` + 类型；新增 `PointsView.vue`（`/points`：可用/累计获得/累计消耗卡片 + 流水分页表 + 未开启提示，未开启时不拉流水）；账户中心「我的服务」加「我的积分」入口 | — |

### 关键设计点

1. **发分时机 = transitionTo 内、状态落库后**：所有 paid 路径唯一入口；`PointsService::credit` 内嵌套事务 + biz_key 幂等兜底退款驳回的二次 paid。
2. **失败隔离**：发分在 transitionTo 事务内但独立 try/catch，积分侧异常只 report，订单支付状态不受影响。
3. **基数口径**：`pay_amount` 已含运费，故 `earn_on_freight=0` 的实现是「实付减运费」而非「仅商品额另算」；0 元单（扣完运费 ≤ 0）直接不返。

### 测试

- 后端 `tests/Unit/OrderPointsServiceTest.php`：**6 passed**（运费不计/计入、0 元单、earn_rate=0、功能关闭、biz_key 幂等）
- 后端 `tests/Feature/OrderEarnTest.php`：**5 passed**（transitionTo paid 自动发分、退款驳回回退 paid 不重复发分、买家概览/流水接口、未登录 401）
- 回归：订单/支付/退款相关 13 个既有测试文件全绿（**109 passed**，含 PaymentApi / PaymentReliability / PaymentSecurity / Refund 全系）
- web `tests/points-view.test.ts`：**3 passed**（概览+流水、翻页、未开启不拉流水）；account-center / checkin-card 回归 11 passed
- `vue-tsc -b` 与 `npm run build` 均通过

### 下一步（S4 会员成长与分层定价）

迁移自 **000140** 起（`user_growth_logs` + `users` 三字段）。

