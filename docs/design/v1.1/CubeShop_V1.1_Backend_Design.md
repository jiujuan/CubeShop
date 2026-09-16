# CubeShop V1.1 后端设计支撑（表结构 / 接口 / 服务）
**版本**：v1.1（规划稿）
**日期**：2026-09-15
**前置**：Laravel 13 + PostgreSQL（主库 `cubeshop`，测试库 `cubeshop_test`）+ Sanctum + spatie/permission；V1.0 共 21 张表。
**约定**：所有表沿用 V1.0 规范 —— `id` 主键、`created_at`/`updated_at` 时间戳、软删除按需、金额字段 `DECIMAL(10,2)`、状态用字符串常量。

> 本文档是 [总览](./CubeShop_V1.1_Overview.md)、[用户端功能](./CubeShop_V1.1_Feature_Web.md)、[后台功能](./CubeShop_V1.1_Feature_Admin.md) 的落地支撑，字段为草案，开发前按评审定稿。

---

## 1. 新增表结构草案（V1.1 共新增 11 张，改造 3 张）

### 1.1 一期

```sql
-- 评价（F01）
CREATE TABLE reviews (
  id BIGSERIAL PRIMARY KEY,
  order_id BIGINT NOT NULL,          -- 关联订单
  order_item_id BIGINT NOT NULL,     -- 行项目，一表一行评价
  user_id BIGINT NOT NULL,
  product_id BIGINT NOT NULL,
  sku_id BIGINT,
  rating SMALLINT NOT NULL,          -- 1~5
  content TEXT,
  images JSONB DEFAULT '[]',         -- 图片 URL 数组，最多 9
  is_anonymous BOOLEAN DEFAULT FALSE,
  status VARCHAR(20) DEFAULT 'approved',  -- pending/approved/rejected（先审后显开关控制默认值）
  reply_content TEXT,                -- 商家回复
  reply_at TIMESTAMPTZ,
  edited_at TIMESTAMPTZ,             -- 用户修改时间
  UNIQUE (order_item_id)
);

-- 站内通知（F02）
CREATE TABLE notifications (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL,
  type VARCHAR(50) NOT NULL,         -- order_paid/order_shipped/refund_result/reply/stock_alert
  title VARCHAR(200) NOT NULL,
  content TEXT,
  link VARCHAR(500),                 -- 跳转（订单详情等，前台路由）
  is_read BOOLEAN DEFAULT FALSE,
  read_at TIMESTAMPTZ
);

-- 收藏（F05）
CREATE TABLE favorites (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL,
  product_id BIGINT NOT NULL,
  UNIQUE (user_id, product_id)
);

-- 浏览足迹（F05）
CREATE TABLE browse_histories (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL,
  product_id BIGINT NOT NULL,
  browsed_at TIMESTAMPTZ NOT NULL,
  UNIQUE (user_id, product_id)       -- 每商品一条，重复浏览更新时间
);
```

### 1.2 二期

