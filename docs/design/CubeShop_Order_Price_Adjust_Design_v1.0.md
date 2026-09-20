# CubeShop 电商系统
## 客服改价 / 改运费功能详细设计（Order Price Adjust）

**版本**：v1.0  
**日期**：2026-09-18  
**关联文档**：CubeShop_PRD_v2.0、CubeShop_Customer_Service_v2.0、CubeShop_Database_Design_v1.0、CubeShop_schema.sql  
**状态**：设计稿（可直接开发）

---

## 1. 功能定位与目标

### 1.1 业务背景
在商品购买过程中，用户常通过客服沟通希望获得更优惠的价格或运费减免（议价场景）。本功能支持客服在与用户充分沟通后，对**未支付订单**进行商品金额或运费的调整，提升转化率与用户满意度，同时保证金额一致性、库存安全与操作可审计。

### 1.2 设计目标
- 支持客服对未支付订单进行安全、可追溯的改价 / 改运费操作
- 与客户服务中心（工单 / 在线客服）深度打通，操作自动落沟通记录
- 完整记录改前改后金额与操作人，满足财务对账与审计要求
- 用户端实时感知价格变更，降低纠纷风险
- 严格权限控制与操作上限，防止滥用

### 1.3 范围边界

| 在范围内 | 不在范围内（后续迭代） |
|----------|------------------------|
| 未支付订单（status = pending_payment）的商品金额 / 运费调整 | 已支付订单的直接改价 |
| 改价必须关联工单或会话 | 自动议价机器人 |
| 操作日志 + 用户通知 | 复杂多商品逐项改价（当前按订单维度） |
| 权限与单日改价限额 | 已支付订单差额自动退款 |

---

## 2. 核心业务规则

| 编号 | 规则 | 说明 |
|------|------|------|
| R1 | 仅允许改未支付订单 | 订单状态必须为 `pending_payment` |
| R2 | 改价后应付金额 ≥ 0 | 禁止改成负数 |
| R3 | 库存锁定不释放 | 改价不触发库存回滚，防止超卖 |
| R4 | 必须关联沟通记录 | 推荐关联 `cs_ticket` 工单；也可关联在线会话 ID |
| R5 | 全量留痕 | 原金额、新金额、操作人、时间、原因必须完整记录 |
| R6 | 权限控制 | 仅拥有 `order:adjust_price` 权限的客服可操作 |
| R7 | 操作上限（可配置） | 支持单笔最大优惠幅度、单日改价次数 / 金额上限 |
| R8 | 优惠券处理策略 | 默认保留已用优惠券；可选「作废后重算」 |
| R9 | 支付超时建议重置 | 改价成功后可选择重置订单支付超时时间 |
| R10 | 用户确认可选 | 改价后用户端可弹出确认（默认开启，可配置） |

---

## 3. 业务流程

### 3.1 用户侧流程

```
用户下单（生成未支付订单）
    ↓
进入「联系客服」或「我的工单」发起议价
    ↓
客服沟通并确认优惠方案
    ↓
客服后台执行改价 / 改运费
    ↓
系统更新订单金额 + 写入日志 + 通知用户
    ↓
用户端订单详情实时刷新金额
    ↓
（可选）用户确认新价格
    ↓
用户正常支付
```

### 3.2 客服侧流程

1. 客服在工单工作台 / 订单详情 / 在线会话中看到关联未支付订单。
2. 点击「改价 / 改运费」按钮，打开操作面板。
3. 面板展示：
   - 订单基本信息（订单号、用户、创建时间、剩余支付时间）
   - 商品明细（标题、规格、原单价、数量、小计）
   - 金额汇总（商品总金额、运费、优惠金额、应付金额）
4. 客服填写：
   - 新商品总金额（可选）
   - 新运费金额（可选）
   - 是否保留已使用优惠券
   - 改价原因（必填）
   - 内部备注（可选）
5. 系统校验通过后执行改价，并自动在工单沟通记录中插入系统消息。
6. 用户收到站内通知（+ 可选短信 / 推送）。

### 3.3 状态与金额变更示意

```
原订单：
  total_amount     = 240.00   （商品总金额）
  freight_amount   =  15.00
  discount_amount  =  20.00   （优惠券）
  pay_amount       = 235.00

客服改价：商品总金额改为 220.00，运费改为 0.00，保留优惠券

新订单：
  total_amount     = 220.00
  freight_amount   =   0.00
  discount_amount  =  20.00
  pay_amount       = 200.00
```

