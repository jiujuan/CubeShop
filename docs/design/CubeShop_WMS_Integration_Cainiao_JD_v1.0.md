# CubeShop / SaaS 电商平台
## WMS 对接设计文档（菜鸟 + 京东云仓）

**版本**：v1.1  
**日期**：2026-09-19  
**状态**：设计稿（已补充字段映射、后台原型、退货入库）  
**关联文档**：SaaS_CrossBorder_Architecture.md、TiShop_Architecture.md、CubeShop_Architecture_v1.0.md  
**适用范围**：CubeShop、TiShop、SaaS 跨境电商多租户平台

---

## 1. 文档目标与范围

### 1.1 目标
- 支持与**菜鸟**（奇门/仓配）、**京东云仓（ECLP）**进行标准化对接
- 后台 Admin 可按租户/仓库灵活配置对接参数、SKU 映射、推送开关
- 实现「支付成功 → 创建发货单 → 推送出库 → 回传运单 → 更新订单」全链路闭环
- **本期同步支持退货入库**（售后审核通过 → 创建退货入库单 → WMS 确认收货 → 恢复库存）
- 预留后续扩展其他 WMS 的 Adapter 能力

### 1.2 本期范围（v1.0 / v1.1）
| 能力 | 菜鸟 | 京东云仓 | 说明 |
|------|------|----------|------|
| 出库单创建/推送 | ✓ | ✓ | 销售出库 |
| 出库单取消 | ✓ | ✓ | 未出库前可取消 |
| 发货结果回传（运单号） | ✓ | ✓ | Webhook / 回调 |
| 出库状态变更回传 | ✓ | ✓ | 拣货中、已打包、异常等 |
| 库存查询 / 增量同步 | ✓ | ✓ | 准实时 |
| **退货入库单创建** | ✓ | ✓ | **本期纳入** |
| **退货入库结果回传** | ✓ | ✓ | **本期纳入** |
| 多仓路由 | 基础支持 | 基础支持 | 按仓库配置选择 |
| 后台配置界面 | ✓ | ✓ | 租户级 + 仓库级 + 页面原型 |

### 1.3 非目标（本期不做）
- 深度库内作业可视化（波次、拣货路径等）
- 复杂库内调拨、盘点回写
- 海外仓特殊清关字段完整对接
- 自动面单打印（由 WMS 侧完成）

---

## 2. 整体架构设计

### 2.1 逻辑架构

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        电商平台（OMS / 履约中心）                         │
│  ┌──────────────┐  ┌──────────────┐  ┌────────────────────────────────┐ │
│  │  订单服务     │  │  库存服务     │  │  履约服务（Fulfillment）        │ │
│  │  Order       │  │  Inventory   │  │  发货单 + 退货入库单 状态机     │ │
│  └──────┬───────┘  └──────┬───────┘  └───────────────┬────────────────┘ │
│         │                 │                          │                   │
│         └─────────────────┼──────────────────────────┘                   │
│                           ▼                                              │
│              ┌────────────────────────────┐                              │
│              │     WMS Adapter 层         │                              │
│              │  ┌──────────┐ ┌──────────┐ │                              │
│              │  │ Cainiao  │ │ JD Cloud │ │  ← 可扩展更多实现             │
│              │  │ Adapter  │ │ Adapter  │ │                              │
│              │  └──────────┘ └──────────┘ │                              │
│              └─────────────┬──────────────┘                              │
└────────────────────────────┼─────────────────────────────────────────────┘
                             │ HTTPS + 签名 / 消息队列
                             ▼
        ┌────────────────────┴────────────────────┐
        │                                           │