```sql
-- 优惠券（F06）
CREATE TABLE coupons (
  id BIGSERIAL PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  type VARCHAR(20) NOT NULL,         -- fixed(满减)/percent(折扣)
  amount DECIMAL(10,2),              -- fixed 面额
  percent SMALLINT,                  -- percent 折扣(1~99)
  min_spend DECIMAL(10,2) DEFAULT 0, -- 使用门槛
  max_discount DECIMAL(10,2),        -- 折扣券封顶
  scope VARCHAR(20) DEFAULT 'all',   -- all/category/product
  scope_refs JSONB DEFAULT '[]',     -- 分类/商品 id 数组
  total_count INT NOT NULL,          -- 发放总量
  issued_count INT DEFAULT 0,        -- 已领取（条件更新防超发）
  used_count INT DEFAULT 0,
  per_user_limit INT DEFAULT 1,
  valid_type VARCHAR(20) NOT NULL,   -- absolute(绝对)/relative(领取后N天)
  valid_from TIMESTAMPTZ,
  valid_to TIMESTAMPTZ,
  valid_days INT,
  status VARCHAR(20) DEFAULT 'active' -- active/stopped
);

CREATE TABLE user_coupons (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL,
  coupon_id BIGINT NOT NULL,
  status VARCHAR(20) DEFAULT 'unused', -- unused/used/expired/returned
  used_order_id BIGINT,
  used_at TIMESTAMPTZ,
  expire_at TIMESTAMPTZ NOT NULL       -- 领取时按 valid_type 计算固化
);

-- 满减活动（F06）
CREATE TABLE promotions (
  id BIGSERIAL PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  rules JSONB NOT NULL,               -- [{"min":100,"discount":10}, ...] 多级梯度
  scope VARCHAR(20) DEFAULT 'all',
  scope_refs JSONB DEFAULT '[]',
  start_at TIMESTAMPTZ NOT NULL,
  end_at TIMESTAMPTZ NOT NULL,
  status VARCHAR(20) DEFAULT 'active'
);

-- 物流（F07）
CREATE TABLE shippings (
  id BIGSERIAL PRIMARY KEY,
  order_id BIGINT NOT NULL,
  company_code VARCHAR(20) NOT NULL,
  company_name VARCHAR(50) NOT NULL,
  tracking_no VARCHAR(50) NOT NULL,
  trace_status VARCHAR(20) DEFAULT 'pending', -- pending/in_transit/delivered/failed
  shipped_at TIMESTAMPTZ,
  delivered_at TIMESTAMPTZ
);

CREATE TABLE shipping_traces (
  id BIGSERIAL PRIMARY KEY,
  shipping_id BIGINT NOT NULL,
  context VARCHAR(500) NOT NULL,      -- 轨迹描述
  occurred_at TIMESTAMPTZ NOT NULL,
  raw JSONB                           -- 第三方原始报文
);

-- 首页装修（F08）
CREATE TABLE banners (
  id BIGSERIAL PRIMARY KEY,
  image VARCHAR(500) NOT NULL,
  link_type VARCHAR(20),              -- product/category/url
  link_value VARCHAR(500),
  sort INT DEFAULT 0,
  start_at TIMESTAMPTZ,
  end_at TIMESTAMPTZ,
  status VARCHAR(20) DEFAULT 'active'
);

CREATE TABLE home_floors (
  id BIGSERIAL PRIMARY KEY,
  title VARCHAR(100) NOT NULL,
  type VARCHAR(20) NOT NULL,          -- products/category
  ref_id BIGINT,                      -- 分类 id（type=category 时）
  product_ids JSONB DEFAULT '[]',     -- 指定商品 id 数组
  sort INT DEFAULT 0,
  status VARCHAR(20) DEFAULT 'active'
);

CREATE TABLE announcements (
  id BIGSERIAL PRIMARY KEY,
  content VARCHAR(500) NOT NULL,
  start_at TIMESTAMPTZ,
  end_at TIMESTAMPTZ,
  status VARCHAR(20) DEFAULT 'active'
);
```

### 1.3 三期

```sql
-- 积分（F09）
CREATE TABLE points (
  user_id BIGINT PRIMARY KEY,
  balance INT NOT NULL DEFAULT 0
);

CREATE TABLE point_logs (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL,
  type VARCHAR(30) NOT NULL,          -- sign_in/order_earn/order_refund_rollback/deduct/revert
  change INT NOT NULL,                -- 正负
  balance_after INT NOT NULL,
  ref_type VARCHAR(20),               -- order/sign_in
  ref_id BIGINT
);

CREATE TABLE sign_ins (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL,
  sign_date DATE NOT NULL,
  streak INT DEFAULT 1,               -- 连续签到天数
  UNIQUE (user_id, sign_date)
);
```

### 1.4 存量表改造

| 表 | 改造 | 原因 |
|----|------|------|
| `orders` | 新增 `coupon_id`、`discount_amount`、`promotion_discount`、`amount_details JSONB`（优惠分摊快照）；**新增 `express_company`、`tracking_no`（冗余，E03）**；**新增 `auto_completed`（是否系统自动确认，E02）** | 用券/满减后金额可追溯、退款分摊有依据；物流列表/导出需要单号 |
| `order_items` | 新增 `coupon_share DECIMAL(10,2)`、`promotion_share DECIMAL(10,2)`；**新增 `sku_specs_snapshot JSONB`（规格快照，配合 E01）** | 行项目级优惠分摊，退款按行退；规格模型改造后订单展示不依赖 SKU 实时数据 |
| `products` | `sales_count` 冗余刷新策略不变；**新增 `brand_id`、`weight`、`video_url`、`keywords`（E01）** | 品牌/参数/按重量计费/搜索增强 |
| `user_addresses` | **新增 `label`、`used_count`、`last_used_at`（E04）** | 地址标签与常用度排序 |

