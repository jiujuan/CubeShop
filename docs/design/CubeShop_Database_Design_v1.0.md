# CubeShop 电商系统
## 数据库设计文档（Database Design）
**版本**：v1.0（MVP）  
**日期**：2026-09-15  
**数据库**：PostgreSQL 14+  
**关联文档**：CubeShop_PRD_v1.0、CubeShop_Architecture_v1.0、CubeShop_技术选型

> **V1.1 更新（2026-09-16）· 用户表拆分**
> 账号体系拆为两表：`sys_user` 收敛为**后台管理员**专表，买家迁至 **`users`**（模型 `App\Models\User`），二者物理隔离。
> 买家不再参与 spatie 权限体系，原 `customer` 角色已彻底移除（后台角色仅 `super_admin` / `operator`）。
> 连带变化：`user_addresses` / `cart_items` / `orders` 的 `user_id` 外键由 `sys_user` 重指向 `users`；
> `sys_operation_log` 新增 `actor_type`、`notifications` 新增 `receiver_type`（两列均为混合语义身份的显式区分）。
> 详见 `docs/design/CubeShop_UserTable_Split_Analysis.md` 与验收报告 `docs/testing/evidence/v1.1/user-split/`。

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
- 表名：小写蛇形，复数或业务含义清晰（如 `orders`）
- 前缀约定：`sys_` 前缀的**系统表用单数**（`sys_user`、`sys_operation_log`），**业务表用复数**（`users`、`orders`、`user_balances`）
- 主键：统一 `id`（BIGINT，雪花或序列）
- 时间字段：`created_at`、`updated_at`、`deleted_at`
- 状态字段：`status` + 注释说明枚举值
- 金额字段：统一使用 `DECIMAL(12,2)`，单位元

### 1.3 表清单概览

| 模块 | 表名 | 说明 |
|------|------|------|
| 账号 | users | **买家**（前台注册用户） |
| 账号 | sys_user | **后台管理员** |
| 系统与权限 | roles | 角色（仅后台：super_admin / operator） |
| | permissions | 权限 |
| | model_has_roles | 管理员-角色关联 |
| | model_has_permissions | 用户-权限关联（可选直授） |
| | role_has_permissions | 角色-权限关联 |
| | sys_operation_log | 操作日志（`actor_type` 区分买家/管理员） |
| | system_configs | 系统配置 |
| | notifications | 站内通知（`receiver_type` 区分买家/管理员） |
| 用户地址 | user_addresses | 收货地址（买家） |
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

> 注：本节为 V1.0 基线表清单。V1.1 / V1.2 新增的表（余额、充值、评价、收藏、足迹、品牌/属性库、优惠券等）见文末「附录 A」。

---

## 2. 表结构详细设计

### 2.1 账号与权限

> **账号模型（V1.1 拆分后）**：`users`（买家）+ `sys_user`（后台管理员），两表结构对齐但**账号体系物理隔离**。
> 买家不参与 spatie 权限；管理员独占 `roles` / `permissions` 体系。

#### 2.1.1 sys_user（后台管理员）
| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| id | BIGSERIAL | PK | 主键 |
| username | VARCHAR(64) | UNIQUE, NOT NULL | 登录名 |
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

**说明**：本表**仅存后台管理员**（超级管理员 / 运营）。V1.1 之前注册的买家已按原 ID 迁至 `users`，本表中原有的买家记录已于清理阶段删除（迁移 `2026_09_16_000028`）。管理员与买家各自独立自增序列，ID 可能重复，**跨表比较 ID 无意义**。

---

#### 2.1.2 users（买家）
| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| id | BIGSERIAL | PK | 主键 |
| username | VARCHAR(64) | UNIQUE, NOT NULL | 登录名 |
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

**说明**：前台注册用户（买家）；模型 `App\Models\User`（`HasApiTokens` + `SoftDeletes`，**不含** `HasRoles`）。
买家身份识别即「本表存在记录」，不再使用角色。所有业务表（`orders` / `cart_items` / `user_addresses` / `user_balances` 等）的 `user_id` 一律指向本表。

---

#### 2.1.3 roles / permissions（spatie 标准）
与 `spatie/laravel-permission` 保持一致，便于直接使用包。**仅后台管理员使用**。

**roles**
| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGSERIAL | PK |
| name | VARCHAR(125) | 角色名（当前内置 super_admin、operator） |
| guard_name | VARCHAR(125) | 守卫名（本项目为 web） |
| created_at / updated_at | TIMESTAMP | |

> V1.1 拆分后 `customer` 角色已彻底移除（买家不参与 spatie），详见迁移 `2026_09_16_000028`。

**permissions**
| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGSERIAL | PK |
| name | VARCHAR(125) | 权限码（如 product.create） |
| guard_name | VARCHAR(125) | |
| created_at / updated_at | TIMESTAMP | |

