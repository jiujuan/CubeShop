# CubeShop 电商系统
## 数据库设计文档（Database Design）
**版本**：v1.0（MVP）  
**日期**：2026-09-15  
**数据库**：PostgreSQL 14+  
**关联文档**：CubeShop_PRD_v1.0、CubeShop_Architecture_v1.0、CubeShop_技术选型

---

## 1. 设计说明

### 1.1 设计原则
| 原则 | 说明 |
|------|------|
| 业务闭环优先 | 覆盖用户、商品、库存、购物车、订单、支付、退款完整链路 |
| 快照隔离 | 订单明细保存下单时商品信息，避免后续改价影响历史 |
| 库存可追溯 | 所有库存变更写流水表 |
| 软删除 | 用户、商品等核心数据优先软删除 |
| 可扩展 | 规格、配置使用 JSONB，便于后续扩展 |
| 权限兼容 | 兼容 spatie/laravel-permission 标准表结构 |

### 1.2 命名约定
- 表名：小写蛇形，复数或业务含义清晰（如 `orders`、`sys_user`）
- 主键：统一 `id`（BIGINT，雪花或序列）
- 时间字段：`created_at`、`updated_at`、`deleted_at`
- 状态字段：`status` + 注释说明枚举值
- 金额字段：统一使用 `DECIMAL(12,2)`，单位元

### 1.3 表清单概览

| 模块 | 表名 | 说明 |
|------|------|------|
| 系统与权限 | sys_user | 系统用户（买家+管理员） |
| | roles | 角色 |
| | permissions | 权限 |
| | model_has_roles | 用户-角色关联 |
| | model_has_permissions | 用户-权限关联（可选直授） |
| | role_has_permissions | 角色-权限关联 |
| | sys_operation_log | 操作日志 |
| | system_configs | 系统配置 |
| 用户地址 | user_addresses | 收货地址 |
| 商品 | categories | 商品分类 |
| | products | 商品主表 |
| | product_skus | 商品 SKU |
| | product_images | 商品图片 |
| 库存 | inventories | 库存（按 SKU） |
| | inventory_logs | 库存流水 |
| 购物车 | cart_items | 购物车项 |
| 订单 | orders | 订单主表 |
| | order_items | 订单明细（含快照） |
| 支付 | payments | 支付记录 |
| | payment_logs | 支付回调/日志 |
| 售后 | refunds | 退款申请 |

---

## 2. 表结构详细设计

### 2.1 系统与权限

#### 2.1.1 sys_user（系统用户）
| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| id | BIGSERIAL | PK | 主键 |
| username | VARCHAR(64) | UNIQUE, NOT NULL | 登录名（手机号/邮箱/自定义） |
| email | VARCHAR(128) | UNIQUE | 邮箱 |
| phone | VARCHAR(20) | UNIQUE | 手机号 |
| password | VARCHAR(255) | NOT NULL | 密码哈希 |
| nickname | VARCHAR(64) | | 昵称 |
| avatar | VARCHAR(512) | | 头像 URL |
| status | SMALLINT | NOT NULL DEFAULT 1 | 1=正常 0=禁用 |
| last_login_at | TIMESTAMP | | 最后登录时间 |
| last_login_ip | VARCHAR(45) | | 最后登录 IP |
| created_at | TIMESTAMP | NOT NULL | |
| updated_at | TIMESTAMP | NOT NULL | |
| deleted_at | TIMESTAMP | | 软删除 |

**索引**：username、email、phone、status

---

#### 2.1.2 roles / permissions（spatie 标准）
与 `spatie/laravel-permission` 保持一致，便于直接使用包。

**roles**
| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGSERIAL | PK |
| name | VARCHAR(125) | 角色名（如 admin、operator） |
| guard_name | VARCHAR(125) | 守卫名（通常 web） |
| created_at / updated_at | TIMESTAMP | |

**permissions**
| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGSERIAL | PK |
| name | VARCHAR(125) | 权限码（如 product.create） |
| guard_name | VARCHAR(125) | |
| created_at / updated_at | TIMESTAMP | |