┌───────▼────────┐                        ┌─────────▼────────┐
│   菜鸟开放平台  │                        │  京东云仓/ECLP    │
│  （奇门仓配）   │                        │  （宙斯开放平台） │
└────────────────┘                        └──────────────────┘
```

### 2.2 核心设计原则
1. **履约单中心化**：平台以「发货单（Fulfillment Order）」和「退货入库单（Return Inbound Order）」为推送单元。
2. **Adapter 模式**：统一内部标准接口，菜鸟/京东各自实现转换、签名、重试。
3. **租户 + 仓库双维度配置**：同一租户可绑定多个仓库，每个仓库独立选择 WMS 供应商与凭证。
4. **异步优先 + 幂等**：推送走消息队列，所有写接口带 `request_id`。
5. **状态机驱动**：发货单 / 退货入库单状态变更可追溯、可对账。

### 2.3 发货单状态机

```
Created
  → PendingPush          （待推送）
  → Pushing              （推送中）
  → Pushed               （已推送成功）
  → Picking              （WMS 拣货中）
  → Packed               （已打包）
  → Shipped              （已发货，有运单号）
  → Completed            （完成）
  → Cancelled            （已取消）
  → Exception            （异常：缺货/拦截/失败）
  → PushFailed           （推送失败，可重试）
```

### 2.4 退货入库单状态机

```
Created
  → PendingPush          （待推送）
  → Pushed               （已推送到 WMS）
  → Receiving            （WMS 收货中）
  → Received             （已收货确认）
  → Completed            （完成，库存已恢复）
  → Cancelled            （已取消）
  → Exception            （异常）
  → PushFailed           （推送失败）