### 1.5 增强项（E 系列）相关新增表

E01 商品属性与 SPU 体系：

```sql
CREATE TABLE brands (
  id BIGSERIAL PRIMARY KEY,
  name VARCHAR(64) NOT NULL UNIQUE,
  logo VARCHAR(500),
  sort INT DEFAULT 0,
  status SMALLINT DEFAULT 1
);

CREATE TABLE attributes (
  id BIGSERIAL PRIMARY KEY,
  name VARCHAR(50) NOT NULL,          -- 颜色 / 材质 / 功率
  type VARCHAR(10) NOT NULL,          -- spec=规格属性 param=参数属性
  is_filterable BOOLEAN DEFAULT FALSE,
  is_multiple BOOLEAN DEFAULT FALSE,  -- 参数属性是否多值
  sort INT DEFAULT 0
);

CREATE TABLE attribute_values (
  id BIGSERIAL PRIMARY KEY,
  attribute_id BIGINT NOT NULL,
  value VARCHAR(64) NOT NULL,
  sort INT DEFAULT 0,
  UNIQUE (attribute_id, value)
);

CREATE TABLE category_attributes (          -- 分类属性模板
  id BIGSERIAL PRIMARY KEY,
  category_id BIGINT NOT NULL,
  attribute_id BIGINT NOT NULL,
  is_required BOOLEAN DEFAULT FALSE,
  sort INT DEFAULT 0,
  UNIQUE (category_id, attribute_id)
);

CREATE TABLE product_attribute_values (     -- 商品参数值
  id BIGSERIAL PRIMARY KEY,
  product_id BIGINT NOT NULL,
  attribute_id BIGINT NOT NULL,
  value VARCHAR(255) NOT NULL
);
```

E02/E03/E06 相关：

```sql
CREATE TABLE order_logs (                   -- 订单状态流转记录（E02-E）
  id BIGSERIAL PRIMARY KEY,
  order_id BIGINT NOT NULL,
  from_status VARCHAR(32),
  to_status VARCHAR(32) NOT NULL,
  operator_type VARCHAR(10) NOT NULL,       -- user/admin/system
  operator_id BIGINT,
  remark VARCHAR(255)
);

CREATE TABLE express_companies (            -- 快递公司字典（E03）
  id BIGSERIAL PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,         -- 顺丰 SF
  name VARCHAR(50) NOT NULL,
  channel_code VARCHAR(30),                 -- 第三方查询渠道编码
  sort INT DEFAULT 0,
  status SMALLINT DEFAULT 1
);

CREATE TABLE freight_templates (            -- 运费模板（E03-7，可选）
  id BIGSERIAL PRIMARY KEY,
  name VARCHAR(64) NOT NULL,
  mode VARCHAR(20) NOT NULL,                -- fixed/weight/region
  rules JSONB NOT NULL,                     -- [{region, first_weight, first_fee, add_weight, add_fee}]
  status SMALLINT DEFAULT 1
);

CREATE TABLE inventory_checks (             -- 库存盘点单（E06）
  id BIGSERIAL PRIMARY KEY,
  check_no VARCHAR(32) NOT NULL UNIQUE,
  status VARCHAR(20) DEFAULT 'draft',       -- draft/submitted/approved
  operator_id BIGINT,
  remark VARCHAR(255)
);

CREATE TABLE inventory_check_items (
  id BIGSERIAL PRIMARY KEY,
  check_id BIGINT NOT NULL,
  sku_id BIGINT NOT NULL,
  system_stock INT NOT NULL,
  actual_stock INT NOT NULL,
  diff INT NOT NULL,
  reason VARCHAR(255)
);
```