---

## 4. 数据库设计

### 4.1 表清单

| 表名 | 说明 | 类型 |
|------|------|------|
| `order_price_adjust_log` | 订单改价操作日志（核心） | 新增 |
| `orders` | 订单主表 | 扩展字段 |
| `cs_ticket` | 服务工单 | 扩展字段（可选） |
| `system_configs` | 系统配置 | 复用，增加改价相关配置项 |

### 4.2 新增表：order_price_adjust_log

```sql
CREATE TABLE IF NOT EXISTS order_price_adjust_log (
    id                   BIGSERIAL PRIMARY KEY,
    order_id             BIGINT         NOT NULL REFERENCES orders(id),
    order_no             VARCHAR(32)    NOT NULL,                    -- 冗余，方便查询
    ticket_id            BIGINT,                                      -- 关联客服工单（推荐）
    session_id           VARCHAR(64),                                 -- 在线会话 ID（可选）
    
    operator_id          BIGINT         NOT NULL,                    -- 操作客服 ID
    operator_name        VARCHAR(64)    NOT NULL,                    -- 冗余姓名
    
    -- 改前金额快照
    old_total_amount     DECIMAL(12,2)  NOT NULL,                    -- 原商品总金额
    old_freight_amount   DECIMAL(12,2)  NOT NULL,                    -- 原运费
    old_discount_amount  DECIMAL(12,2)  NOT NULL DEFAULT 0,          -- 原优惠金额
    old_pay_amount       DECIMAL(12,2)  NOT NULL,                    -- 原应付金额
    
    -- 改后金额
    new_total_amount     DECIMAL(12,2)  NOT NULL,
    new_freight_amount   DECIMAL(12,2)  NOT NULL,
    new_discount_amount  DECIMAL(12,2)  NOT NULL DEFAULT 0,
    new_pay_amount       DECIMAL(12,2)  NOT NULL,
    
    -- 优惠券处理
    coupon_kept          BOOLEAN        NOT NULL DEFAULT TRUE,       -- 是否保留原优惠券
    
    reason               VARCHAR(255)   NOT NULL,                    -- 改价原因（用户可见）
    internal_remark      TEXT,                                        -- 内部备注（仅客服可见）
    
    -- 用户确认（可选）
    user_confirmed       BOOLEAN,                                     -- NULL=未开启确认；true/false=已确认/拒绝
    user_confirmed_at    TIMESTAMP,
    
    created_at           TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_order_price_adjust_order   ON order_price_adjust_log (order_id);
CREATE INDEX idx_order_price_adjust_ticket  ON order_price_adjust_log (ticket_id);
CREATE INDEX idx_order_price_adjust_operator ON order_price_adjust_log (operator_id, created_at);
CREATE INDEX idx_order_price_adjust_created ON order_price_adjust_log (created_at);

COMMENT ON TABLE  order_price_adjust_log IS '订单改价/改运费操作日志';
COMMENT ON COLUMN order_price_adjust_log.coupon_kept IS 'true=保留原优惠券；false=作废后重算';
COMMENT ON COLUMN order_price_adjust_log.user_confirmed IS '用户是否确认新价格（可选流程）';
```

### 4.3 订单表扩展（orders）

```sql
-- 建议增加以下字段（兼容现有结构）
ALTER TABLE orders 
    ADD COLUMN IF NOT EXISTS discount_amount       DECIMAL(12,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS last_adjust_at         TIMESTAMP,
    ADD COLUMN IF NOT EXISTS last_adjust_operator_id BIGINT,
    ADD COLUMN IF NOT EXISTS adjust_count           INT NOT NULL DEFAULT 0,          -- 被改价次数
    ADD COLUMN IF NOT EXISTS version                INT NOT NULL DEFAULT 0;          -- 乐观锁版本号

COMMENT ON COLUMN orders.discount_amount IS '优惠总金额（优惠券等）';
COMMENT ON COLUMN orders.last_adjust_at IS '最后一次改价时间';
COMMENT ON COLUMN orders.adjust_count IS '累计被改价次数';
COMMENT ON COLUMN orders.version IS '乐观锁版本，改价时 +1';
```

> 说明：若当前 `total_amount` 已包含优惠后的商品金额，则需在业务层明确「商品原价合计」与「优惠后商品金额」的区分。推荐在后续迭代中增加 `product_amount` 字段以明确语义。

### 4.4 工单表扩展（可选）

