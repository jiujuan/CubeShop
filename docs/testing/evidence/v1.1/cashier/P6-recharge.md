# 收银台与支付渠道方案 · P6 余额充值（验收证据）

**日期**：2026-09-16
**阶段**：P6 充值（收银台方案 §6.5 / §9.4）
**方案文档**：[CubeShop_Cashier_Payment_Design_v1.0.md](../../../design/CubeShop_Cashier_Payment_Design_v1.0.md)

## 1. 交付范围

| 层 | 内容 |
|----|------|
| 后端 | `BalanceRechargeService`（金额/单日限额风控、赠送规则命中最高档、建充值单并委托 `PaymentService::createRechargePayment` 建支付单 + 调网关）；`BalanceController`（余额汇总 / 发起充值 / 充值记录 / 余额流水）；`NoGeneratorService` 新增 `RC` 前缀；`AppServiceProvider` 新增 `throttle:recharge`（10/min）；`BalanceService::credit()` 增加 `totalRechargeDelta`，使「累计充值」只统计本金 |
| 前端 | `web/src/api/balance.ts`；`BalanceRechargeView.vue`（`/balance/recharge`，面额 + 自定义金额 + 赠送提示 + 渠道不含余额 + 线下凭证 + 复用 PayParams 与结果页）；`AccountCenterView.vue` 新增「我的余额」区块与「余额」Tab（充值记录 + 余额流水，分页）；`PayView.vue` 余额不足处「去充值」快捷入口 |

### 路由

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/user/balance` | 余额汇总（可用/冻结/累计充值/累计消费） |
| POST | `/user/balance/recharges` | 发起充值（限流 `recharge` 10/min） |
| GET | `/user/balance/recharges` | 充值记录（分页） |
| GET | `/user/balance/logs` | 余额流水（分页） |

## 2. 关键设计落实

- **资金一本账**：充值单 `balance_recharges` 只存业务字段；支付仍走 `payments`（`biz_type=recharge`、`order_id=null`、`biz_no=充值单号`），回调/查单/对账复用订单链路。
- **唯一入账口**：`BalanceService::creditForRecharge()`，幂等判据 `user_balance_logs(related_type=recharge, related_id)`；在线支付成功与线下核账通过均走此处。
- **赠送规则**：命中「门槛 ≤ 充值金额」的**最高档**，未命中不送；`balance += 本金 + 赠送`，`total_recharge += 本金`。
- **风控**：单笔 ∈ [`recharge_min_amount`, `recharge_max_single`]、单日累计 ≤ `recharge_max_daily`；充值**不支持余额支付**（渠道校验 + 请求校验双重拦截）。
- **线下充值**：提交凭证 → `reviewing` → 后台 `payment.offline.review` 核账 → 入账。

## 3. 验证证据

| 项 | 结果 |
|----|------|
| 后端新增用例 | `backend/tests/Feature/RechargeApiTest.php` **15 passed**（44 assertions） |
| 后端全量（SQLite） | **397 passed**（1313 assertions） |
| 后端全量（PostgreSQL） | **397 passed**（1313 assertions），`php artisan test -c phpunit.pgsql.xml` |
| 前端全量（Vitest） | **77 passed**（新增 `web/tests/recharge.test.ts` 6 例） |
| 前端构建 | `npm run build` 通过（`vue-tsc -b` + `vite build`） |

### 覆盖用例（RechargeApiTest）

发起充值返回充值 PayParams / 支付成功后按本金+赠送入账并写流水 / 重复回调幂等 / 赠送命中最高档 / 低于最小金额 40000 / 高于单笔上限 40000 / 单日累计超限 40000 / 充值不支持余额支付 422 / 总开关关闭 40000 / 线下充值进入待核账 / 线下核账通过入账 / 余额汇总空态 / 充值记录分页 / 余额流水类型标签 / 未登录 401。

## 4. 与设计文档的偏差说明

- 设计文档 §6.5 / §8.5 将「超限」错误码写作 `40010`；代码库统一以 `BusinessException::badRequest()` 返回 **40000**（与 P5 余额不足一致），测试按 40000 断言。
- 充值结果页复用 P5 已实现的 `/pay/result/:payment_no`（`biz_type=recharge` 文案与按钮已就绪），未新增结果页。

## 5. 关联提交

- `feat(payment): P6 余额充值（服务 + 接口 + 充值页 + 账户中心余额）`（见 `git log`）