**关联表**：`model_has_roles`、`model_has_permissions`、`role_has_permissions`（按 spatie 标准结构）

---

#### 2.1.3 sys_operation_log（操作日志）
| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| id | BIGSERIAL | PK | |
| user_id | BIGINT | | 操作人 |
| module | VARCHAR(64) | | 模块（product/order/...） |
| action | VARCHAR(64) | | 动作（create/update/ship/...） |
| target_type | VARCHAR(64) | | 目标类型 |
| target_id | BIGINT | | 目标 ID |
| content | TEXT | | 变更摘要/JSON |
| ip | VARCHAR(45) | | |
| user_agent | VARCHAR(512) | | |
| created_at | TIMESTAMP | NOT NULL | |

---

#### 2.1.4 system_configs（系统配置）
| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| id | BIGSERIAL | PK | |
| config_key | VARCHAR(128) | UNIQUE, NOT NULL | 配置键（如 order.timeout_minutes） |
| config_value | TEXT | | 配置值 |
| description | VARCHAR(255) | | 说明 |
| created_at / updated_at | TIMESTAMP | | |

**预置配置示例**：
- `order.timeout_minutes` = 30
- `order.freight_default` = 10.00
- `inventory.warning_threshold` = 10

---

### 2.2 用户地址

#### user_addresses
| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| id | BIGSERIAL | PK | |
| user_id | BIGINT | NOT NULL, FK → sys_user | |
| contact_name | VARCHAR(64) | NOT NULL | 收货人 |
| contact_phone | VARCHAR(20) | NOT NULL | 手机 |
| province | VARCHAR(64) | | 省 |
| city | VARCHAR(64) | | 市 |
| district | VARCHAR(64) | | 区 |
| detail_address | VARCHAR(255) | NOT NULL | 详细地址 |
| is_default | BOOLEAN | DEFAULT FALSE | 是否默认 |
| created_at / updated_at | TIMESTAMP | | |
| deleted_at | TIMESTAMP | | |

---

### 2.3 商品模块

#### categories（分类，支持二级）
| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| id | BIGSERIAL | PK | |
| parent_id | BIGINT | DEFAULT 0 | 父分类，0=一级 |
| name | VARCHAR(128) | NOT NULL | 分类名 |
| sort | INT | DEFAULT 0 | 排序，越大越靠前 |
| status | SMALLINT | DEFAULT 1 | 1=启用 0=禁用 |
| created_at / updated_at | TIMESTAMP | | |
| deleted_at | TIMESTAMP | | |

---

#### products（商品主表）
| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| id | BIGSERIAL | PK | |
| category_id | BIGINT | FK → categories | |
| title | VARCHAR(255) | NOT NULL | 商品标题 |
| subtitle | VARCHAR(255) | | 副标题 |
| main_image | VARCHAR(512) | | 主图 URL |
| description | TEXT | | 详情（富文本/HTML） |
| price | DECIMAL(12,2) | NOT NULL | 展示价（最低 SKU 价可冗余） |
| status | SMALLINT | NOT NULL DEFAULT 0 | 0=下架 1=上架 |
| sales_count | INT | DEFAULT 0 | 销量（冗余） |
| sort | INT | DEFAULT 0 | 排序 |
| created_at / updated_at | TIMESTAMP | | |
| deleted_at | TIMESTAMP | | |

---

#### product_skus（SKU）
| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| id | BIGSERIAL | PK | |
| product_id | BIGINT | NOT NULL, FK → products | |
| sku_code | VARCHAR(64) | UNIQUE | SKU 编码 |
| specs | JSONB | | 规格，如 `{"颜色":"红","尺码":"L"}` |
| price | DECIMAL(12,2) | NOT NULL | 售价 |
| status | SMALLINT | DEFAULT 1 | 1=启用 0=禁用 |
| created_at / updated_at | TIMESTAMP | | |
| deleted_at | TIMESTAMP | | |

---

