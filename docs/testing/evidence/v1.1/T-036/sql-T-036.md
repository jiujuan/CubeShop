# T-036 退款 / 取消与券回退 — 证据（SQL 核对）

> 运行：`cd backend && php ../docs/testing/evidence/v1.1/T-036/gen_evidence.php`
> 使用临时 SQLite 库，不污染开发库。结论与 `RefundCouponApiTest`（7 例）+ `RefundServiceTest`（10 例）双库（SQLite / PG）一致。

## 场景设定

- 商品 `66.00 × 2 = 132.00`，运费 `10.00`，满减无，券 `20.00`（fixed 直减）。
- 实付 = 132.00 − 20.00 + 10.00 = **122.00**。

---

## 场景 A：整单全额退款 → 券原样返还 + used_count 回退

下单即核销（占用制）：

| user_coupons.status | used_order_id | used_at | coupons.used_count |
|---|---|---|---|
| used | 1 | 2026-09-16 22:13:33 | 1 |

订单 `pay_amount = 122.00`（✅ 期望 122.00）

申请退款（不传金额 = 全额）→ 审核通过：

| 字段 | 值 | 期望 |
|---|---|---|
| refund.amount | 122.00 | 122.00 ✅ |
| refund.status | success | success ✅ |
| order.status | refunded | refunded ✅ |
| 券状态 | unused / used_order_id=NULL / used_at=NULL / used_count=0 | 已返还 ✅ |

`refund.refund_details`（固化快照）— 与 `orders.amount_details` 同口径：

```json
{
  "goods_amount": "132.00",
  "freight_amount": "10.00",
  "coupon_discount": "20.00",
  "promotion_discount": "0.00",
  "pay_amount": "122.00",
  "lines": [
    { "index": 0, "amount": "132.00", "coupon_share": "20.00", "promotion_share": "0.00", "payable": "112.00" }
  ]
}
```

不变量：`Σ 行实付(112.00) + 运费(10.00) = 122.00` = `pay_amount` ✅

---

## 场景 B：部分退款 → 券不返还（防资损）

| 字段 | 值 | 期望 |
|---|---|---|
| refund.amount | 50.00 | 50.00 ✅ |
| refund.status | success | success ✅ |
| order.status | refunded | refunded ✅ |
| 券状态 | used / used_order_id=2 / used_count=1 | 不返还（保持 used）✅ |

> 设计口径：部分退款默认不返还已使用券，避免「退了钱又白拿券」的资损。仅整单全额退款才返还。

---

## 场景 C：未支付订单取消 → 券返还

下单即核销 → 取消 `pending_payment` 订单：

| 阶段 | user_coupons.status | coupons.used_count |
|---|---|---|
| 下单后（占用） | used | 1 |
| 取消后 | unused / used_order_id=NULL | 0 |

| order.status | 期望 |
|---|---|
| cancelled | cancelled ✅ |

> 取消路径经 `OrderService::transitionTo` → `releaseCoupon` 复用与退款相同的返还逻辑。

---

## 场景 D：退款累计上限 + 重复申请互斥

无券订单 `pay_amount = 142.00`（132 + 10）。

| 用例 | 输入 | 结果 | 期望 |
|---|---|---|---|
| D1 超额 | amount=200.00 | 拒绝 `退款金额超过可退余额`(40000) | ✅ |
| D2 重复 | 首笔全额(pending) → 再申请 | 拒绝 `该订单已有退款处理中`(40009) | ✅ |

> 校验顺序：① 金额 ≤ 0 → 40000；② 已存在未完结退款 → 40009（先于上限校验，避免「首笔全额处理中 + 同额再申请」被误判为超余额）；③ 累计已退(含处理中)后余额不足 → 40000。

---

## 结论

T-036 全部场景与预期一致：

1. 整单全额退款 → 券原样返还（status→unused、used_order_id/used_at 清空、used_count 回退）。
2. 部分退款 → 券不返还（保持 used，防资损）。
3. 未支付取消 → 券原样返还（与退款同源 `releaseCoupon`）。
4. 退款累计上限 = 订单实付 − 已退(含处理中)；超额 40000、重复 40009。
5. `refund_details` 固化 `orders.amount_details` 同口径快照，退款不变量可审计。