```sql
ALTER TABLE cs_ticket 
    ADD COLUMN IF NOT EXISTS has_price_adjust BOOLEAN NOT NULL DEFAULT FALSE;

COMMENT ON COLUMN cs_ticket.has_price_adjust IS '该工单是否产生过改价操作';
```

### 4.5 系统配置项（system_configs 或独立配置）

| config_key | 说明 | 默认值 | 类型 |
|------------|------|--------|------|
| order.adjust_price.enabled | 是否开启改价功能 | true | bool |
| order.adjust_price.require_ticket | 是否强制关联工单 | true | bool |
| order.adjust_price.max_discount_rate | 单笔最大优惠比例（相对原 pay_amount） | 0.5 | decimal |
| order.adjust_price.max_daily_count_per_staff | 单个客服单日最大改价次数 | 50 | int |
| order.adjust_price.max_daily_amount_per_staff | 单个客服单日最大优惠总金额 | 5000 | decimal |
| order.adjust_price.require_user_confirm | 改价后是否需要用户确认 | false | bool |
| order.adjust_price.reset_pay_timeout | 改价后是否重置支付超时时间 | true | bool |
| order.adjust_price.pay_timeout_minutes | 重置后的支付超时分钟数 | 30 | int |

---

## 5. 接口设计

### 5.1 通用约定

- 后台接口统一前缀：`/admin`
- 用户端接口前缀：`/api` 或 `/user`
- 鉴权：后台需登录 + 权限校验；用户端需登录且只能操作自己的订单
- 金额字段统一使用 `string` 或高精度 decimal 序列化，避免浮点精度问题
- 错误码建议：
  - `40001`：订单状态不允许改价
  - `40002`：改价后金额不合法
  - `40003`：超出单笔优惠幅度限制
  - `40004`：超出客服单日改价限额
  - `40005`：无改价权限
  - `40006`：订单已被他人修改（乐观锁失败）
  - `40007`：强制要求关联工单但未提供

---

### 5.2 后台接口

#### 5.2.1 获取订单改价预览信息

```
GET /admin/orders/{orderId}/adjust-preview
```

**权限**：`order:adjust_price` 或 `order:view`

**响应示例**：
```json
{
  "code": 0,
  "data": {
    "orderId": 10086,
    "orderNo": "O2026091812345678",
    "status": "pending_payment",
    "userId": 2001,
    "userNickname": "张三",
    "createdAt": "2026-09-18T14:30:00+08:00",
    "payExpireAt": "2026-09-18T15:00:00+08:00",
    "items": [
      {
        "productTitle": "示例商品 A",
        "skuSpecs": {"颜色": "黑色", "尺寸": "L"},
        "price": "120.00",
        "quantity": 2,
        "totalAmount": "240.00"
      }
    ],
    "amount": {
      "totalAmount": "240.00",
      "freightAmount": "15.00",
      "discountAmount": "20.00",
      "payAmount": "235.00"
    },
    "adjustCount": 0,
    "lastAdjustAt": null,
    "canAdjust": true,
    "cannotReason": null,
    "relatedTickets": [
      {
        "ticketId": 501,
        "ticketNo": "CS202609180001",
        "title": "希望优惠一点",
        "status": "processing"
      }
    ]
  }
}
```

#### 5.2.2 执行改价 / 改运费

```
POST /admin/orders/{orderId}/adjust-price
```

**权限**：`order:adjust_price`

**请求体**：
```json
{
  "newTotalAmount": "220.00",        // 新商品总金额，null 表示不修改
  "newFreightAmount": "0.00",        // 新运费，null 表示不修改
  "keepCoupon": true,                // 是否保留原优惠券
  "reason": "用户沟通后同意减免运费并优惠20元",
  "internalRemark": "老客户，给点优惠",
  "ticketId": 501,                   // 关联工单ID（推荐/强制）
  "sessionId": null,                 // 在线会话ID（可选）
  "resetPayTimeout": true            // 是否重置支付超时（覆盖系统配置）
}
```

**成功响应**：
```json
{
  "code": 0,
  "data": {
    "logId": 9001,
    "orderId": 10086,
    "orderNo": "O2026091812345678",
    "oldPayAmount": "235.00",
    "newPayAmount": "200.00",
    "savedAmount": "35.00",
    "message": "改价成功，已通知用户"
  }
}
```

**失败响应示例**：
```json
{
  "code": 40001,
  "message": "订单状态不允许改价，当前状态：paid"
}
```

