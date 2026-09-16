# T-033 SQL 核对

## 1. 领取一致性（并发后）

| 校验 | 期望 | 实测（场景 A） |
|---|---|---|
| `coupons.issued_count` | 10 | 10 ✅ |
| `COUNT(user_coupons WHERE coupon_id=?)` | 10 | 10 ✅ |
| 二者相等（无记录行数漂移） | 相等 | 相等 ✅ |

场景 B（同一用户并发 10 次，限领 1）：`COUNT(user_coupons WHERE coupon_id=? AND user_id=?)` = 1 ✅

## 2. expire_at 固化口径

| valid_type | expire_at 取值 |
|---|---|
| `relative` | `now() + valid_days`（领取时刻固化） |
| `absolute` | `coupons.valid_to`（券的绝对截止） |

## 3. 可用券判定口径（`GET /coupons/available`）

| 结果 | 条件 |
|---|---|
| 可用 | 状态 unused、未过期、命中金额 ≥ min_spend、范围命中 |
| 不可用：已过期 | `expire_at < now()` |
| 不可用：未满使用门槛 | 命中金额 < min_spend |
| 不可用：适用范围不符 | scope=product/category 且命中金额 = 0 |

命中金额：`scope=all` 取全单金额；`product/category` 只累计命中行的 `price × qty`。

## 4. 过期任务口径

`coupons:expire` 将 `status=unused AND expire_at < now()` 分批（500）置为 `expired`；
`GET /me/coupons?status=expired` 亦包含「状态仍为 unused 但已过期」的券（任务未收敛时的兜底展示）。
