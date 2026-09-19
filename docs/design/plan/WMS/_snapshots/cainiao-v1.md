# 菜鸟奇门接口报文快照（v1）

> 用途：官方接口可能改版，本文件作为**当前对接基线**留档。每次菜鸟侧变更都在此追加新版本，禁止直接修改历史段落。
> 创建日期：2026-09-19（P2 开工）；2026-09-20 由 **P2 实现回填**——记录代码里实际采用的字段映射与签名口径。

## 0. 签名（P2 实现基线）

**算法来源**：阿里巴巴开放平台官方 SDK `signTopRequest`（奇门仓配接入说明）。

**待签串构造**：
1. 参数名按 ASCII 升序（`ksort(SORT_STRING)`）；
2. 空值参数（`null` / `''` / `false`）整项跳过——注意 `0`/`"0"` **不**跳过；`sign` 自身排除；
3. 拼接 `key1value1key2value2…`（无分隔符）；
4. 业务报文 JSON 原文（`body`）**接在参数串之后**；
5. MD5 后转大写十六进制。

**secret 包裹位置（关键变体，已配置化）**：
| 口径 | 待签串 | 说明 |
|------|--------|------|
| `both`（默认） | `md5(secret + 参数串 + body + secret)` | 奇门官方文档口径 |
| `tail` | `md5(参数串 + body + secret)` | TOP 通用 SDK `signTopRequest` 的 md5 分支 |

- 由 `WMS_CAINIAO_SIGN_SECRET_WRAP`（`both`/`tail`）切换；联调报「签名验证失败 / error_code:25」时改配置重试，**不需改代码**。
- `hmac_md5`（`WMS_CAINIAO_SIGN_METHOD`）不受该项影响：secret 作 HMAC key，不参与待签串拼接。
- **签名用的 body 与实际发出的 body 必须逐字节一致**：网关用 `Http::withBody()` 发送原串（不走 `json()`），避免中文/斜杠转义差异破坏签名。

**系统参数（随请求 URL 传输，均参与签名）**：`method` / `app_key` / `customerId` / `timestamp`（`Y-m-d H:i:s`）/ `format=json` / `v`（默认 `2.0`）/ `sign_method` / `sign`。
> 刻意**不掩 `sign` 与 `app_key`**（签名非凭证；app_key 用于辨识应用），AppSecret / access_token 永不入日志（见 §6）。

## 1. deliveryorder.create（创建出库单）

方法名：`taobao.qimen.deliveryorder.create`（`WMS_CAINIAO_METHOD_CREATE_OUTBOUND`，可配去前缀）。
出库单类型：`JYCK`（一般交易出库，`WMS_CAINIAO_ORDER_TYPE`）。

### 平台 → 奇门 字段映射（`CainiaoAdapter::buildOutboundBiz()`）

| 平台来源 | 奇门字段 | 备注 |
|---|---|---|
| `fulfillment_orders.outbound_no` | `deliveryOrder.deliveryOrderCode` | **幂等键**：全局唯一，绝不复用 |
| `orders.order_no` | `deliveryOrder.sourceOrderCode` / `orderLines[].sourceOrderCode` | 交易订单号 |
| `wms_configs.warehouse_code` | `deliveryOrder.warehouseCode` / `orderLines[].ownerCode` 同层 | 缺则 fail-fast |
| `wms_configs.customer_id` | `deliveryOrder.ownerCode` | 货主编码；缺则 fail-fast |
| `address_snapshot.contact_name` | `deliveryOrder.receiverInfo.name` | 缺则 fail-fast |
| `address_snapshot.contact_phone` | `deliveryOrder.receiverInfo.mobile` | 缺则 fail-fast |
| `address_snapshot.province/city/district` | `receiverInfo.province/city/area` | 平台叫 `district`，奇门叫 **`area`** |
| `address_snapshot.detail_address` | `receiverInfo.detailAddress` | 缺失回落 `full_address` |
| `fulfillment_order_items.wms_sku_code` | `orderLines[].itemCode` | 缺则 fail-fast（拒绝推送） |
| `fulfillment_order_items.platform_sku_code` | `orderLines[].itemName` 旁路/条码 | `product_name` → `itemName` |
| `fulfillment_order_items.qty` | `orderLines[].planQty` | ≤0 拒绝推送 |
| 行下标（从 0 起） | `orderLines[].orderLineNo` | 平台转成**从 1 起**的字符串 |
| — | `sourcePlatformCode` | 配置 `source_platform_code`，空则 `OTHER` |