```

---

## 3. 后台 Admin 配置与页面原型

### 3.1 配置入口
- 商家后台：`设置 → 仓库与物流 → WMS 对接`
- 或：`仓库管理 → 编辑仓库 → WMS 对接配置`

支持按**仓库**维度配置（一个仓库绑定一个 WMS 实例）。

### 3.2 配置项清单

#### 3.2.1 通用配置

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| warehouse_id | bigint | 是 | 关联平台仓库 |
| provider | enum | 是 | `cainiao` / `jd_cloud` |
| enabled | bool | 是 | 是否启用推送 |
| auto_push | bool | 是 | 支付成功后是否自动推送出库 |
| auto_push_return | bool | 是 | 售后审核通过后是否自动推送退货入库 |
| push_retry_times | int | 否 | 推送失败最大重试次数，默认 5 |
| sku_mapping_mode | enum | 是 | `same`（SKU 一致）/ `manual`（手动映射） |
| remark | string | 否 | 备注 |

#### 3.2.2 菜鸟专用配置

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| app_key | string | 是 | 菜鸟/奇门 AppKey |
| app_secret | string（加密） | 是 | AppSecret |
| customer_id / owner_code | string | 是 | 货主编码 |
| warehouse_code | string | 是 | 菜鸟侧仓库编码 |
| api_env | enum | 是 | `prod` / `sandbox` |
| callback_url | string | 是 | 平台回调地址（系统自动生成，只读） |
| extra_config | jsonb | 否 | 扩展字段 |

#### 3.2.3 京东云仓专用配置

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| app_key | string | 是 | 宙斯 AppKey |
| app_secret | string（加密） | 是 | AppSecret |
| access_token | string（加密） | 条件 | OAuth Token |
| owner_no | string | 是 | 货主编码 |
| warehouse_no | string | 是 | 京东云仓仓库编码 |
| api_env | enum | 是 | `prod` / `sandbox` |
| callback_url | string | 是 | 平台回调地址（系统自动生成） |
| extra_config | jsonb | 否 | 扩展配置 |

### 3.3 后台页面原型（文字线框）

#### 页面 1：WMS 对接配置列表

```
┌──────────────────────────────────────────────────────────────────────────┐
│  仓库与物流 / WMS 对接                                      [+ 新增配置]  │
├──────────────────────────────────────────────────────────────────────────┤
│  仓库名称          │ 供应商     │ 状态   │ 自动出库 │ 自动退货 │ 操作     │
│  上海仓-WH_SH_01   │ 菜鸟       │ 启用   │ 是       │ 是       │ 编辑 测试│
│  北京仓-WH_BJ_01   │ 京东云仓   │ 启用   │ 是       │ 否       │ 编辑 测试│
│  广州仓-WH_GZ_01   │ 未配置     │ -      │ -        │ -        │ 配置     │
└──────────────────────────────────────────────────────────────────────────┘
```

#### 页面 2：编辑 WMS 配置（菜鸟示例）

```
┌──────────────────────────────────────────────────────────────────────────┐
│  编辑 WMS 配置 - 上海仓（WH_SH_01）                          [保存] [取消]│
├──────────────────────────────────────────────────────────────────────────┤
│  【基础信息】                                                            │
│  供应商*          ○ 菜鸟   ● 京东云仓                                    │
│  启用对接         [✓]                                                    │
│  支付成功自动推送出库  [✓]                                               │
│  售后审核自动推送退货入库 [✓]                                            │
│  推送失败重试次数  [5]                                                   │
│  SKU 映射方式     ○ 与平台一致   ● 手动映射                              │
│                                                                          │
│  【菜鸟凭证】                                                            │
│  AppKey*          [________________________]                             │
│  AppSecret*       [********]  [修改]                                     │
│  货主编码*        [CUSTOMER_001]                                         │
│  仓库编码*        [CN_WH_SH_01]                                          │
│  环境             ○ 沙箱   ● 生产                                        │
│                                                                          │
│  【回调地址】（只读，请复制到菜鸟后台配置）                               │
│  https://api.yourdomain.com/api/v1/wms/callback/cainiao?tenant=T1001     │
│                                                                          │
│  【备注】         [________________________]                             │
│                                                                          │
│  [测试连通性]                                                            │
└──────────────────────────────────────────────────────────────────────────┘
```

#### 页面 3：SKU 映射管理

```
┌──────────────────────────────────────────────────────────────────────────┐
│  SKU 映射 - 上海仓（菜鸟）                    [批量导入] [导出] [+ 新增] │
├──────────────────────────────────────────────────────────────────────────┤
│  平台SKU编码    │ 商品名称       │ WMS货品编码   │ 条码         │ 状态 │
│  SKU-001        │ 示例T恤-红色   │ CN-SKU-001    │ 690123...    │ 启用 │
│  SKU-002        │ 示例T恤-蓝色   │ CN-SKU-002    │ 690123...    │ 启用 │
└──────────────────────────────────────────────────────────────────────────┘
```

#### 页面 4：发货单 / 退货入库单列表（运营侧）

```
┌──────────────────────────────────────────────────────────────────────────┐
│  履约中心 / 发货单                              筛选：状态 ▼ 仓库 ▼      │
├──────────────────────────────────────────────────────────────────────────┤
│  发货单号        │ 订单号      │ 仓库   │ 状态     │ 运单号    │ 操作   │
│  FO202609190001  │ O2026...    │ 上海仓 │ 已发货   │ SF123...  │ 详情   │
│  FO202609190002  │ O2026...    │ 北京仓 │ 推送失败 │ -         │ 重推 取消│
└──────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────────┐
│  履约中心 / 退货入库单                                                   │
├──────────────────────────────────────────────────────────────────────────┤
│  退货单号        │ 售后单号    │ 仓库   │ 状态     │ 操作               │
│  RI202609190001  │ RMA2026...  │ 上海仓 │ 已收货   │ 详情               │
│  RI202609190002  │ RMA2026...  │ 北京仓 │ 待推送   │ 推送 取消          │
└──────────────────────────────────────────────────────────────────────────┘
```

### 3.4 交互要点
1. 保存前可「测试连通性」（调用库存查询或健康检查接口）。
2. 敏感字段脱敏，仅支持覆盖更新。
3. 回调地址系统自动生成并展示，提示用户去 WMS 后台配置。
4. 操作日志记录配置变更。
5. 支持临时关闭 auto_push / auto_push_return。

---

## 4. 数据模型设计

### 4.1 核心表

```sql
-- WMS 对接配置表（按仓库）
CREATE TABLE wms_config (
    id              BIGSERIAL PRIMARY KEY,
    tenant_id       BIGINT NOT NULL,
    warehouse_id    BIGINT NOT NULL,
    provider        VARCHAR(32) NOT NULL,          -- cainiao / jd_cloud
    enabled         BOOLEAN NOT NULL DEFAULT false,
    auto_push       BOOLEAN NOT NULL DEFAULT true,
    auto_push_return BOOLEAN NOT NULL DEFAULT true,
    push_retry_times INT NOT NULL DEFAULT 5,
    sku_mapping_mode VARCHAR(16) NOT NULL DEFAULT 'same',
    
    app_key         VARCHAR(128),
    app_secret_enc  TEXT,
    access_token_enc TEXT,
    customer_id     VARCHAR(64),                  -- 菜鸟货主
    owner_no        VARCHAR(64),                  -- 京东货主
    warehouse_code  VARCHAR(64),                  -- 菜鸟仓库编码
    warehouse_no    VARCHAR(64),                  -- 京东仓库编码
    api_env         VARCHAR(16) NOT NULL DEFAULT 'prod',
    
    extra_config    JSONB DEFAULT '{}',
    remark          VARCHAR(255),
    
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by      BIGINT,
    updated_by      BIGINT,
    
    UNIQUE (tenant_id, warehouse_id)
);

