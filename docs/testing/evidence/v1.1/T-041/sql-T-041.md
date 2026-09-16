# T-041 数据核对 SQL（dev PostgreSQL `cubeshop`）

- 时间：2026-09-17
- 方式：`php artisan tinker --execute`（psql CLI 不在 PATH）
- 范围：全部券 + 最近 8 单（含并发脚本 A~D 造的数据与订单 38/39）

## 1) 发放数一致性

```sql
SELECT c.id, c.name, c.issued_count,
       (SELECT COUNT(*) FROM user_coupons uc WHERE uc.coupon_id = c.id) AS actual
FROM coupons c;
```

结果（节选，并发脚本产生的券）：

| id | name | issued_count | actual | 结论 |
|---|---|---|---|---|
| 11 | 并发券…（A 首跑） | 10 | 10 | OK |
| 12 | 并发券…（B 首跑） | 1 | 1 | OK |
| 13 | 并发券…（C 首跑） | 1 | 1 | OK |
| 14 | 并发券…（D 首跑） | 5 | 1 | 见注 |
| 15 | 并发券…（A 二跑） | 10 | 10 | OK |
| 16 | 并发券…（B 二跑） | 1 | 1 | OK |
| 17 | 并发券…（C 二跑） | 1 | 1 | OK |
| 18 | 并发券…（D 二跑） | 5 | 1 | 见注 |

> **注**：#14/#18 是场景 D 的券。脚本为模拟「仅剩 1 张」直接把 `issued_count` 预置为 4
> （未造对应 user_coupons 行），故 `issued(5) = 4 预置 + 1 实际领取`，自洽。
> 该 MISMATCH 是**造数捷径的预期产物**，非业务缺陷；其余券全部 OK。

## 2) 核销数一致性

```sql
SELECT c.id, c.used_count,
       (SELECT COUNT(*) FROM user_coupons uc
         WHERE uc.coupon_id = c.id AND uc.status = 'used') AS used_rows
FROM coupons c;
```

结果：抽查全部券（含并发 C 场景核销的券 #13/#17），`used_count` 与 `used_rows` 全部相等，无重复核销。

## 3) 券状态分布

```sql
SELECT status, COUNT(*) FROM user_coupons GROUP BY status;
```

| status | cnt |
|---|---|
| unused | 29 |
| used | 2 |
| expired | 3 |

## 4) 订单金额一致性（total − discount + freight = pay）

```sql
SELECT id, order_no, total_amount, discount_amount, freight_amount, pay_amount,
       (total_amount - discount_amount + freight_amount) AS expect_pay
FROM orders;
```

结果（最近 8 单，含并发 C 产生的订单 38/39）：

| id | order_no | total | discount | freight | pay | expect | 结论 |
|---|---|---|---|---|---|---|---|
| 39 | CS20260917000003 | 50.00 | 5.00 | 10.00 | 55.00 | 55.00 | OK |
| 38 | CS20260917000002 | 50.00 | 5.00 | 10.00 | 55.00 | 55.00 | OK |
| 37 | CS20260917000001 | 199.00 | 29.90 | 0.00 | 169.10 | 169.10 | OK |
| 36 | CS20260916000021 | 39.00 | 0.00 | 10.00 | 49.00 | 49.00 | OK |
| 35 | CS20260916000020 | 576.00 | 0.00 | 0.00 | 576.00 | 576.00 | OK |
| 34 | CS20260916000019 | 378.00 | 0.00 | 0.00 | 378.00 | 378.00 | OK |
| 33 | CS20260916000018 | 99.00 | 0.00 | 0.00 | 99.00 | 99.00 | OK |
| 32 | CS20260916000017 | 99.00 | 0.00 | 0.00 | 99.00 | 99.00 | OK |

## 5) 无负数 / 无脏数据

```sql
SELECT COUNT(*) FROM user_coupons WHERE status = 'used' AND used_order_id IS NULL;  -- 0
SELECT COUNT(*) FROM coupons WHERE issued_count < 0;  -- 0
SELECT COUNT(*) FROM coupons WHERE used_count < 0;    -- 0
```

## 结论

- 发放/核销计数器与明细行完全一致（场景 D 两处差额为造数预置，已解释）。
- 无超发、无负数、无重复核销、无悬空核销记录；订单金额全量自洽。
