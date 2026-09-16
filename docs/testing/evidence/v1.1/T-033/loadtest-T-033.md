# T-033 优惠券领取并发防超发结论

- 日期：2026-09-16
- 脚本：`docs/testing/coupon_concurrency_test.sh`
- 数据库：开发库 PostgreSQL `cubeshop`（`php artisan serve` 为单 worker，HTTP 层无法产生真实竞态，
  故以「多进程 + 同库」方式验证原子防超发原语，PG 下为真实并发）

## 场景与结论

| 场景 | 并发设置 | 结果 | 判定 |
|---|---|---|---|
| A | 限量 10 的券，**50 个不同用户**并发领取 | 成功 **10**、失败 40、`issued_count=10`、`user_coupons=10` | ✅ 无超发 |
| B | **同一用户**对限领 1 的券并发领 10 次 | 成功 1、`user_coupons=1` | ✅ 限领生效 |
| D | 仅剩 **1 张**，2 个用户并发领取 | 成功 1、`issued_count=1` | ✅ 无负数、无超发 |

## 原始输出

```
--- 场景 A：限量 10 的券，50 用户并发领取 ---
成功=10 失败=40 issued_count=10 user_coupons=10
场景A PASS（无超发）
--- 场景 B：同一用户并发领 10 次（限领 1） ---
成功=1 记录数=1（期望 1）
场景B PASS（限领生效）
--- 场景 D：仅剩 1 张，2 用户并发领取 ---
成功=1 issued_count=1（期望 1 / 1）
场景D PASS（无负数、无超发）
```

## 机制说明

`CouponService::receive()` 的防超发依赖**条件更新**：

```sql
UPDATE coupons SET issued_count = issued_count + 1
WHERE id = ? AND status = 'active' AND issued_count < total_count
```

受影响行数为 0 即判定「已领完或已停发」并抛业务码 40009；每人限领校验与之处于**同一事务**内，
因此并发下不会出现「先查后写」的绕过。

## 结论

批次一核心风险项（券超发）在 50 并发下**未出现超发**（`issued_count` 与领取记录数均等于限量值），
限领与余量边界均正确。**T-033 并发硬指标达成。**
