# CubeShop 电商系统
## API 接口文档
**版本**：v1.0（MVP）  
**日期**：2026-09-15  
**Base URL**：`https://{host}/api`  
**关联文档**：CubeShop_PRD_v1.0、CubeShop_Architecture_v1.0、CubeShop_Database_Design_v1.0

---

## 1. 通用约定

### 1.1 请求格式
- Content-Type：`application/json`（除文件上传外）
- 认证方式：`Authorization: Bearer {token}`（Laravel Sanctum）
- 字符编码：UTF-8

### 1.2 统一响应结构

**成功**
```json
{
  "code": 0,
  "message": "ok",
  "data": { }
}
```

**失败**
```json
{
  "code": 40001,
  "message": "错误描述",
  "data": null
}
```

### 1.3 常见错误码

| code | 说明 |
|------|------|
| 0 | 成功 |
| 40000 | 参数错误 / 校验失败 |
| 40001 | 未登录或 Token 无效 |
| 40003 | 无权限 |
| 40004 | 资源不存在 |
| 40009 | 业务冲突（库存不足、状态不允许等） |
| 50000 | 服务器内部错误 |

### 1.4 分页约定
请求参数：`page`（默认 1）、`page_size`（默认 20，最大 100）

响应 `data` 结构：
```json
{
  "list": [],
  "pagination": {
    "page": 1,
    "page_size": 20,
    "total": 100,
    "total_pages": 5
  }
}
```

### 1.5 接口分组

| 前缀 | 说明 | 认证 |
|------|------|------|
| `/auth` | 注册、登录、重置密码 | 部分无需 |
| `/user` | 个人中心、收货地址 | 需要 |
| `/products` | 前台商品、分类、搜索 | 无需 |
| `/cart` | 购物车 | 需要 |
| `/orders` | 下单、订单查询、取消、退款申请 | 需要 |
| `/payments` | 发起支付、支付回调 | 部分需要 |
| `/admin` | 后台管理接口 | 需要 + 权限 |

---

## 2. 认证模块 `/auth`

### 2.0 获取图形验证码
`POST /auth/captcha`

**无需认证**

**请求体**（可选）

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| scene | string | 否 | 场景：`admin`（默认，后台管理端，浅蓝底直线风格）/ `web`（用户端登录/注册，暖色渐变曲线风格）。两者视觉风格明显区分 |

