# T-031 SQL 核对（开发库 PostgreSQL `cubeshop`）

- 日期：2026-09-16
- 迁移：`2026_09_16_000030_create_coupon_and_promotion_tables`、`2026_09_16_000031_add_discount_fields_to_orders_tables`

## 1. 新表列结构

```
coupons      : id,name,type,amount,percent,min_spend,max_discount,scope,scope_refs,
               total_count,issued_count,used_count,per_user_limit,valid_type,
               valid_from,valid_to,valid_days,status,created_at,updated_at
user_coupons : id,user_id,coupon_id,status,used_order_id,used_at,expire_at,created_at,updated_at
promotions   : id,name,rules,scope,scope_refs,start_at,end_at,status,created_at,updated_at
```

## 2. 存量表改造核对

| 检查项 | 结果 |
|---|---|
| `orders` 新增列 `coupon_id` / `amount_details` | ✅ 存在 |
| `order_items` 新增列 `coupon_share` / `promotion_share` | ✅ 存在 |
| 既有订单数 | 36（未受影响） |
| 既有订单 `amount_details` 为 NULL 的比例 | 100%（36/36） |

## 3. 结论

三表建成、索引齐备；订单与明细新增字段为可空/默认 0，**未改动 V1.0 既有金额计算路径**。
旧数据与旧用例行为不变（SQLite 全量 454 passed 覆盖）。
