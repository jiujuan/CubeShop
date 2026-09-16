# T-040 SQL 核对 — 券核销统计（API vs 数据库）

- 时间：2026-09-17
- 数据库：PostgreSQL dev `cubeshop`（127.0.0.1:5432, postgres）
- 方式：`php artisan tinker --execute` 执行与 `Admin\CouponController::stats()`（backend/app/Http/Controllers/Admin/CouponController.php:155）等价的 SQL（psql CLI 不在 PATH，改用 tinker）
- 对象：券 10「会员折扣券」（9折·满100可用·封顶30，total=200）

## 等价 SQL

```sql
-- 领取数
SELECT COUNT(*) FROM user_coupons WHERE coupon_id = 10;
-- 核销数
SELECT COUNT(*) FROM user_coupons WHERE coupon_id = 10 AND status = 'used';
-- 带来订单/优惠金额/订单金额
SELECT COUNT(*) AS order_count,
       COALESCE(SUM(discount_amount), 0) AS discount_sum,
       COALESCE(SUM(pay_amount), 0) AS pay_sum
FROM orders WHERE coupon_id = 10;
```

## 核对结果（券 10）

| 指标 | API `/api/admin/coupons/10/stats` | SQL（tinker） | 一致 |
|---|---|---|---|
| issued_count | 3 | 3 | ✅ |
| received_count | 3 | 3 | ✅ |
| used_count | 1 | 1 | ✅ |
| use_rate | 0.3333 | 1/3 ≈ 0.3333 | ✅ |
| order_count | 1 | 1 | ✅ |
| discount_sum | 29.9 | 29.90 | ✅ |
| order_amount_sum | 169.1 | 169.10 | ✅ |

## 订单明细核对（order 37）

| 字段 | 值 |
|---|---|
| order_no | CS20260917000001 |
| total_amount | 199.00 |
| discount_amount | 29.90 |
| pay_amount | 169.10 |
| status | pending_payment |

金额自洽：199.00 − 29.90 = 169.10，与 T-039 浏览器实测（PayView ¥169.10）一致。

## 领取明细（user_coupons）

| id | user_id | status | used_order_id |
|---|---|---|---|
| 28 | 36 | unused | — |
| 31 | 36 | unused | — |
| 29 | 150 | used | 37 |

## 结论

API 统计与数据库原始数据完全一致，T-040 统计抽屉取数可信。