> `attributes` / `attribute_values` / `category_attributes` 三张表替代当前「硬编码维度 key + 纯文本规格」的录入方式，是本版本最大改造项，详见 [Enhancement E01](./CubeShop_V1.1_Enhancement.md)。

---

## 2. 新增接口清单

### 2.1 用户端（/api/storefront，Sanctum 按需）

| 模块 | 方法与路径 | 说明 |
|------|-----------|------|
| 评价 | `POST /orders/{id}/items/{itemId}/review` | 提交评价（限已完成订单） |
| 评价 | `GET /products/{id}/reviews?sort=&rating=` | 商品评价列表（含汇总） |
| 评价 | `GET /me/reviews`、`PUT /reviews/{id}` | 我的评价 / 修改（30 天内 1 次） |
| 通知 | `GET /me/notifications`、`POST /me/notifications/read` | 列表 / 批量已读 |
| 通知 | `GET /me/notifications/unread-count` | 未读数 |
| 收藏 | `POST /products/{id}/favorite`、`DELETE`、`GET /me/favorites` | 收藏 |
| 足迹 | `GET /me/histories`、`POST /products/{id}/track` | 足迹查询/上报 |
| 优惠券 | `GET /coupons`（领券中心）、`POST /coupons/{id}/receive` | 领取（条件更新防超发） |
| 优惠券 | `GET /me/coupons?status=`、`GET /coupons/available?order-context=` | 我的券 / 结算可用券 |
| 物流 | `GET /orders/{id}/shipping` | 物流卡片（单号+轨迹） |
| 装修 | `GET /home-layout` | Banner+公告+楼层聚合（缓存 5min） |
| 积分 | `POST /me/sign-in`、`GET /me/points`、`GET /me/point-logs` | 签到/余额/流水 |
| 搜索 | `GET /search/suggest?q=`、`GET /search/hot` | 联想/热搜（F10） |
| **订单（E02）** | `POST /orders/{id}/confirm`、`POST /orders/{id}/rebuy` | 确认收货（shipped→completed，幂等）/ 再次购买（返回成功与失效行明细） |
| **订单（E02）** | `GET /orders?keyword=&start=&end=&tab=` | 列表扩展：订单号/收货人搜索、时间区间、分组 Tab |
| **账号（E05）** | `GET /me/sessions`、`DELETE /me/sessions/{id}` | 登录设备列表 / 踢出指定设备 |
| **账号（E05）** | `POST /auth/password`（强化） | 旧密码 + 强度校验；成功后撤销其他设备 Token |
| **账号（E05）** | `POST /me/rebind`（手机/邮箱换绑，验证码） | 二/三期 |
| **属性（E01）** | `GET /attributes?category_id=&filterable=1`、`GET /brands` | 前台按属性筛选、品牌列表 |

### 2.2 管理端（/api/admin，权限码控制）

| 模块 | 路径组 | 权限码 |
|------|--------|--------|
| 评价 | `/admin/reviews`（列表/审核/回复/删除） | `review.manage` |
| 报表 | `/admin/reports/*`（trend、top-products、category-share、users、export） | `report.view` |
| 账号 | `/admin/accounts`（CRUD/禁用/重置密码）、`/admin/roles`（CRUD/权限同步） | `account.manage` / `role.manage` |
| 营销 | `/admin/coupons`、`/admin/promotions`（CRUD/启停/核销明细导出） | `marketing.manage` |
| 物流 | `/admin/orders/{id}/ship`（升级）、`/admin/orders/batch-ship` | `shipping.manage` |
| 装修 | `/admin/banners`、`/admin/floors`、`/admin/announcements` | `content.manage` |
| 导入 | `/admin/products/import`（上传）、`/admin/products/import/{id}`（结果查询） | `product.update` |
| **属性/品牌（E01）** | `/admin/brands`、`/admin/attributes`（含 values）、`/admin/categories/{id}/attributes` | `product.update` |
| **订单（E02）** | `/admin/orders/{id}/remark`、`/admin/orders/{id}/logs` | `order.view` |
| **快递字典（E03）** | `/admin/express-companies`、`/admin/freight-templates` | `shipping.manage` |
| **盘点（E06）** | `/admin/inventory-checks`（创建/提交/审核/明细） | `inventory.manage` |
| **导出（E07）** | `/admin/exports`（异步任务提交/状态/下载） | 按业务权限码 |