**关联表**：`model_has_roles`、`model_has_permissions`、`role_has_permissions`（按 spatie 标准结构）

---

#### 2.1.4 sys_operation_log（操作日志）
| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| id | BIGSERIAL | PK | |
| user_id | BIGINT | | 操作人 ID（**混合语义**，配合 actor_type 解读） |
| actor_type | VARCHAR(16) | NOT NULL DEFAULT 'admin' | 操作人来源：admin=后台管理员 / customer=买家（V1.1 新增） |
| module | VARCHAR(64) | | 模块（product/order/...） |
| action | VARCHAR(64) | | 动作（create/update/ship/...） |
| target_type | VARCHAR(64) | | 目标类型 |
| target_id | BIGINT | | 目标 ID |
| content | TEXT | | 变更摘要/JSON |
| ip | VARCHAR(45) | | |
| user_agent | VARCHAR(512) | | |
| created_at | TIMESTAMP | NOT NULL | |

**索引**：actor_type、(user_id, created_at)

> **注意**：`user_id` 同时承载买家与管理员 ID（两表各自自增、会撞号），必须结合 `actor_type` 才能还原真正的操作人。

---

#### 2.1.5 system_configs（系统配置）
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

#### 2.1.6 notifications（站内通知）
| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| id | BIGSERIAL | PK | |
| user_id | BIGINT | NOT NULL | 收件人 ID（**混合语义**，配合 receiver_type 解读） |
| receiver_type | VARCHAR(16) | NOT NULL DEFAULT 'customer' | 收件人来源：customer=买家 / admin=后台管理员（V1.1 新增） |
| type | VARCHAR(64) | NOT NULL | 通知类型（order_paid / low_stock / password_changed ...） |
| title | VARCHAR(191) | NOT NULL | 标题 |
| content | TEXT | | 内容 |
| link | VARCHAR(512) | | 跳转链接 |
| is_read | BOOLEAN | NOT NULL DEFAULT FALSE | 是否已读 |
| read_at | TIMESTAMP | | 已读时间 |
| created_at / updated_at | TIMESTAMP | | |

**索引**：(user_id, is_read)、(receiver_type, user_id, is_read)

> 库存预警等发给运营的通知，其 `user_id` 是**管理员** ID；买家读取通知时必须按 `receiver_type=customer` 过滤，否则会因 ID 撞号读到发给运营的预警（V1.1 已修复）。

---

### 2.2 用户地址

#### user_addresses
| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| id | BIGSERIAL | PK | |
| user_id | BIGINT | NOT NULL, FK → users | 买家（`users.id`） |
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
| user_id | BIGINT | NOT NULL, FK → users | 买家 |
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
| user_id | BIGINT | NOT NULL, FK → users | 买家 |
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
> V1.2 收银台扩展：订单支付与余额充值**共用本表**，由 `biz_type` + `biz_no` 分流；`order_id` / `order_no` 改为**可空**（充值单无订单）。

| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| id | BIGSERIAL | PK | |
| payment_no | VARCHAR(64) | UNIQUE, NOT NULL | 支付单号 |
| biz_type | VARCHAR(32) | NOT NULL DEFAULT 'order' | 业务类型：order / recharge（V1.2） |
| biz_no | VARCHAR(64) | | 业务单号（订单号 / 充值单号，V1.2） |
| order_id | BIGINT | FK, **NULL** | 关联订单；充值为 NULL（V1.2 起可空） |
| order_no | VARCHAR(32) | **NULL** | 冗余订单号；充值为 NULL |
| user_id | BIGINT | NOT NULL | 买家（`users.id`） |
| channel | VARCHAR(32) | NOT NULL | wechat / alipay / balance / offline |
| amount | DECIMAL(12,2) | NOT NULL | 支付金额 |
| status | VARCHAR(32) | NOT NULL | pending / success / failed / closed |
| channel_trade_no | VARCHAR(128) | | 渠道交易号 |
| paid_at | TIMESTAMP | | |
| created_at / updated_at | TIMESTAMP | | |

**线下转账扩展字段（V1.2）**：`payer_name`、`payer_account`、`transfer_no`、`transferred_at`、`voucher_url`、`review_remark`、`reviewed_by`、`reviewed_at`。

**索引**：`(payment_no)`、`(order_id)`、`(biz_type, biz_no)`

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
| user_id | BIGINT | NOT NULL | 买家（`users.id`） |
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
| users.status / sys_user.status | 0 / 1 | 禁用 / 正常 |
| products.status | 0 / 1 | 下架 / 上架 |
| orders.status | pending_payment, paid, shipped, completed, cancelled, refunding, refunded | 订单状态机 |
| payments.status | pending, success, failed, closed | 支付状态 |
| refunds.status | pending, approved, rejected, success, failed | 退款状态 |
| inventory_logs.change_type | lock, unlock, deduct, increase, adjust | 库存变更类型 |
| sys_operation_log.actor_type | admin, customer | 操作人来源（V1.1） |
| notifications.receiver_type | customer, admin | 收件人来源（V1.1） |

