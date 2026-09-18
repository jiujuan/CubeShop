# CS-202 证据：order_snapshot ↔ 底层表交叉核对

- 生成时间：2026-09-17 21:37:34
- 数据库：pgsql / cubeshop
- 工单：cs_ticket#1 → order#46

快照由 `CsTicketService::orderSnapshot()` 实时聚合以下表，本文件列出原始行以证明字段来源一致。

## 1. orders（快照头字段来源）

| 字段 | 原始值 | 快照字段 |
|------|--------|----------|
| id | 46 | order_id |
| order_no | CS20260917000010 | order_no |
| status | pending_ship | status / status_label |
| pay_amount | 30.00 | pay_amount |
| created_at | 2026-09-17 16:37:21 | created_at |
| address_snapshot.contact_name | CS冒烟 | address.contact_name |
| address_snapshot.contact_phone | 13800001234 | address.phone_masked（脱敏） |

## 2. order_items（商品清单来源，读快照列 `sku_image` / `product_title`，不查 products 表）

| id | product_title（→ 快照 title） | sku_image（→ 快照 image） | price | quantity |
|----|------|-----------|-------|----------|
| 47 | CS冒烟商品 | null | 20.00 | 1 |
| 53 | 磁吸充电线 1m | /storage/demo/charge-cable.png | 39.00 | 2 |

> 明细行数 = 2，数量合计 = 3；快照 `item_count` = 数量合计。

## 3. shippings / shipping_traces（物流来源）

| shipping.id | company_code | company_name | tracking_no | trace_status |
|-------------|--------------|--------------|-------------|--------------|
| 7 | SF | 顺丰速运 | SF202609170046 | in_transit |

轨迹（按 occurred_at 倒序，快照取第 1 条为 latest_trace）：

| id | occurred_at | context |
|----|-------------|---------|
| 540 | 2026-09-17 19:32:52 | 派送中（预计今日送达） ← latest |
| 539 | 2026-09-16 21:32:52 | 运输中（深圳转运中心） |
| 538 | 2026-09-15 21:32:52 | 已揽收 |

## 4. refunds（退款记录来源，按 id 倒序）

| id | refund_no | amount | status | admin_remark |
|----|-----------|--------|--------|--------------|
| 27 | RF20260917000046 | 39.00 | pending | 客服内部：已核对凭证，待仓库确认 |

> `admin_remark` 仅后台快照（forStaff=true）返回，买家端剥离；`refund_no` 亦进 `jump.latest_refund_no`。

