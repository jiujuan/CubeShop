# T-035 下单链路 + 取消券回退 证据样例（sql-T-035）

**任务**：T-035 [BE] 订单金额链路改造与支付回调校验扩展
**对象**：`App\Services\Order\OrderService::createFromCart` / `App\Services\Payment\PaymentService::applySuccess`
**口径**：Backend_Design §3.4（T-034 定稿）

## 场景：商品 66.00 × 2 + 运费 10.00，满减 100-12（自动匹配）+ 券 20.00

### orders 行（下单后）

| 字段 | 值 |
|---|---|
| total_amount | 132 |
| freight_amount | 10 |
| promotion_discount | 12 |
| discount_amount | 32 |
| pay_amount | 110 |
| coupon_id | 1 |
| status | pending_payment |

### amount_details（分摊快照）

```json
{
    "v": 1,
    "goods_amount": "132.00",
    "freight_amount": "10.00",
    "promotion_discount": "12.00",
    "coupon_discount": "20.00",
    "discount_amount": "32.00",
    "pay_amount": "110.00",
    "promotion_id": 1,
    "coupon_id": 1,
    "user_coupon_id": 1,
    "lines": [
        {
            "index": 0,
            "product_id": 1,
            "sku_id": 1,
            "amount": "132.00",
            "promotion_share": "12.00",
            "coupon_share": "20.00",
            "payable": "100.00"
        }
    ]
}
```

### order_items 行级分摊

| id | price | quantity | total_amount | promotion_share | coupon_share | 行实付 |
|---|---|---|---|---|---|---|
| 1 | 66 | 2 | 132 | 12 | 20 | 100.00 |

### 券占用状态（下单即核销）

| user_coupons.status | used_order_id | used_at |
|---|---|---|
| used | 1 | 2026-09-16 21:49:45 |

| coupons.used_count |
|---|
| 1 |

### 期望值核对（手算：132 − 12 − 20 + 10 = 110）

| 字段 | 期望 | 实测 | 结论 |
|---|---|---|---|
| goods_amount | 132.00 | 132.00 | ✅ |
| freight_amount | 10.00 | 10.00 | ✅ |
| promotion_discount | 12.00 | 12.00 | ✅ |
| discount_amount | 32.00 | 32.00 | ✅ |
| pay_amount | 110.00 | 110.00 | ✅ |
| Σ 行 promotion_share = 满减总额 | 12.00 | 12.00 | ✅ |

### 取消待支付订单后（券返还 + used_count 回退）

| user_coupons.status | used_order_id | used_at | coupons.used_count |
|---|---|---|---|
| unused | NULL | NULL | 0 |

| 订单 status | 券 status | 结论 |
|---|---|---|
| cancelled | unused | ✅ 已返还可用 |