**成功响应**
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "captcha_id": "uuid",
    "image": "data:image/svg+xml;base64,...",
    "expires_in": 300
  }
}
```

**说明**
- 验证码 4 位大写（已剔除 0/O/1/I 易混淆字符），5 分钟有效，校验一次即销毁。
- `captcha_id` 全局唯一，校验时不区分场景，同一验证码仅能消费一次。
- 非法 `scene` 值回落 `admin` 样式。

### 2.1 用户注册
`POST /auth/register`

**无需认证**

**请求体**
```json
{
  "username": "13800138000",
  "password": "Passw0rd!",
  "password_confirmation": "Passw0rd!",
  "phone": "13800138000",
  "email": "user@example.com",
  "code": "123456"
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| username | string | 是 | 登录名（可用手机号） |
| password | string | 是 | 密码，至少 6 位 |
| password_confirmation | string | 是 | 确认密码 |
| phone | string | 否 | 手机号 |
| email | string | 否 | 邮箱 |
| code | string | 是 | 验证码 |

**成功响应**
```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "token": "1|xxxxxxxxxxxx",
    "user": {
      "id": 1,
      "username": "13800138000",
      "nickname": null,
      "phone": "13800138000"
    }
  }
}
```

---

### 2.2 用户登录
`POST /auth/login`

**无需认证**

**请求体**
```json
{
  "username": "13800138000",
  "password": "Passw0rd!"
}
```

**成功响应**：同注册，返回 `token` + `user` 基本信息。

---

### 2.3 退出登录
`POST /auth/logout`

**需要认证**

**成功响应**
```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

---

### 2.4 发送验证码
`POST /auth/send-code`

**无需认证**

**请求体**
```json
{
  "target": "13800138000",
  "type": "register"
}
```

| 字段 | 说明 |
|------|------|
| target | 手机号或邮箱 |
| type | register / reset_password / login |

---

### 2.5 重置密码
`POST /auth/reset-password`

**无需认证**

**请求体**
```json
{
  "target": "13800138000",
  "code": "123456",
  "password": "NewPassw0rd!",
  "password_confirmation": "NewPassw0rd!"
}
```

---

## 3. 用户模块 `/user`

### 3.1 获取当前用户信息
`GET /user/profile`

**需要认证**

**成功响应**
```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "id": 1,
    "username": "13800138000",
    "nickname": "小明",
    "avatar": "https://...",
    "phone": "13800138000",
    "email": null
  }
}
```

---

### 3.2 更新个人资料
`PUT /user/profile`

**需要认证**

**请求体**
```json
{
  "nickname": "小明",
  "avatar": "https://..."
}
```

---

### 3.3 收货地址列表
`GET /user/addresses`

**需要认证**

**成功响应**
```json
{
  "code": 0,
  "message": "ok",
  "data": [
    {
      "id": 1,
      "contact_name": "张三",
      "contact_phone": "13800138000",
      "province": "广东省",
      "city": "深圳市",
      "district": "南山区",
      "detail_address": "科技园路 1 号",
      "is_default": true
    }
  ]
}
```

---

### 3.4 新增收货地址
`POST /user/addresses`

**需要认证**

**请求体**
```json
{
  "contact_name": "张三",
  "contact_phone": "13800138000",
  "province": "广东省",
  "city": "深圳市",
  "district": "南山区",
  "detail_address": "科技园路 1 号",
  "is_default": true
}
```

---

### 3.5 更新收货地址
`PUT /user/addresses/{id}`

**需要认证**

请求体同新增。

---

### 3.6 删除收货地址
`DELETE /user/addresses/{id}`

**需要认证**

---

### 3.7 设置默认地址
`POST /user/addresses/{id}/default`

**需要认证**

---

## 4. 商品模块 `/products`（前台，无需登录）

### 4.1 商品列表 / 搜索
`GET /products`

**查询参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| keyword | string | 关键词（标题） |
| category_id | int | 分类 ID |
| min_price | number | 最低价 |
| max_price | number | 最高价 |
| sort | string | price_asc / price_desc / sales_desc / newest |
| page | int | 页码 |
| page_size | int | 每页数量 |

**成功响应**
```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "list": [
      {
        "id": 1,
        "title": "示例商品",
        "main_image": "https://...",
        "price": "99.00",
        "sales_count": 120,
        "status": 1
      }
    ],
    "pagination": {
      "page": 1,
      "page_size": 20,
      "total": 50,
      "total_pages": 3
    }
  }
}
```

---

### 4.2 商品详情
`GET /products/{id}`

**成功响应**
```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "id": 1,
    "title": "示例商品",
    "subtitle": "副标题",
    "main_image": "https://...",
    "images": ["https://...", "https://..."],
    "description": "<p>详情 HTML</p>",
    "price": "99.00",
    "sales_count": 120,
    "status": 1,
    "category": {
      "id": 2,
      "name": "数码"
    },
    "skus": [
      {
        "id": 10,
        "sku_code": "SKU001",
        "specs": {"颜色": "黑色", "尺码": "M"},
        "price": "99.00",
        "stock": 50,
        "status": 1
      }
    ]
  }
}
```

---

### 4.3 分类列表
`GET /products/categories`

**成功响应**
```json
{
  "code": 0,
  "message": "ok",
  "data": [
    {
      "id": 1,
      "name": "服装",
      "children": [
        {"id": 2, "name": "男装"},
        {"id": 3, "name": "女装"}
      ]
    }
  ]
}
```

---

### 4.4 首页推荐 / 热销
`GET /products/hot`

**查询参数**：`limit`（默认 10）

返回已上架、按销量或配置排序的商品简要列表。

---

## 5. 购物车模块 `/cart`

### 5.1 获取购物车
`GET /cart`

**需要认证**

**成功响应**
```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "items": [
      {
        "id": 1,
        "sku_id": 10,
        "product_id": 1,
        "title": "示例商品",
        "specs": {"颜色": "黑色"},
        "image": "https://...",
        "price": "99.00",
        "quantity": 2,
        "stock": 50,
        "valid": true,
        "subtotal": "198.00"
      }
    ],
    "total_amount": "198.00",
    "total_quantity": 2
  }
}
```

`valid = false` 表示商品已下架或库存不足。

---

### 5.2 加入购物车
`POST /cart`

**需要认证**

**请求体**
```json
{
  "sku_id": 10,
  "quantity": 1
}
```

**错误示例**：库存不足 → `code: 40009, message: "库存不足"`

---

### 5.3 修改购物车数量
`PUT /cart/{id}`

**需要认证**

**请求体**
```json
{
  "quantity": 3
}
```

---

### 5.4 删除购物车项
`DELETE /cart/{id}`

**需要认证**

---

### 5.5 清空购物车
`DELETE /cart`

**需要认证**

---

## 6. 订单模块 `/orders`

### 6.1 创建订单（结算）
`POST /orders`

**需要认证**

**请求体**
```json
{
  "address_id": 1,
  "cart_item_ids": [1, 2],
  "remark": "请尽快发货"
}
```

| 字段 | 说明 |
|------|------|
| address_id | 收货地址 ID |
| cart_item_ids | 要结算的购物车项 ID 列表；不传则结算全部有效项 |
| remark | 订单备注 |

**成功响应**
```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "order_id": 1001,
    "order_no": "CS202609150001",
    "pay_amount": "208.00",
    "status": "pending_payment"
  }
}
```

**常见错误**：
- 无收货地址 / 地址不存在
- 购物车商品失效或库存不足
- 部分商品已下架

---

### 6.2 订单列表
`GET /orders`

**需要认证**

**查询参数**

| 参数 | 说明 |
|------|------|
| status | pending_payment / paid / shipped / completed / cancelled / refunding / refunded |
| page / page_size | 分页 |

**成功响应**（列表项简要信息 + 分页）

---

### 6.3 订单详情
`GET /orders/{id}` 或 `GET /orders/by-no/{order_no}`

**需要认证**（仅本人订单）

**成功响应**
```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "id": 1001,
    "order_no": "CS202609150001",
    "status": "paid",
    "total_amount": "198.00",
    "freight_amount": "10.00",
    "pay_amount": "208.00",
    "remark": "请尽快发货",
    "address_snapshot": {
      "contact_name": "张三",
      "contact_phone": "13800138000",
      "full_address": "广东省深圳市南山区科技园路 1 号"
    },
    "items": [
      {
        "product_title": "示例商品",
        "sku_specs": {"颜色": "黑色"},
        "sku_image": "https://...",
        "price": "99.00",
        "quantity": 2,
        "total_amount": "198.00"
      }
    ],
    "paid_at": "2026-09-15 10:00:00",
    "created_at": "2026-09-15 09:50:00"
  }
}
```

---

### 6.4 取消订单
`POST /orders/{id}/cancel`

**需要认证**

**请求体**（可选）
```json
{
  "reason": "不想要了"
}
```

**限制**：仅 `pending_payment` 或 `paid`（未发货）可取消。

---

### 6.5 申请退款
`POST /orders/{id}/refund`

**需要认证**

**请求体**
```json
{
  "reason": "质量问题",
  "amount": "208.00"
}
```

`amount` 可不传，默认整单退款金额。

---

## 7. 支付模块 `/payments`

### 7.1 发起支付
`POST /payments`

**需要认证**

**请求体**
```json
{
  "order_no": "CS202609150001",
  "channel": "wechat"
}
```

| channel | 说明 |
|---------|------|
| wechat | 微信支付 |
| alipay | 支付宝 |

**成功响应**
```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "payment_no": "PAY202609150001",
    "pay_params": {
      // 前端调起支付所需的参数（渠道相关）
    }
  }
}
```

---

### 7.2 支付回调（渠道通知）
`POST /payments/callback/{channel}`

**无需用户 Token**（需验签）

由支付渠道服务器调用，系统验签 + 幂等处理后更新订单状态。

**内部处理要点**：
1. 验证签名
2. 根据 `payment_no` / 渠道单号查单
3. 幂等：已成功则直接返回成功
4. 事务更新 `payments` + `orders` 状态

---

### 7.3 查询支付状态
`GET /payments/{payment_no}`

**需要认证**

用于前端轮询支付结果。

---

## 8. 后台管理模块 `/admin`

> 所有 `/admin/*` 接口需要登录 + 对应权限码。

### 8.1 商品管理

#### 商品列表
`GET /admin/products`

**权限**：`product.view`

查询参数：`keyword`、`category_id`、`status`、`page`、`page_size`

#### 创建商品
`POST /admin/products`

**权限**：`product.create`

**请求体示例**
```json
{
  "category_id": 2,
  "title": "新商品",
  "subtitle": "",
  "main_image": "https://...",
  "images": ["https://..."],
  "description": "<p>...</p>",
  "status": 0,
  "skus": [
    {
      "sku_code": "SKU100",
      "specs": {"颜色": "白"},
      "price": "129.00",
      "stock": 100
    }
  ]
}
```

#### 更新商品
`PUT /admin/products/{id}`

**权限**：`product.update`

#### 上下架
`POST /admin/products/{id}/status`

**权限**：`product.update`

```json
{ "status": 1 }
```

#### 批量上下架 / 调整库存
`POST /admin/products/batch`

**权限**：`product.update`

```json
{
  "ids": [1, 2, 3],
  "action": "on_shelf",
  "stock_adjust": null
}
```

`action`：`on_shelf` / `off_shelf` / `adjust_stock`

---

### 8.2 分类管理

`GET    /admin/categories`  
`POST   /admin/categories`  
`PUT    /admin/categories/{id}`  
`DELETE /admin/categories/{id}`

**权限**：`category.manage`

---

### 8.3 订单管理

#### 订单列表
`GET /admin/orders`

**权限**：`order.view`

查询参数：`order_no`、`user_id`、`status`、`start_time`、`end_time`、`page`、`page_size`

#### 订单详情
`GET /admin/orders/{id}`

**权限**：`order.view`

#### 发货
`POST /admin/orders/{id}/ship`

**权限**：`order.ship`

```json
{
  "remark": "已发货"
}
```

#### 订单导出
`GET /admin/orders/export`

**权限**：`order.export`

返回文件下载或异步任务 ID。

---

### 8.4 退款处理

#### 退款列表
`GET /admin/refunds`

**权限**：`refund.view`

#### 审核退款
`POST /admin/refunds/{id}/process`

**权限**：`refund.process`

```json
{
  "action": "approve",
  "admin_remark": "同意退款"
}
```

`action`：`approve` / `reject`

---

### 8.5 数据概览
`GET /admin/dashboard`

**权限**：`dashboard.view`

**成功响应示例**
```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "today_orders": 28,
    "today_sales": "5680.00",
    "yesterday_orders": 35,
    "yesterday_sales": "7200.00",
    "pending_ship": 12,
    "pending_refund": 3
  }
}
```

---

### 8.6 系统配置
`GET  /admin/configs`  
`PUT  /admin/configs`

**权限**：`config.manage`

用于修改 `order.timeout_minutes`、`order.freight_default`、`inventory.warning_threshold` 等。

---

### 8.7 操作日志
`GET /admin/operation-logs`

**权限**：`log.view`

查询参数：`user_id`、`module`、`start_time`、`end_time`、分页。

---

### 8.8 用户管理
`GET /admin/users`  
`GET /admin/users/{id}`  
`PUT /admin/users/{id}`  
`PUT /admin/users/{id}/status`

**权限**：`user.manage`

管理前台注册买家（sys_user，角色 customer）。查询参数：`keyword`（用户名/昵称/手机号/邮箱模糊）、`status`（1 正常 / 0 禁用）、`role`（customer 默认 / admin 后台账号 / all）、`start_time` / `end_time`（注册时间范围）、分页。

- 列表与详情含订单统计：`order_count`（有效订单数）、`total_paid`（累计实付）；详情另含 `recent_orders`（最近 10 笔）。
- 编辑仅支持昵称 / 手机号 / 邮箱（唯一性校验）。
- 禁用立即吊销全部 Token 强制下线；禁止禁用当前登录账号；超级管理员账号不允许在此禁用/编辑。
- 关键写操作（编辑 / 启用 / 禁用）记录操作日志（module=user）。

---

### 8.9 收货地址管理（设计文档 CubeShop_Address_Design_v1.0 §5）
`GET  /admin/users/{userId}/addresses`  
`PUT  /admin/addresses/{id}`

**权限**：查看 `address.view`；代改 `address.manage`

后台无独立地址菜单、无全局地址列表；按用户逐个查看（用户管理详情内嵌）。

- 查看：某用户地址列表（默认地址置顶），手机号脱敏 + `contact_phone_full` 供编辑回显，附 `updated_at`。
- 代改：仅 `contact_name / contact_phone / province / city / district / detail_address`（全部 sometimes）；传 `is_default` / `user_id` 显式拒绝 40000；不提供代新增 / 删除 / 设默认。
- 代改写操作日志（module=address，含 before/after 快照）。
- **订单收货信息以下单时刻 `orders.address_snapshot` 快照为准，代改不影响历史订单。**
- 前台新增地址上限每用户 20 条（超出 40000）。

### 8.10 角色权限
`GET    /admin/roles`（角色列表 + 权限分组一次返回）  
`GET    /admin/permissions`（仅权限分组）  
`POST   /admin/roles`  
`PUT    /admin/roles/{id}`  
`DELETE /admin/roles/{id}`

**权限**：`role.manage`（超管专属）

**角色中文名**：`name` 为英文标识（程序用、唯一），`display_name` 为中文名（展示用）。列表返回 `label`，取值顺序 `display_name` → 内置中文映射（super_admin=超级管理员 / operator=运营 / customer=买家）→ `name`。

- 新增：`name` 必填（2~32 位 alpha_dash、唯一）、`display_name` **必填**（≤64 字符）、`permissions` 可选——一次提交即完成「建角色 + 配权限」。
- 编辑：`name` / `display_name` / `permissions` 均 sometimes。内置角色的英文标识不可改（40003），但**中文名允许修改**（不影响权限判断）。
- 删除：内置角色不可删（40003）；角色下仍有账号时拒绝（40009）。
- 角色与权限变更均写操作日志（module=role，含 before/after），并刷新 spatie 权限缓存。

### 8.11 支付管理（payments）
`GET  /admin/payments`（列表 + 汇总）  
`GET  /admin/payments/export`（CSV 导出，最多 5000 条）  
`GET  /admin/payments/{id}`（详情，含支付日志时间轴）  
`POST /admin/payments/{id}/close`（关闭待支付单，body: `reason?`）

**权限**：查看 `payment.view`（运营 + 超管）；关闭 `payment.manage`（仅超管）

查询参数：`payment_no`（模糊）、`order_no`（模糊）、`user_id`、`channel`（wechat/alipay）、`status`（pending/success/failed/closed）、`start_time`、`end_time`、分页。

- 列表额外返回 `summary`：当前筛选下的总笔数、成功笔数、成功金额、待支付数、失败数。
- 详情返回 `order`（订单号/状态/实付）与 `logs`（该支付单的 payment_logs，按 id 正序，含完整 request/response）。
- **关闭仅对 `pending` 生效**，非待支付返回 40009；关闭只作用于支付单本身，**不联动取消订单**（买家可重新发起支付）；关闭写 `payment_logs`（event=close）与操作日志（module=payment）。

### 8.12 支付日志（payment_logs）
`GET /admin/payment-logs`（列表）  
`GET /admin/payment-logs/{id}`（详情，完整 JSON）

**权限**：`payment.view`（只读，无任何写入口）

查询参数：`payment_no`（模糊）、`event`（create/callback/notify/close）、`start_time`、`end_time`、分页。

- 列表回传 `request_preview` / `response_preview`（JSON 摘要，超 160 字符截断），避免载荷过大；详情回传完整 `request_data` / `response_data`。

### 8.13 订单状态流水（order_logs）
`GET /admin/order-logs`（列表）  
`GET /admin/orders/{orderId}/logs`（单笔订单时间轴，正序）

**权限**：`order.log`（只读，运营 + 超管）

查询参数：`order_no`（模糊，关联 orders）、`order_id`、`to_status`、`operator_type`（user/admin/system）、`start_time`、`end_time`、分页。

- 返回字段含 `from_status_label` / `to_status_label`（中文）与 `operator_name`（账号昵称/用户名，system 为空）。
- **order_logs 为只写不改的审计流水**，唯一写入点是 `OrderService::transitionTo()`（下单/支付/发货/取消/退款等状态机），后台不提供任何新增、修改、删除入口。

---

## 9. 权限码参考（与 spatie 对齐）

| 权限码 | 说明 |
|--------|------|
| product.view | 查看商品 |
| product.create | 创建商品 |
| product.update | 编辑/上下架商品 |
| category.manage | 分类管理 |
| order.view | 查看订单 |
| order.ship | 发货 |
| order.export | 导出订单 |
| refund.view | 查看退款 |
| refund.process | 处理退款 |
| dashboard.view | 数据概览 |
| config.manage | 系统配置 |
| log.view | 操作日志 |
| user.manage | 用户管理（买家账号查看/搜索/禁用） |
| address.view | 查看用户收货地址 |
| address.manage | 代用户修改收货地址 |
| payment.view | 查看支付单与支付日志 |
| payment.manage | 关闭待支付单（超管专属） |
| order.log | 查看订单状态流水 |

超级管理员拥有全部权限。

---

## 10. 状态流转速查

### 订单状态
```
pending_payment → paid → shipped → completed
       ↓            ↓
   cancelled     refunding → refunded
                   ↓
                cancelled（部分场景）
```

### 支付状态
`pending` → `success` / `failed` / `closed`

### 退款状态
`pending` → `approved` → `success`  
`pending` → `rejected`  
`approved` → `failed`（渠道退款失败）

---

## 11. 注意事项

1. **幂等**：支付回调、创建订单等关键写接口需做幂等控制。
2. **库存**：下单时锁定库存，取消/超时释放，支付成功确认扣减。
3. **快照**：订单明细中的商品信息以下单时快照为准。
4. **限流**：登录、发送验证码、下单接口建议限流。
5. **文件上传**：商品图片上传可走独立接口（如 `POST /admin/upload`），返回 URL 后再提交商品。
6. **时间格式**：统一 ISO 8601 或 `Y-m-d H:i:s`（与前端约定）。

---

**—— API 接口文档结束 ——**