---

## 3. 服务与任务设计

### 3.1 新增/扩展 Service
| Service | 职责 |
|---------|------|
| `ReviewService` | 提交校验（订单归属+状态+重复）、汇总缓存（商品评分汇总写 Redis，变更失效） |
| `NotificationService` | 统一发送入口：站内信必发 + 邮件按配置走队列（`ShouldQueue`，重试 3 次） |
| `CouponService` | 领取（`UPDATE coupons SET issued_count = issued_count+1 WHERE id=? AND issued_count < total_count` 原子防超发）、用券校验、金额分摊（按行项目金额占比，尾差进最后一行）、退券 |
| `PromotionService` | 满减匹配（结算时计算最优梯度）、与券叠加规则（默认可叠加，金额明细分开记录） |
| `ShippingService` | 发货落库 + 轨迹拉取对接（快递100/快递鸟适配器模式，便于换渠道） |
| `PointService` | 积分增减统一入口（全部经 `point_logs` 流水，余额由流水驱动） |
| `HomeLayoutService` | 首页布局聚合 + 缓存；后台任意内容变更主动 forget |
| `ProductImportService` | Excel 解析、逐行校验、队列导入、失败清单生成 |
| **`ProductAttributeService`（E01）** | 属性库/分类模板维护；SKU 笛卡尔积生成与差异合并（编辑商品时保留已有 SKU 的价格库存，仅增删变化行）；老 specs 数据迁移与校对清单生成 |
| **`OrderCenterService`（E02）** | 确认收货（幂等 + order_logs）、自动确认、再次购买（批量加购 + 失效行明细）、订单分组查询 |
| **`AuthSessionService`（E05）** | 登录设备列表（基于 `personal_access_tokens`）、踢出设备、改密后保留当前 Token 撤销其余、换绑校验 |
| **`InventoryCheckService`（E06）** | 盘点单创建、差异计算、审核后走库存服务调整并写 `inventory_logs` |

### 3.2 事件与监听（复用 V1.0 事件挂接点）
| 事件 | 监听动作 |
|------|----------|
| `OrderPaid` | 通知（买家）+ 报表统计 + （三期）积分入账预约 |
| `OrderShipped` | 通知（买家）+ 轨迹拉取入队 |
| `RefundResult` | 通知（买家）+ （三期）积分回退 |
| `ReviewReplied` | 通知（买家） |
| `ProductSaved / ProductStatusChanged` | （三期）Meilisearch 索引同步 |

### 3.3 Scheduler / Command
| 任务 | 频率 | 说明 |
|------|------|------|
| `orders:auto-cancel` | 每 5 分钟 | V1.0 已有，保留 |
| **`orders:auto-complete`** | **每小时** | **（E02-A）发货后超 `order.auto_complete_days`（默认 7）天自动确认收货，写 order_logs 标记 system** |
| `shipping:pull-traces` | 每 30 分钟 | 拉取在途运单轨迹，更新 trace_status |
| `coupons:expire` | 每小时 | 未使用且过期的 user_coupon 置 expired |
| `meilisearch:sync` | 每日 03:00 | （三期）全量校准商品索引 |
| `exports:clean` | 每日 04:00 | （E07）清理 7 天前的导出文件 |
| 现有 PG 回归自动化 | 每日 09:00 | 保留，覆盖新增用例 |
| 库存预警扫描 | 每日 08:00 | 已有能力的定时化，走通知中心推运营 |

### 3.4 金额分摊规则（F06 关键设计，T-034 评审定稿）
1. 券优惠按行项目「金额 ÷ 订单商品总额」比例分摊，四舍五入尾差记入金额最大行。
2. 满减同理分摊，与券分摊分开记录（`coupon_share` / `promotion_share`）。
3. 退款时按行项目实付（`price*qty - 各项分摊`）计算可退金额；整单取消返还未使用券。
4. 支付回调金额校验（V1.0 已加 bccomp）扩展为「应付 = 商品总额 − 券 − 满减 + 运费」。

