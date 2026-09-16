# 收银台与支付渠道方案 · P7 可靠性（验收证据）

**日期**：2026-09-16
**阶段**：P7 可靠性（收银台方案 §7.2 / §7.3 / §7.4）
**方案文档**：[CubeShop_Cashier_Payment_Design_v1.0.md](../../../design/CubeShop_Cashier_Payment_Design_v1.0.md)

## 1. 交付范围

| 命令 | 签名 | 说明 |
|------|------|------|
| `payments:cancel-timeout` | `{--dry-run}` | 超时关单：**order 分支**复用 `OrderService::cancelExpired()`（释放锁定库存 + 关待支付单 + 订单流水）；**recharge 分支**关闭 `balance_recharges.status=pending 且 expired_at < now` 的支付单并置 `closed`（不入账） |
| `payments:sync-pending` | `{--limit=200}` | 主动查单补偿：扫描 `status=pending` 且创建于 2~30 分钟前的在线支付单（wechat/alipay/mock），调网关 `query()`；同一支付单主动查单次数达 `payment.query_max_attempts` 后标记 `failed` 并记日志 |

### 调度注册（`routes/console.php`）

```php
Schedule::command('payments:cancel-timeout')->everyMinute()->withoutOverlapping();
Schedule::command('payments:sync-pending')->everyMinute()->withoutOverlapping();
```

> 订单超时（原 `orders:cancel-expired`）自 P7 起统一由 `payments:cancel-timeout` 的 order 分支每分钟调用；`orders:cancel-expired` 命令保留供手动/运维执行。

### PaymentService 新增支撑方法

- `closePendingForRecharge(BalanceRecharge)`：关闭充值单的待处理支付单
- `queryMaxAttempts()`：读 `payment.query_max_attempts`（默认 10）
- `queryAttempts(Payment)`：按 `payment_logs(event=query)` 计数
- `markQueryExhausted(Payment)`：查单超限 → `failed` + 记 `query` 日志
- 构造注入 `ConfigService`（原缺）

## 2. §7.4 切渠道关旧单说明

「同一时刻只允许一笔可付单」已由 `PaymentService` 既有逻辑实现：`createPayment` / `createRechargePayment` 命中既有 `pending/reviewing` 支付单时**改渠道复用**（不新建），`failed` 单重置为 `pending` 复用。效果等价于「关旧单再建新单」，且不产生孤立支付单，无需额外改造。

## 3. 验证证据

| 项 | 结果 |
|----|------|
| 后端新增用例 | `backend/tests/Feature/PaymentReliabilityTest.php` **6 passed**（20 assertions） |
| 后端全量（SQLite） | **403 passed**（1333 assertions） |
| 后端全量（PostgreSQL） | **403 passed**（1333 assertions） |
| 调度校验 | `php artisan schedule:list` 显示两条 `* * * * *`（每分钟） |

### 覆盖用例（PaymentReliabilityTest）

充值单超时关单（不入账）/ 未超时不受影响 / 订单超时取消（订单 cancelled + 支付单 closed）/ `--dry-run` 不改变状态 / `sync-pending` 渠道已支付补单成功并入账 / 查单次数超限标记 failed 并记日志。

## 4. 关联提交

- `feat(payment): P7 可靠性（超时关单 + 主动查单调度）`（见 `git log`）
