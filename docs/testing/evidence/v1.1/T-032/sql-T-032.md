# T-032 SQL 核对

## 1. 开发库权限码核对

```
marketing.manage 存在      : YES
super_admin 持有 marketing  : YES
operator    持有 marketing  : YES
```

## 2. 券统计口径（与接口 stats 手算对照）

统计接口 `GET /admin/coupons/{id}/stats` 的口径：

| 字段 | 口径 |
|---|---|
| `issued_count` | `coupons.issued_count`（领取时条件更新递增） |
| `received_count` | `COUNT(user_coupons WHERE coupon_id=?)` |
| `used_count` | `COUNT(user_coupons WHERE coupon_id=? AND status='used')` |
| `available_count` | `max(0, total_count - issued_count)` |
| `issue_rate` | `received_count / total_count`（4 位小数） |
| `use_rate` | `used_count / received_count`（4 位小数） |
| `order_count` | `COUNT(orders WHERE coupon_id=?)` |
| `discount_sum` | `SUM(orders.discount_amount WHERE coupon_id=?)` |
| `order_amount_sum` | `SUM(orders.pay_amount WHERE coupon_id=?)` |

Pest 用例 `TC-MKT-032-009` 以 3 领取 / 1 核销 / 1 笔用券订单（实付 90、优惠 10）断言
接口返回与手算一致（received=3、used=1、available=7、order_count=1、discount_sum=10、order_amount_sum=90）✅

## 3. 已发放券保护

`update()` 对 `issued_count > 0` 的券拒绝核心字段（type/amount/percent/min_spend/max_discount/
scope/scope_refs/total_count/per_user_limit/valid_type/valid_from/valid_days），仅放行
`name` / `status` / `valid_to`（且 `valid_to` 仅允许延长）。用例 `TC-MKT-032-007` 覆盖。