#### 5.2.3 查询订单改价历史

```
GET /admin/orders/{orderId}/adjust-logs
```

**权限**：`order:view` 或 `order:adjust_price`

**响应**：
```json
{
  "code": 0,
  "data": {
    "list": [
      {
        "id": 9001,
        "operatorId": 10,
        "operatorName": "客服小王",
        "oldPayAmount": "235.00",
        "newPayAmount": "200.00",
        "reason": "用户沟通后同意减免运费并优惠20元",
        "ticketId": 501,
        "createdAt": "2026-09-18T14:45:00+08:00"
      }
    ],
    "total": 1
  }
}
```

#### 5.2.4 客服改价统计（数据看板用）

```
GET /admin/cs/adjust-stats
```

**查询参数**：
- `startDate` / `endDate`
- `operatorId`（可选）

**响应**：
```json
{
  "code": 0,
  "data": {
    "totalCount": 128,
    "totalSavedAmount": "15680.00",
    "avgSavedAmount": "122.50",
    "byOperator": [
      {
        "operatorId": 10,
        "operatorName": "客服小王",
        "count": 45,
        "savedAmount": "5200.00"
      }
    ]
  }
}
```

---

### 5.3 用户端接口

#### 5.3.1 获取订单详情（含最新改价信息）

现有订单详情接口扩展返回字段：

```json
{
  "orderId": 10086,
  "orderNo": "O2026091812345678",
  "status": "pending_payment",
  "amount": {
    "totalAmount": "220.00",
    "freightAmount": "0.00",
    "discountAmount": "20.00",
    "payAmount": "200.00"
  },
  "priceAdjusted": true,
  "lastAdjust": {
    "oldPayAmount": "235.00",
    "newPayAmount": "200.00",
    "reason": "用户沟通后同意减免运费并优惠20元",
    "adjustedAt": "2026-09-18T14:45:00+08:00",
    "needConfirm": false,
    "userConfirmed": null
  }
}
```

#### 5.3.2 用户确认改价（可选流程）

```
POST /api/orders/{orderId}/confirm-price-adjust
```

**请求体**：
```json
{
  "logId": 9001,
  "confirmed": true          // true=同意；false=拒绝
}
```

**说明**：
- 仅当系统配置 `order.adjust_price.require_user_confirm = true` 时启用。
- 用户拒绝后，订单金额回滚到改价前，并记录拒绝日志。

#### 5.3.3 用户查询自己订单的改价记录（可选）

```
GET /api/orders/{orderId}/adjust-logs
```

仅返回当前用户自己的订单，且只展示 `reason` 与金额变化，不暴露内部备注。

---

### 5.4 与客服工单的联动接口

改价成功后，系统应自动调用内部方法向工单插入一条系统消息：

```
POST /admin/cs/tickets/{ticketId}/messages   （内部调用）
```

消息内容示例：
```text
【系统消息】客服已将订单 O2026091812345678 的应付金额从 ¥235.00 调整为 ¥200.00。
改价原因：用户沟通后同意减免运费并优惠20元
```

对应 `cs_ticket_message`：
- `sender_type` = `system`
- `is_internal` = false
- `content` = 上述文本

同时更新 `cs_ticket.has_price_adjust = true`。

---

## 6. 核心业务逻辑伪代码

```text
function adjustOrderPrice(orderId, req, operator):
    // 1. 权限校验
    if not operator.hasPermission("order:adjust_price"):
        throw 40005

    // 2. 加载订单（加乐观锁）
    order = loadOrderForUpdate(orderId)   // SELECT ... FOR UPDATE 或 version 校验
    if order.status != "pending_payment":
        throw 40001

    // 3. 限额校验
    checkDailyLimit(operator.id)
    checkMaxDiscountRate(order.pay_amount, calculateNewPayAmount(req))

    // 4. 计算新金额
    newTotal = req.newTotalAmount ?? order.total_amount
    newFreight = req.newFreightAmount ?? order.freight_amount
    newDiscount = order.discount_amount
    if not req.keepCoupon:
        newDiscount = 0          // 或重新计算可用优惠
    newPay = newTotal + newFreight - newDiscount
    if newPay < 0:
        throw 40002

    // 5. 写日志
    log = insertAdjustLog(...)

    // 6. 更新订单
    order.total_amount = newTotal
    order.freight_amount = newFreight
    order.discount_amount = newDiscount
    order.pay_amount = newPay
    order.last_adjust_at = now()
    order.last_adjust_operator_id = operator.id
    order.adjust_count += 1
    order.version += 1
    if req.resetPayTimeout:
        order.pay_expire_at = now() + config.pay_timeout_minutes
    save(order)

    // 7. 联动工单
    if req.ticketId:
        insertSystemMessageToTicket(req.ticketId, log)
        markTicketHasAdjust(req.ticketId)

    // 8. 通知用户
    notifyUser(order.user_id, log)

    return log
```