#### product_images（商品图片）
| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| id | BIGSERIAL | PK | |
| product_id | BIGINT | NOT NULL, FK | |
| url | VARCHAR(512) | NOT NULL | 图片 URL |
| sort | INT | DEFAULT 0 | 排序 |
| created_at | TIMESTAMP | | |

---

### 2.4 库存模块

#### inventories（库存，按 SKU）
| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| id | BIGSERIAL | PK | |
| sku_id | BIGINT | UNIQUE, NOT NULL, FK → product_skus | |
| stock | INT | NOT NULL DEFAULT 0 | 可售库存 |
| locked_stock | INT | NOT NULL DEFAULT 0 | 锁定库存（下单未支付） |
| version | INT | NOT NULL DEFAULT 0 | 乐观锁版本号 |
| updated_at | TIMESTAMP | | |

**说明**：可用库存 = stock - locked_stock（或业务上 stock 为可售，locked 单独管理）

---

#### inventory_logs（库存流水）
| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| id | BIGSERIAL | PK | |
| sku_id | BIGINT | NOT NULL | |
| change_type | VARCHAR(32) | NOT NULL | lock / unlock / deduct / increase / adjust |
| change_qty | INT | NOT NULL | 变更数量（可正可负） |
| before_stock | INT | | 变更前可售 |
| after_stock | INT | | 变更后可售 |
| before_locked | INT | | |
| after_locked | INT | | |
| biz_type | VARCHAR(32) | | order / refund / adjust / cancel |
| biz_id | BIGINT | | 关联业务 ID（订单号等） |
| remark | VARCHAR(255) | | |
| operator_id | BIGINT | | 操作人（系统为 null） |
| created_at | TIMESTAMP | NOT NULL | |

---

### 2.5 购物车

#### cart_items
| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| id | BIGSERIAL | PK | |
| user_id | BIGINT | NOT NULL, FK | |
| sku_id | BIGINT | NOT NULL, FK | |
| quantity | INT | NOT NULL DEFAULT 1 | 数量 |
| created_at / updated_at | TIMESTAMP | | |

**唯一约束**：`(user_id, sku_id)`

---

### 2.6 订单模块

#### orders
| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| id | BIGSERIAL | PK | |
| order_no | VARCHAR(32) | UNIQUE, NOT NULL | 业务订单号 |
| user_id | BIGINT | NOT NULL, FK | |
| status | VARCHAR(32) | NOT NULL | pending_payment / paid / shipped / completed / cancelled / refunding / refunded |
| total_amount | DECIMAL(12,2) | NOT NULL | 商品总金额 |
| freight_amount | DECIMAL(12,2) | NOT NULL DEFAULT 0 | 运费 |
| pay_amount | DECIMAL(12,2) | NOT NULL | 应付金额 |
| address_snapshot | JSONB | NOT NULL | 收货地址快照 |
| remark | VARCHAR(255) | | 用户备注 |
| paid_at | TIMESTAMP | | 支付时间 |
| shipped_at | TIMESTAMP | | 发货时间 |
| completed_at | TIMESTAMP | | 完成时间 |
| cancelled_at | TIMESTAMP | | 取消时间 |
| cancel_reason | VARCHAR(255) | | |
| created_at / updated_at | TIMESTAMP | | |

**索引**：order_no、user_id、status、created_at

---

#### order_items（订单明细 + 商品快照）
| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| id | BIGSERIAL | PK | |
| order_id | BIGINT | NOT NULL, FK → orders | |
| product_id | BIGINT | | 原商品 ID（可空，防删除） |
| sku_id | BIGINT | | 原 SKU ID |
| product_title | VARCHAR(255) | NOT NULL | 快照标题 |
| sku_specs | JSONB | | 快照规格 |
| sku_image | VARCHAR(512) | | 快照图片 |
| price | DECIMAL(12,2) | NOT NULL | 下单单价 |
| quantity | INT | NOT NULL | 数量 |
| total_amount | DECIMAL(12,2) | NOT NULL | 小计 |
| created_at | TIMESTAMP | | |

---

### 2.7 支付模块