-- SKU 映射表
CREATE TABLE wms_sku_mapping (
    id              BIGSERIAL PRIMARY KEY,
    tenant_id       BIGINT NOT NULL,
    warehouse_id    BIGINT NOT NULL,
    sku_id          BIGINT NOT NULL,
    platform_sku_code VARCHAR(64) NOT NULL,
    wms_sku_code    VARCHAR(64) NOT NULL,
    barcode         VARCHAR(64),
    status          SMALLINT NOT NULL DEFAULT 1,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    UNIQUE (tenant_id, warehouse_id, sku_id)
);

-- 发货单主表
CREATE TABLE fulfillment_order (
    id                  BIGSERIAL PRIMARY KEY,
    tenant_id           BIGINT NOT NULL,
    order_id            BIGINT NOT NULL,
    order_no            VARCHAR(64) NOT NULL,
    outbound_no         VARCHAR(64) NOT NULL,
    warehouse_id        BIGINT NOT NULL,
    provider            VARCHAR(32),
    status              VARCHAR(32) NOT NULL,
    wms_outbound_no     VARCHAR(64),
    tracking_no         VARCHAR(64),
    carrier_code        VARCHAR(32),
    carrier_name        VARCHAR(64),
    
    push_request_id     VARCHAR(64),
    push_times          INT NOT NULL DEFAULT 0,
    last_push_at        TIMESTAMPTZ,
    last_push_error     TEXT,
    
    shipped_at          TIMESTAMPTZ,
    cancelled_at        TIMESTAMPTZ,
    exception_reason    VARCHAR(255),
    
    buyer_info          JSONB,
    shipping_info       JSONB,
    extend              JSONB DEFAULT '{}',
    
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    UNIQUE (tenant_id, outbound_no)
);