**评审定稿细化（T-034 落地口径，代码 `PricingCalculator` / `AmountAllocator`）**
- **叠加顺序：先满减后券**（`STACK_ORDER = ['promotion','coupon']`）。
- 门槛（券 `min_spend`）与满减梯度均按**命中范围原始金额**判定（`scope=all/category/product`）。
- 券优惠额：fixed 直减 `amount`；percent 按 `base*(100-percent)/100` 受 `max_discount` 封顶（percent=80 即 8 折）；
  最终再封顶至「满减后命中行可用余额」。
- **分摊基准由「行原始金额」细化为「行可用余额」**：首个优惠等价于原始金额，第二个优惠改用扣减后余额，
  从而在「券与满减命中同一行」时天然保证行实付 ≥ 0（原「尾差进最后一行」表述统一为**尾差记入金额最大的行**，并列取索引最小）。
- **硬不变量**（`assertInvariants()` 每次计算自检，破坏即抛异常）：Σ 行分摊 = 优惠总额、
  任一行实付 ≥ 0、应付 = 商品总额 − 券 − 满减 + 运费 ≥ 0。
- `orders.amount_details.v = 1`（口径版本号，历史订单据此兼容展示）。

**下单链路与券核销时机（T-035 落地口径，代码 `OrderService` / `PaymentService`）**
- `POST /orders` 新增可选 `user_coupon_id`、`promotion_id`：
  - 不传 `user_coupon_id` → 不用券，**金额路径与 V1.0 完全一致**（`discount_amount=0`、`amount_details` 仅含零优惠快照）；
  - 不传 `promotion_id` → `PromotionService::match()` **自动匹配当前最优满减**（多活动取优惠最大者，**不叠加**）；
  - 显式传 `promotion_id` → `resolveUsable()` 校验启用 + 时间窗口 + 命中范围。
- **参数校验顺序**：先校验券可用性（`CouponService::validateUse`）→ 再锁库存，避免「锁了库存才因券无效回滚」的无谓开销。
- **券核销时机：下单事务内即核销（占用制）**，`UPDATE user_coupons SET status='used', used_at=now() WHERE id=? AND status='unused' AND expire_at>=now()`，
  受影响行数 0 即冲突（**一券一单，天然防并发重复使用**）；同事务 `coupons.used_count + 1` 并回填 `used_order_id`。
  - `used_count` 语义 = 「已被订单占用（含待支付）」；
  - **未支付取消/超时取消** → `OrderService::releaseCoupon()` 原样返还（`status` 回 `unused`，占用期间已过期的置 `expired`），`used_count` 回退（T-036 退款复用同一方法）。
- **支付回调金额校验**（`PaymentService::assertOrderAmountConsistent`）：由 `orders.amount_details` 经
  `PricingCalculator::recomputePayAmount()`（应付 = 商品总额 − 优惠 + 运费，**与落库同一公式**）重算，
  必须同时等于 `orders.pay_amount` 与支付单金额；无 `amount_details` 的 V1.0 历史单退回旧口径（支付单金额 == `pay_amount`）。
  任一不一致 → `Log::warning` 告警 + 抛业务冲突，**拒绝入账**（防篡改订单金额后低价支付）。
- 订单列表/详情响应补充 `discount_amount`、`promotion_discount`、`amount_details` 与行级 `coupon_share`/`promotion_share`/`payable_amount`；
  历史无券订单返回 `0` 与 `null` 快照，前端兼容。

---

## 4. 质量保障要求（沿 V1.0 基线）
- 每个新 Service：Pest Unit 用例（金额分摊、防超发、积分流水必须覆盖边界）。
- 每个新接口组：Pest Feature 用例（含权限码 403、未登录 401、参数 422）。
- 前端：每个新页面/组件至少 1 个 Vitest 用例（渲染 + 关键交互）。
- 优惠券领取/用券：并发压测脚本（复用 P7 压测方案）验证不超发、金额不错乱。
- 数据库迁移必须双库（SQLite/PG）通过；JSONB 字段在 SQLite 测试环境用 JSON 文本兼容（沿用 V1.0 约束兼容做法）。

---

**—— 后端设计支撑结束 ——**