#### payments
| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| id | BIGSERIAL | PK | |
| payment_no | VARCHAR(64) | UNIQUE, NOT NULL | 支付单号 |
| order_id | BIGINT | NOT NULL, FK | |
| order_no | VARCHAR(32) | NOT NULL | 冗余订单号 |
| user_id | BIGINT | NOT NULL | |
| channel | VARCHAR(32) | NOT NULL | wechat / alipay |
| amount | DECIMAL(12,2) | NOT NULL | 支付金额 |
| status | VARCHAR(32) | NOT NULL | pending / success / failed / closed |
| channel_trade_no | VARCHAR(128) | | 渠道交易号 |
| paid_at | TIMESTAMP | | |
| created_at / updated_at | TIMESTAMP | | |

---

#### payment_logs
| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| id | BIGSERIAL | PK | |
| payment_id | BIGINT | | |
| payment_no | VARCHAR(64) | | |
| event | VARCHAR(64) | | create / callback / notify |
| request_data | JSONB | | |
| response_data | JSONB | | |
| created_at | TIMESTAMP | NOT NULL | |

---

### 2.8 售后（退款）

#### refunds
| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| id | BIGSERIAL | PK | |
| refund_no | VARCHAR(64) | UNIQUE, NOT NULL | 退款单号 |
| order_id | BIGINT | NOT NULL, FK | |
| order_no | VARCHAR(32) | NOT NULL | |
| user_id | BIGINT | NOT NULL | |
| amount | DECIMAL(12,2) | NOT NULL | 退款金额 |
| reason | VARCHAR(255) | | 用户原因 |
| status | VARCHAR(32) | NOT NULL | pending / approved / rejected / success / failed |
| admin_remark | VARCHAR(255) | | 后台备注 |
| processed_by | BIGINT | | 处理人 |
| processed_at | TIMESTAMP | | |
| created_at / updated_at | TIMESTAMP | | |

---

## 3. 状态枚举汇总

| 对象 | 状态值 | 说明 |
|------|--------|------|
| sys_user.status | 0 / 1 | 禁用 / 正常 |
| products.status | 0 / 1 | 下架 / 上架 |
| orders.status | pending_payment, paid, shipped, completed, cancelled, refunding, refunded | 订单状态机 |
| payments.status | pending, success, failed, closed | 支付状态 |
| refunds.status | pending, approved, rejected, success, failed | 退款状态 |
| inventory_logs.change_type | lock, unlock, deduct, increase, adjust | 库存变更类型 |

---

## 4. 关键关系说明

```
sys_user 1 ─── N user_addresses
sys_user 1 ─── N cart_items
sys_user 1 ─── N orders

categories 1 ─── N products
products 1 ─── N product_skus
products 1 ─── N product_images
product_skus 1 ─── 1 inventories

orders 1 ─── N order_items
orders 1 ─── N payments
orders 1 ─── N refunds

product_skus 1 ─── N inventory_logs
```

---

## 5. 索引与性能建议

| 表 | 建议索引 |
|----|----------|
| orders | (order_no), (user_id, status), (status, created_at) |
| order_items | (order_id) |
| cart_items | UNIQUE(user_id, sku_id) |
| inventories | UNIQUE(sku_id) |
| inventory_logs | (sku_id, created_at), (biz_type, biz_id) |
| payments | (payment_no), (order_id) |
| products | (status, category_id), (title) 可考虑全文检索后续 |
| sys_user | (username), (phone), (email) |

---

## 6. 与架构/公共服务的对应

| 能力 | 表/字段支撑 |
|------|-------------|
| 订单号生成 | orders.order_no / payments.payment_no / refunds.refund_no |
| 库存事务 | inventories + inventory_logs + 乐观锁 version |
| 商品快照 | order_items 中的 title/specs/price/image |
| 操作日志 | sys_operation_log |
| 系统配置 | system_configs |
| 权限 | roles / permissions + 关联表 |

---

## 7. 后续可扩展（非 V1.0）

- 优惠券、满减活动表
- 评价表
- 物流轨迹表
- 分销/佣金表
- 会员积分表

---

**—— 数据库设计文档结束 ——**