---

## 4. 关键关系说明

```
users 1 ─── N user_addresses
users 1 ─── N cart_items
users 1 ─── N orders
users 1 ─── N payments
users 1 ─── N refunds

sys_user 1 ─── N model_has_roles        （后台权限）

users     1 ─── N notifications          （receiver_type = customer）
sys_user  1 ─── N notifications          （receiver_type = admin）

users / sys_user 1 ─── N sys_operation_log（按 actor_type 区分）

categories 1 ─── N products
products 1 ─── N product_skus
products 1 ─── N product_images
product_skus 1 ─── 1 inventories

orders 1 ─── N order_items
orders 1 ─── N payments
orders 1 ─── N refunds

product_skus 1 ─── N inventory_logs
```

> **账号隔离要点**：`users` 与 `sys_user` 是两套独立账号，主键各自自增、**ID 会撞号**。
> 凡「操作人 / 收件人」这类跨身份字段（`sys_operation_log.user_id`、`notifications.user_id`），
> 必须配合 `actor_type` / `receiver_type` 使用，不可仅凭 ID 归属。

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
| users | UNIQUE(username), UNIQUE(phone), UNIQUE(email), (status) |
| sys_user | UNIQUE(username), UNIQUE(phone), UNIQUE(email), (status) |
| notifications | (user_id, is_read), (receiver_type, user_id, is_read) |
| sys_operation_log | (actor_type), (user_id, created_at) |

---

## 6. 与架构/公共服务的对应

| 能力 | 表/字段支撑 |
|------|-------------|
| 账号体系 | users（买家）/ sys_user（后台管理员），V1.1 起物理隔离 |
| 订单号生成 | orders.order_no / payments.payment_no / refunds.refund_no |
| 库存事务 | inventories + inventory_logs + 乐观锁 version |
| 商品快照 | order_items 中的 title/specs/price/image |
| 操作日志 | sys_operation_log（+ actor_type 区分身份） |
| 站内通知 | notifications（+ receiver_type 区分身份） |
| 系统配置 | system_configs |
| 权限 | roles / permissions + 关联表（仅后台管理员） |

---

## 7. 后续可扩展（非 V1.0）

- 优惠券、满减活动表
- ~~评价表~~（V1.1 已实现 `reviews`）
- 物流轨迹表
- 分销/佣金表
- 会员积分表

---

## 附录 A：V1.1 / V1.2 新增表（超出 v1.0 基线）

以下表在 v1.0 之后随迭代落地，**本节仅作索引**，详细字段以对应迁移与设计文档为准。

| 模块 | 表 | 说明 | 来源 |
|------|----|------|------|
| 订单 | order_logs | 订单状态变更流水 | V1.1 T-001 |
| 商品 | brands | 品牌库 | V1.1 T-007 |
| 商品 | attributes / attribute_values | 属性库与属性值 | V1.1 T-007 |
| 商品 | category_attributes | 分类属性模板 | V1.1 T-007 |
| 商品 | product_attribute_values | 商品-属性值关联 | V1.1 T-007 |
| 评价 | reviews | 商品评价与评分汇总 | V1.1 T-015 |
| 通知 | notifications | 站内通知（详见 §2.1.6） | V1.1 T-018 |
| 收藏/足迹 | favorites / browse_histories | 收藏与浏览足迹 | V1.1 T-024 |
| 余额 | user_balances | 买家余额账户 | V1.2 收银台 |
| 余额 | user_balance_logs | 余额流水（唯一资金口写入） | V1.2 收银台 |
| 充值 | balance_recharges | 余额充值单 | V1.2 收银台 |
| 支付 | payment_channels | 支付渠道配置（scene 区分支付/充值） | V1.2 收银台 |
| 支付 | payments | 扩展：`biz_type` + `biz_no` 分流订单支付与余额充值，`order_id` 可空 | V1.2 收银台 |
| 基础设施 | biz_no_sequences | 业务单号序列（订单号/支付单号等） | V1.1 |
| 基础设施 | personal_access_tokens | Sanctum 访问令牌（`tokenable_type` 区分 User / SysUser） | V1.0 |

> 余额/充值/支付相关设计见 `docs/design/CubeShop_Cashier_Payment_Design_v1.0.md`；
> 商品属性体系见 `docs/design/v1.1/`；用户表拆分见 `docs/design/CubeShop_UserTable_Split_Analysis.md`。
> 框架自带表（`jobs` / `job_batches` / `failed_jobs` / `cache` / `cache_locks`）不在此列。

---

**—— 数据库设计文档结束 ——**