信封形状要点：`orderLines` 与 `deliveryOrder` **平级**（`orderLines.orderLine` 数组），不嵌套。

### 响应样例（成功）
```json
{ "response": { "flag": "success", "code": "0", "deliveryOrderId": "CN-20260920-000001" } }
```
→ 平台记录 `wms_outbound_no = payload.deliveryOrderId`（缺失回落 `deliveryOrderCode`）。
> 实现同时兼容 `deliveryorder_create_response` 包裹式与根层 `flag` 两种外形（见 `CainiaoGateway::extractEnvelope()`）。

### 已知业务码（P2 实现归类）
| code | 含义 | 平台处理 |
|------|------|----------|
| `S07` / `ORDER_ALREADY_EXISTS` / `DELIVERY_ORDER_EXISTS` | 单据已存在 | **幂等成功**（`WmsResult.idempotent=true`），记 WMS 单号，**不开新单** |
| `S01`/`S02`/`S05`/`S09`/`SYSTEM_ERROR`/`TIMEOUT` | 对方系统/网关侧异常 | **可重试**（退避重试，超限转 `push_failed`） |
| `S03` | 参数校验失败 | 不可重试，`push_failed` 转人工 |
| `S04` | 签名/凭证校验失败 | 不可重试，转人工（改配置后重推） |
| `S12` | 货品编码不存在 | 不可重试，提示补 SKU 映射 |
| HTTP 408/429/5xx、网络异常 | 传输层 | 可重试 |

> 判据**全部来自显式规则表**（`CainiaoErrorCode`），不做「消息含'超时'就重试」的文本嗅探。

## 2. deliveryorder.confirm（发货回传）
### 平台关注的字段
`deliveryOrderCode / deliveryOrderId / expressCode / logisticsCode / logisticsName / orderConfirmTime / orderLines[].actualQty / packages[]`
### 样例待补（P3 联调回填）

## 3. returnorder.create（创建退货入库单）
方法名：`taobao.qimen.returnorder.create`。**P2 未实现**——`CainiaoAdapter::createReturnInbound()` 抛 `WmsUnsupportedException`（P4 补齐），绝不返回假成功。样例待 P4 回填。

## 4. returnorder.confirm（收货回传）
样例待补（P4 联调回填）

## 5. inventory.query（库存查询）
方法名：`taobao.qimen.inventory.query`。请求 `warehouseCode / ownerCode / itemCodes`。
### 响应样例（成功）
```json
{ "response": { "flag": "success", "items": { "item": [ { "itemCode": "W-SKU-001", "quantity": 128, "lockQuantity": 4 } ] } } }
```
> 兼容「单条返回对象、多条返回数组」两种形态；平台归一为 `items[].{sku_code,quantity,lock_quantity}` 与 `quantities{itemCode:qty}`（P5 库存同步复用）。

## 6. 脱敏规则（P2 实现）
`wms_api_logs` 落库前统一过 `PayloadMasker`（`WmsApiLogService` 内，调用方无法绕过）：
- 键名归一（去 `_`/`-`/`.`/空格、转小写）后含 `keywords` → 整值抹为 `***`（`appsecret`/`secret`/`accesstoken`/`refreshtoken`/`password`/`privatekey`/`credential`）；
- 键名含 `phone_keys`（`mobile`/`phone`/`telephone`/`tel`）→ 保留后 4 位（`*******8000`）；太短则整串抹除；
- **故意不掩** `app_key`（辨识应用）与 `sign`（非凭证）。

---

## 变更记录
| 日期 | 版本 | 变更内容 | 影响范围 |
|------|------|----------|----------|
| 2026-09-19 | v1 | 建立文件，待联调回填 | — |
| 2026-09-20 | v1（回填） | P2 实现基线：签名口径（both/tail 可配）、create/query 字段映射、错误码归类、脱敏规则 | `CainiaoAdapter` / `CainiaoGateway` / `CainiaoErrorCode` / `CainiaoNormalizer` / `PayloadMasker` |
