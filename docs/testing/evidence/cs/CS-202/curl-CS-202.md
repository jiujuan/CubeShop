# CS-202 证据：工单详情订单快照 HTTP 端到端

- 时间：2026-09-17 21:36:06
- 接口：`GET /api/admin/cs/tickets/1`（后台）· `GET /api/cs/tickets/1`（买家端）
- 环境：`php artisan serve` :8000 + 开发库 PG `cubeshop`

## 1. 断言的结论

| 检查项 | 结果 |
|--------|------|
| 后台响应含 order_snapshot 节点 | PASS |
| 后台商品清单非空 | PASS |
| 后台物流含最新轨迹 | PASS |
| 后台退款记录非空且含 admin_remark（客服内部字段） | PASS |
| 后台含跳转参数 jump | PASS |
| 买家端不含 jump | PASS |
| 买家端退款不含 admin_remark | PASS |
| 买家端响应正文不含内部备注明文 | PASS |
| 两端快照均不含收货手机明文（13800001234） | PASS |
| 旧 order 节点形状兼容（5 键） | PASS |

## 2. 后台 `order_snapshot` 响应全文

```json
{
  "order_id": 46,
  "order_no": "CS20260917000010",
  "status": "pending_ship",
  "status_label": "待发货",
  "pay_amount": "30.00",
  "created_at": "2026-09-17T08:37:21.000000Z",
  "item_count": 3,
  "items": [
    {
      "product_id": 35,
      "sku_id": 52,
      "title": "CS冒烟商品",
      "specs": {
        "规格": "标准"
      },
      "image": null,
      "price": "20.00",
      "quantity": 1,
      "total_amount": "20.00",
      "payable_amount": 20
    },
    {
      "product_id": null,
      "sku_id": null,
      "title": "磁吸充电线 1m",
      "specs": {
        "颜色": "白"
      },
      "image": "/storage/demo/charge-cable.png",
      "price": "39.00",
      "quantity": 2,
      "total_amount": "78.00",
      "payable_amount": 78
    }
  ],
  "address": {
    "contact_name": "CS冒烟",
    "phone_masked": "138****1234",
    "full_address": "广东省深圳市南山区冒烟路1号"
  },
  "shipping": {
    "company_code": "SF",
    "company_name": "顺丰速运",
    "tracking_no": "SF202609170046",
    "trace_status": "in_transit",
    "shipped_at": "2026-09-15T13:32:52.000000Z",
    "delivered_at": null,
    "latest_trace": {
      "context": "派送中（预计今日送达）",
      "occurred_at": "2026-09-17T11:32:52.000000Z"
    }
  },
  "refunds": [
    {
      "refund_no": "RF20260917000046",
      "amount": "39.00",
      "status": "pending",
      "created_at": "2026-09-17T13:32:52.000000Z",
      "admin_remark": "客服内部：已核对凭证，待仓库确认"
    }
  ],
  "jump": {
    "order_id": 46,
    "order_no": "CS20260917000010",
    "latest_refund_no": "RF20260917000046"
  }
}
```

## 3. 买家端 `order_snapshot` 响应全文（精简版）

```json
{
  "order_id": 46,
  "order_no": "CS20260917000010",
  "status": "pending_ship",
  "status_label": "待发货",
  "pay_amount": "30.00",
  "created_at": "2026-09-17T08:37:21.000000Z",
  "item_count": 3,
  "items": [
    {
      "product_id": 35,
      "sku_id": 52,
      "title": "CS冒烟商品",
      "specs": {
        "规格": "标准"
      },
      "image": null,
      "price": "20.00",
      "quantity": 1,
      "total_amount": "20.00",
      "payable_amount": 20
    },
    {
      "product_id": null,
      "sku_id": null,
      "title": "磁吸充电线 1m",
      "specs": {
        "颜色": "白"
      },
      "image": "/storage/demo/charge-cable.png",
      "price": "39.00",
      "quantity": 2,
      "total_amount": "78.00",
      "payable_amount": 78
    }
  ],
  "address": {
    "contact_name": "CS冒烟",
    "phone_masked": "138****1234",
    "full_address": "广东省深圳市南山区冒烟路1号"
  },
  "shipping": {
    "company_code": "SF",
    "company_name": "顺丰速运",
    "tracking_no": "SF202609170046",
    "trace_status": "in_transit",
    "shipped_at": "2026-09-15T13:32:52.000000Z",
    "delivered_at": null,
    "latest_trace": {
      "context": "派送中（预计今日送达）",
      "occurred_at": "2026-09-17T11:32:52.000000Z"
    }
  },
  "refunds": [
    {
      "refund_no": "RF20260917000046",
      "amount": "39.00",
      "status": "pending",
      "created_at": "2026-09-17T13:32:52.000000Z"
    }
  ]
}
```

## 4. 后台 `order` 旧节点（兼容形状）

```json
{
  "order_no": "CS20260917000010",
  "status": "pending_ship",
  "pay_amount": "30.00",
  "created_at": "2026-09-17T08:37:21.000000Z",
  "product_image": null
}
```

> 买家端 token 由 tinker 现场铸造（`createToken('cs202-evidence')`），仅用于本次取证。