---

## 7. 权限设计

| 权限码 | 名称 | 说明 |
|--------|------|------|
| `order:adjust_price` | 订单改价 | 执行改价 / 改运费 |
| `order:view_adjust_log` | 查看改价日志 | 查看任意订单的改价历史 |
| `cs:view_adjust_stats` | 改价数据统计 | 查看客服改价统计看板 |

建议角色默认权限：
- 普通客服：默认无 `order:adjust_price`，由主管单独开启
- 客服主管：拥有全部改价相关权限
- 超级管理员：全部权限

---

## 8. 前端交互要点

### 8.1 客服后台

- **入口位置**：
  1. 订单详情页醒目按钮「改价 / 改运费」
  2. 工单详情页关联订单卡片上的快捷按钮
  3. 在线客服会话侧边栏订单信息区
- **操作弹窗**：
  - 清晰展示「改前 → 改后」金额对比
  - 红字提示优惠幅度
  - 必填原因输入框
  - 关联工单下拉（自动带出当前工单）
- **改价成功后**：
  - 自动刷新订单金额
  - 在工单消息流插入系统消息
  - Toast 提示成功

### 8.2 用户端

- 订单详情页若发生过改价，显示醒目提示条：
  > 客服已为您调整价格，应付金额由 ¥235.00 变为 ¥200.00
- 支付按钮金额实时同步
- 若开启用户确认，则弹出确认对话框，确认前不可支付

---

## 9. 异常与边界处理

| 场景 | 处理方式 |
|------|----------|
| 订单已支付 / 已取消 | 拒绝改价，返回明确错误码 |
| 并发两个客服同时改价 | 乐观锁（version）或悲观锁，后提交者失败 |
| 改价过程中用户完成支付 | 以支付成功为准，改价失败并提示 |
| 改价后用户超时未支付 | 按正常超时关闭订单逻辑处理 |
| 优惠券已使用且 keepCoupon=false | 作废优惠券使用记录，优惠金额归零 |
| 客服达到单日限额 | 拒绝并提示「今日改价次数/金额已达上限」 |
| 强制关联工单但未传 ticketId | 拒绝改价 |

---

## 10. 开发优先级与工作量估算

| 优先级 | 内容 | 预估工作量 |
|--------|------|------------|
| P0 | 表结构 + 改价核心接口 + 权限 + 操作日志 | 3 人日 |
| P0 | 客服后台改价弹窗 + 订单详情展示 | 2 人日 |
| P1 | 与工单消息联动 + 用户端提示 | 1.5 人日 |
| P1 | 单日限额与优惠幅度校验 | 0.5 人日 |
| P2 | 用户确认改价流程 | 1 人日 |
| P2 | 改价数据统计看板 | 1 人日 |
| P2 | 已支付订单差额退款能力 | 3~4 人日 |

**建议首期交付**：P0 + P1，即可满足核心议价场景。

---

## 11. 测试要点

1. 正常改价成功路径（只改商品金额 / 只改运费 / 两者都改）
2. 订单状态校验（已支付、已取消、已发货均不可改）
3. 金额边界（改成 0、改成负数、优惠幅度超限）
4. 并发改价（两个客服同时操作同一订单）
5. 权限拦截
6. 工单消息是否正确落库
7. 用户端金额是否实时刷新
8. 改价后支付流程是否正常
9. 单日限额是否生效
10. 操作日志完整性与可追溯性

---

## 12. 后续扩展方向

1. **已支付订单差额退款**：改价后生成部分退款单，原路退回差价。
2. **按商品明细改价**：支持对订单中单个商品单独调整单价。
3. **改价审批流**：大额优惠需主管审批后再生效。
4. **智能议价辅助**：根据用户历史订单、会员等级给出建议优惠幅度。
5. **与在线客服（IM）深度集成**：会话中一键改价并实时推送。

---

**文档结束**

如需将本设计合并进 `CubeShop_Customer_Service_v2.0.md` 或生成对应的建表 SQL 文件，请告知。