CREATE TABLE fulfillment_order_item (
    id                  BIGSERIAL PRIMARY KEY,
    fulfillment_order_id BIGINT NOT NULL REFERENCES fulfillment_order(id),
    tenant_id           BIGINT NOT NULL,
    sku_id              BIGINT NOT NULL,
    platform_sku_code   VARCHAR(64) NOT NULL,
    wms_sku_code        VARCHAR(64) NOT NULL,
    product_name        VARCHAR(255),
    qty                 INT NOT NULL,
    shipped_qty         INT NOT NULL DEFAULT 0,
    barcode             VARCHAR(64),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 退货入库单主表（本期新增）
CREATE TABLE return_inbound_order (
    id                  BIGSERIAL PRIMARY KEY,
    tenant_id           BIGINT NOT NULL,
    after_sale_id       BIGINT NOT NULL,           -- 关联售后单
    after_sale_no       VARCHAR(64) NOT NULL,
    order_id            BIGINT,
    order_no            VARCHAR(64),
    inbound_no          VARCHAR(64) NOT NULL,       -- 平台退货入库单号
    warehouse_id        BIGINT NOT NULL,
    provider            VARCHAR(32),
    status              VARCHAR(32) NOT NULL,
    wms_inbound_no      VARCHAR(64),               -- WMS 侧入库单号
    
    push_request_id     VARCHAR(64),
    push_times          INT NOT NULL DEFAULT 0,
    last_push_at        TIMESTAMPTZ,
    last_push_error     TEXT,
    
    received_at         TIMESTAMPTZ,
    cancelled_at        TIMESTAMPTZ,
    exception_reason    VARCHAR(255),
    
    return_reason       VARCHAR(255),
    buyer_info          JSONB,                     -- 退回人/地址快照（可选）
    extend              JSONB DEFAULT '{}',
    
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    UNIQUE (tenant_id, inbound_no)
);

CREATE TABLE return_inbound_order_item (
    id                  BIGSERIAL PRIMARY KEY,
    return_inbound_order_id BIGINT NOT NULL REFERENCES return_inbound_order(id),
    tenant_id           BIGINT NOT NULL,
    sku_id              BIGINT NOT NULL,
    platform_sku_code   VARCHAR(64) NOT NULL,
    wms_sku_code        VARCHAR(64) NOT NULL,
    product_name        VARCHAR(255),
    qty                 INT NOT NULL,               -- 应退数量
    received_qty        INT NOT NULL DEFAULT 0,     -- 实收数量
    inventory_type      VARCHAR(16) DEFAULT 'ZP',  -- ZP正品 / CC残次
    barcode             VARCHAR(64),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 推送/回传日志
CREATE TABLE wms_api_log (
    id              BIGSERIAL PRIMARY KEY,
    tenant_id       BIGINT NOT NULL,
    direction       VARCHAR(16) NOT NULL,          -- outbound / inbound
    provider        VARCHAR(32) NOT NULL,
    api_name        VARCHAR(64) NOT NULL,
    request_id      VARCHAR(64),
    biz_no          VARCHAR(64),                   -- outbound_no 或 inbound_no
    request_body    JSONB,
    response_body   JSONB,
    http_status     INT,
    success         BOOLEAN,
    error_msg       TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

---

## 5. 对接流程详解

### 5.1 出库主流程
1. 订单支付成功 → 创建 fulfillment_order（Created）
2. 若仓库 WMS 配置 enabled + auto_push → PendingPush → 消息队列
3. Adapter 调用 WMS 创建出库接口 → Pushed / PushFailed
4. WMS 回调发货完成 → 更新运单号 → Shipped → 通知买家
5. 最终 Completed

### 5.2 取消出库
- 状态 ∈ {PendingPush, Pushing, Pushed, Picking} 时可调用 WMS 取消接口
- 成功 → Cancelled

### 5.3 退货入库流程（本期）
```
1. 售后单审核通过（同意退货）
   ↓
2. 履约服务创建 return_inbound_order（状态 = Created）
   - 关联售后单、原订单、仓库、退货商品明细
   ↓
3. 若 auto_push_return = true → PendingPush → 消息队列
   ↓
4. Adapter 调用 WMS「创建退货入库单」接口
   - 成功 → Pushed，记录 wms_inbound_no
   ↓
5. 买家寄回商品，WMS 收货并质检
   ↓
6. WMS 回调「入库完成」
   - 回传实收数量、正品/残次
   - 状态 = Received
   ↓
7. 平台根据实收数量恢复可用库存（正品）或入残次仓
   - 状态 = Completed
   - 更新售后单状态
```

### 5.4 库存同步
- 增量：WMS 推送库存变更
- 主动拉取：定时对账校准

---

## 6. Adapter 标准内部接口

```go
type WmsAdapter interface {
    CreateOutbound(ctx context.Context, req *CreateOutboundReq) (*CreateOutboundResp, error)
    CancelOutbound(ctx context.Context, req *CancelOutboundReq) error
    CreateReturnInbound(ctx context.Context, req *CreateReturnInboundReq) (*CreateReturnInboundResp, error)
    CancelReturnInbound(ctx context.Context, req *CancelReturnInboundReq) error
    QueryInventory(ctx context.Context, req *QueryInventoryReq) (*QueryInventoryResp, error)
}
```

### 6.1 标准出库请求（平台内部模型）

```json
{
  "request_id": "uuid",
  "tenant_id": 1001,
  "warehouse_id": 2001,
  "outbound_no": "FO202609190001",
  "order_no": "O202609190001",
  "order_type": "SALE",
  "buyer": {
    "name": "张三",
    "mobile": "13800138000",
    "province": "浙江省",
    "city": "杭州市",
    "district": "余杭区",
    "detail_address": "xxx路xxx号",
    "postcode": "311100"
  },
  "items": [
    {
      "sku_id": 3001,
      "platform_sku_code": "SKU-001",
      "wms_sku_code": "CN-SKU-001",
      "product_name": "示例商品",
      "qty": 2,
      "barcode": "6901234567890"
    }
  ],
  "shipping": {
    "carrier_code": "",
    "remark": "请轻拿轻放"
  },
  "extend": {}
}
```

### 6.2 标准退货入库请求

```json
{
  "request_id": "uuid",
  "tenant_id": 1001,
  "warehouse_id": 2001,
  "inbound_no": "RI202609190001",
  "after_sale_no": "RMA202609190001",
  "order_no": "O202609190001",
  "return_reason": "七天无理由退货",
  "items": [
    {
      "sku_id": 3001,
      "platform_sku_code": "SKU-001",
      "wms_sku_code": "CN-SKU-001",
      "product_name": "示例商品",
      "qty": 1,
      "barcode": "6901234567890"
    }
  ],
  "extend": {}
}
```

---

## 7. 菜鸟具体接口字段映射

> 说明：菜鸟仓配场景主流通过**奇门（Qimen）**协议对接。以下字段以奇门 `deliveryorder.create` / `returnorder.create` / `deliveryorder.confirm` 等常见结构为准。实际联调时请以当前开放平台最新文档为准，Adapter 内做版本适配。

### 7.1 创建出库单（deliveryorder.create）

| 平台标准字段 | 菜鸟/奇门字段 | 类型 | 必填 | 说明 |
|--------------|---------------|------|------|------|
| outbound_no | deliveryOrderCode | string(50) | 是 | 平台发货单号 |
| order_no | orderCode / sourceOrderCode | string(50) | 是 | 交易订单号 |
| order_type | orderType | string | 是 | JYCK=一般交易出库 |
| warehouse_code（配置） | warehouseCode | string(50) | 是 | 仓库编码 |
| customer_id（配置） | ownerCode | string(50) | 是 | 货主编码 |
| buyer.name | receiverInfo.name | string | 是 | 收件人姓名 |
| buyer.mobile | receiverInfo.mobile | string | 是 | 手机 |
| buyer.province | receiverInfo.province | string | 是 | 省 |
| buyer.city | receiverInfo.city | string | 是 | 市 |
| buyer.district | receiverInfo.area | string | 是 | 区 |
| buyer.detail_address | receiverInfo.detailAddress | string | 是 | 详细地址 |
| buyer.postcode | receiverInfo.zipCode | string | 否 | 邮编 |
| shipping.remark | remark | string | 否 | 备注 |
| items[].wms_sku_code | orderLines.itemCode | string | 是 | 货品编码 |
| items[].product_name | orderLines.itemName | string | 否 | 商品名称 |
| items[].qty | orderLines.planQty | int | 是 | 应发数量 |
| items[].barcode | orderLines.barCode | string | 否 | 条码 |
| - | orderLines.ownerCode | string | 是 | 货主（同主单） |
| - | orderLines.orderLineNo | string | 是 | 行号（平台生成） |
| - | sourcePlatformCode | string | 否 | 建议填 OTHER 或自有平台编码 |

**响应关键字段**：
- `deliveryOrderId` → 平台记录为 `wms_outbound_no`
- `flag` / `code` / `message`

### 7.2 出库确认/发货回传（deliveryorder.confirm，WMS → 平台）

| 菜鸟回传字段 | 平台落库字段 | 说明 |
|--------------|--------------|------|
| deliveryOrderCode | outbound_no | 匹配发货单 |
| deliveryOrderId | wms_outbound_no | |
| logisticsCode | carrier_code | 物流公司编码 |
| logisticsName | carrier_name | |
| expressCode | tracking_no | 运单号 |
| orderLines[].actualQty | shipped_qty | 实发数量 |
| orderConfirmTime | shipped_at | 发货时间 |

支持一单多包裹时，`packages` 数组解析后全部写入。

### 7.3 取消出库单

| 平台字段 | 菜鸟字段 | 说明 |
|----------|----------|------|
| outbound_no | deliveryOrderCode | |
| wms_outbound_no | deliveryOrderId | 条件必填 |
| warehouse_code | warehouseCode | |
| owner_code | ownerCode | |

### 7.4 创建退货入库单（returnorder.create）

| 平台标准字段 | 菜鸟/奇门字段 | 类型 | 必填 | 说明 |
|--------------|---------------|------|------|------|
| inbound_no | returnOrderCode | string(50) | 是 | 平台退货入库单号 |
| after_sale_no / order_no | preDeliveryOrderCode / orderCode | string | 否 | 原出库/订单号 |
| warehouse_code | warehouseCode | string | 是 | |
| owner_code | ownerCode | string | 是 | |
| return_reason | returnReason | string | 否 | |
| items[].wms_sku_code | orderLines.itemCode | string | 是 | |
| items[].qty | orderLines.planQty | int | 是 | 应退数量 |
| items[].barcode | orderLines.barCode | string | 否 | |

**响应**：`returnOrderId` → `wms_inbound_no`

### 7.5 退货入库确认回传（returnorder.confirm）

| 菜鸟回传字段 | 平台落库 | 说明 |
|--------------|----------|------|
| returnOrderCode | inbound_no | |
| returnOrderId | wms_inbound_no | |
| orderLines[].actualQty | received_qty | 实收 |
| orderLines[].inventoryType | inventory_type | ZP/CC 等 |
| orderConfirmTime | received_at | |

### 7.6 库存查询（inventory.query）

| 平台字段 | 菜鸟字段 | 说明 |
|----------|----------|------|
| warehouse_code | warehouseCode | |
| owner_code | ownerCode | |
| wms_sku_codes | itemCodes | 批量 |

---

## 8. 京东云仓（ECLP）具体接口字段映射

> 说明：以京东宙斯开放平台 ECLP 相关接口为参考（如销售出库单创建、取消、查询，退货入库相关接口）。实际接口名与字段以最新 JOS 文档为准。

### 8.1 创建销售出库单

| 平台标准字段 | 京东 ECLP 常见字段 | 类型 | 必填 | 说明 |
|--------------|---------------------|------|------|------|
| outbound_no | isvUUID / isvSoNo | string | 是 | 商家出库单号（幂等） |
| order_no | spSoNo / salesPlatformOrderNo | string | 是 | 销售平台订单号 |
| owner_no（配置） | ownerNo | string | 是 | 货主 |
| warehouse_no（配置） | warehouseNo | string | 是 | 库房编号 |
| buyer.name | consigneeName | string | 是 | 收件人 |
| buyer.mobile | consigneeMobile | string | 是 | 手机 |
| buyer.province + city + district + detail | consigneeAddress | string | 是 | 完整地址（或拆分省市区） |
| buyer.postcode | consigneePostcode | string | 否 | |
| shipping.remark | remark | string | 否 | |
| items[].wms_sku_code | goodsNo / isvGoodsNo | string | 是 | 商家商品编码 |
| items[].qty | quantity | int | 是 | |
| items[].product_name | goodsName | string | 否 | |

**响应关键**：
- `eclpSoNo` → 平台记录为 `wms_outbound_no`（开放平台出库单号，ESL 开头常见）

### 8.2 取消出库单（jingdong.eclp.order.cancelOrder 等）

| 平台字段 | 京东字段 | 说明 |
|----------|----------|------|
| wms_outbound_no | eclpSoNo | 必填 |

### 8.3 出库结果回传 / 查询

通过回调或主动查询 `queryOrder`：
- 运单号、物流公司、出库时间、状态流水写入平台发货单。

### 8.4 创建退货入库单

| 平台标准字段 | 京东常见字段 | 说明 |
|--------------|--------------|------|
| inbound_no | isvRtwNo / isvUUID | 商家退货入库单号 |
| order_no / after_sale_no | 关联原出库或订单号 | 按接口要求 |
| owner_no | ownerNo | |
| warehouse_no | warehouseNo | |
| return_reason | reason / remark | |
| items[].wms_sku_code | goodsNo | |
| items[].qty | quantity | |

**响应**：`eclpRtwNo`（ESR/EBR 开头常见）→ `wms_inbound_no`

### 8.5 退货入库查询 / 回传

- 接口示例：`jingdong.eclp.rtw.queryRtw`
- 回传或查询结果中的实收数量、状态更新平台 `return_inbound_order`

### 8.6 公共鉴权
- method、app_key、access_token、timestamp、sign、v、format
- 签名按京东宙斯规则（参数排序 + secret）

---

## 9. 平台对外 API

### 9.1 后台配置 API

```
GET    /admin/api/v1/warehouses/{warehouse_id}/wms-config
PUT    /admin/api/v1/warehouses/{warehouse_id}/wms-config
POST   /admin/api/v1/warehouses/{warehouse_id}/wms-config/test

GET    /admin/api/v1/warehouses/{warehouse_id}/wms-sku-mappings
POST   /admin/api/v1/warehouses/{warehouse_id}/wms-sku-mappings/batch

POST   /admin/api/v1/fulfillment-orders/{id}/push
POST   /admin/api/v1/fulfillment-orders/{id}/cancel-push

POST   /admin/api/v1/return-inbound-orders/{id}/push
POST   /admin/api/v1/return-inbound-orders/{id}/cancel-push
```

### 9.2 WMS 回调入口

```
POST   /api/v1/wms/callback/cainiao
POST   /api/v1/wms/callback/jd-cloud
```

处理原则：快速验签 + 幂等落库 → 异步更新订单/售后/库存 → 按规定格式返回成功。

---

## 10. 可靠性与运维

| 能力 | 实现方式 |
|------|----------|
| 幂等 | 出站 request_id；回传入库前按单号+状态去重 |
| 重试 | 消息队列 + 死信；次数可配置 |
| 对账 | 每日对比平台已推送 vs WMS 侧单据 |
| 监控 | 推送成功率、回传延迟、异常单量 |
| 日志 | wms_api_log 保留 ≥ 90 天 |
| 降级 | 可关闭 auto_push / auto_push_return，改为人工确认 |
| 密钥 | 加密存储，支持轮换覆盖 |

---

## 11. 实施计划建议

| 阶段 | 内容 | 预估 |
|------|------|------|
| P0 | 数据模型 + 发货单/退货入库单状态机 + 后台配置页 | 1～1.5 周 |
| P1 | 菜鸟 Adapter（出库 + 取消 + 回传 + 退货入库） | 2 周 |
| P2 | 京东云仓 Adapter（同等能力） | 2 周 |
| P3 | 库存同步、对账、异常处理、监控 | 1 周 |
| P4 | 联调、压测、运营手册 | 1 周 |

**建议**：先打通菜鸟完整闭环（含退货），再复制到京东云仓。

---

## 12. 风险与应对

| 风险 | 应对 |
|------|------|
| 官方接口字段/名称变更 | Adapter 隔离 + 配置化版本；保留文档快照 |
| 签名算法差异 | 各 Adapter 独立实现 |
| 回传延迟/丢失 | 主动查询补偿 + 人工补录运单/收货 |
| 一单多包裹 | 支持 packages 数组 |
| 退货实收与应退不一致 | 按实收恢复库存，差异人工处理 |
| 库存不一致 | 平台库存为准售卖，定期校准 |

---

## 13. 附录

### 13.1 术语
- **Outbound / 出库单**：发给 WMS 的发货指令
- **Return Inbound / 退货入库单**：发给 WMS 的退货收货指令
- **Fulfillment Order**：平台侧发货单
- **Adapter**：标准模型 ↔ 具体 WMS 协议转换层
- **货主编码**：菜鸟 ownerCode/customer_id；京东 ownerNo

### 13.2 参考方向
- 菜鸟：开放平台奇门仓配接口（deliveryorder.* / returnorder.*）
- 京东：宙斯 ECLP 销售出库、退货入库相关接口（eclp.order.* / eclp.rtw.*）

### 13.3 后续扩展
- 多物流产品选择
- 海外仓 Adapter
- 库内作业更细粒度状态展示
- 残次品单独仓位与处理流程

---

**文档结束**
