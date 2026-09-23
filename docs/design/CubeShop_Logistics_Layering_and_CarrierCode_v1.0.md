# CubeShop 物流分层架构与承运商编码归一

**版本**：v1.0
**日期**：2026-09-22
**状态**：已实施（编码归一随迁移 000113 落地；电子面单申请层 2026-09-23 新增，见 §9）
**关联文档**：`CubeShop_WMS_Integration_Cainiao_JD_v1.0.md`（WMS 对接）、`../deployment/KUAIDI100_SETUP.md`（轨迹查询接入）

---

## 1. 这份文档解决什么

项目里先后出现了两套"物流"能力：一套是仓库履约（菜鸟奇门 / 京东云仓），一套是快递轨迹查询（快递100）。两者名字里都有"物流"，容易被当成同一层的可选项，进而在管理端做出错误的配置模型。

本文档明确三件事：

1. **两者是上下游关系，不是二选一**；
2. **两者作用域不同**：一个按仓，一个全局；
3. **真正的冲突在承运商编码体系**，并给出归一方案。

---

## 2. 分层定义

### 2.1 两层职责

| | WMS（仓配履约） | 物流轨迹查询（TMS） |
|---|---|---|
| 管什么 | 库存、入库、拣货、打包、出库 | 运单走到哪了 |
| 数据方向 | 写 —— 指令下行 | 读 —— 状态上行 |
| **作用域** | **按仓**（A 仓菜鸟、B 仓京东可共存） | **全局**（全站统一一个渠道） |
| 配置载体 | `wms_configs`（带 `warehouse_id` + `provider`） | `system_configs.shipping.channel` |
| 失败影响 | **阻断业务** —— 订单发不出去 | **不阻断** —— 只是没轨迹 |
| 失败处理原则 | fail-closed，不允许"看着成功" | 降级跳过，保留已有轨迹 |
| 编码体系 | 仓方自己的（奇门 `logisticsCode`） | 快递100（`shunfeng`/`zhongtong`） |

最后一行是理解全篇的钥匙：**失败影响完全不同**。所以 WMS 缺凭证必须抛错（`MockAdapter::assertCredentialsForProd()`），而轨迹查询缺密钥用 `NullChannel` 静默降级是合理设计。

### 2.2 上下游关系

```
订单
 │
 ├─ 履约方式（由订单/仓决定）───────────────┐
 │   ├─ 商家自发货：admin 手填 公司 + 单号   │  写操作
 │   ├─ 菜鸟奇门仓：provider = cainiao      │  按仓配置
 │   └─ 京东云仓：provider = jd_cloud（P8） │
 │                                          │
 └──────────────────────────────────────────┘
                  ↓ 产出：运单号 + 承运商编码
                  ↓ （两层唯一的交接契约）
 ┌──────────────────────────────────────────┐
 │  轨迹查询层：快递100（全局唯一，只读）      │  读操作
 │  消费上述任意路径产出的运单号               │
 └──────────────────────────────────────────┘
```

**运单号是两层唯一的契约。** 快递100 不关心这个单号是管理员手敲的、还是菜鸟回传的，它只认"公司编码 + 单号"。

---

### 2.3 第三种能力：电子面单申请层（发货写回单号）

前两层解决"怎么发、走到哪"。但"运单号从哪来"在快递100 签约前一直依赖 admin 手敲。电子面单申请层把这笔单号的**产生**也纳入统一渠道模型：

| | WMS（仓配履约） | 电子面单申请（全局写） | 物流轨迹查询（全局读） |
|---|---|---|---|
| 管什么 | 库存/拣货/打包/出库 | 向快递方申请运单号 + 面单 | 运单走到哪了 |
| 数据方向 | 写（按仓） | 写（全局） | 读（全局） |
| 作用域 | 按仓 | 全局（一个渠道） | 全局（一个渠道） |
| 渠道载体 | `wms_configs` | `system_configs.waybill.channel` | `system_configs.shipping.channel` |
| 缺凭证 | fail-closed 阻断 | 降级为手动录入（Null 渠道） | 降级静默跳过（Null 渠道） |

**关键设计：电子面单申请层与轨迹查询层是镜像对称的。** 两者共享同一套渠道抽象（`*ChannelInterface` + 一个编排 Service）、共享 `CarrierCode` 编码归一、共享 `config/services.php` + DB 运行时覆写机制，区别只在方向：

- 轨迹查询：读第三方 → 落库 `traces`；
- 电子面单：写第三方 → 回写 `shippings.tracking_no` / `waybill_*`。

这种对称让"加一家快递公司"变成纯增量：新写一个 `*WaybillChannel` 类 + 在 `AppServiceProvider` 注册一个分支即可，不动编排层与发货入口。

---

## 3. 三者怎么区分

区分维度是**履约方式**，不是"选哪家物流平台"。

| 履约方式 | 判定依据 | 运单号来源 | 当前状态 |
|---|---|---|---|
| 商家自发货 | 订单未路由到 WMS 仓 | admin 手工录入 / Excel 批量导入 | 已支持 |
| 菜鸟奇门仓 | `wms_configs.provider = cainiao` | 出库回调回传 | 已支持 |
| 京东云仓 | `wms_configs.provider = jd_cloud` | 出库回调回传 | P8，字段已建枚举已备 |

三条发货路径最终都汇聚到 `OrderService::shipForShipment()` 这个唯一入口写 `shippings` 表，所以轨迹查询侧**不需要区分来源**。

### 3.1 京东云仓接入路径

准备工作已完成一半：

- `App\Support\WmsProvider` 枚举含 `JD_CLOUD`，但 `isAvailable()` 返回 `false`（`ENABLED` 只列 cainiao）；
- `wms_configs` 表的京东字段已一并建出且可空；
- `WmsAdapterFactory::make()` 已有 provider 分支骨架。

真正接入只需三步：`WmsProvider::ENABLED` 加入 `JD_CLOUD` → 实现 `JdCloudAdapter` → 工厂加分支。

> **关键决策**：京东云仓接入后，轨迹**仍然统一走快递100**，不单开查询链路。
> 理由：字典里 `JD`（京东物流）→ `jd` 已预填，快递100 原生支持查询。
> 除非京东主动提供轨迹推送（那是订阅推送架构，不是轮询），否则没必要引入第二个查询源。

---

## 4. 真冲突：三套承运商编码

### 4.1 冲突描述

同一个"快递公司"，在不同体系里有不同的编码：

| 体系 | 顺丰 | 圆通 | 京东 | 兜底值 |
|---|---|---|---|---|
| 平台内部（`express_companies.code`） | `SF` | `YTO` | `JD` | —— |
| 快递100 | `shunfeng` | `yuantong` | `jd` | —— |
| 菜鸟奇门 | `SF` | `YTO` | —— | `OTHER` |

原设计 `express_companies` 只有**一列** `channel_code`，且已按快递100 编码预填。这带来两个后果。

### 4.2 后果一：入站（WMS 回传）语义污染

链路：`CallbackMessageParser` 取奇门 `logisticsCode` → `DeliveryOrderConfirmHandler` → `markShipped()` → `shippings.company_code`。

原样落库后，`shippings.company_code` 出现了**两种互不相容的语义**：

| 来源 | 落库值 | `Kuaidi100Channel` 转换结果 |
|---|---|---|
| admin 字典选择 | `SF` | 命中映射 → `shunfeng` ✓ 正确 |
| 奇门回传平台码 | `SF` | 命中映射 → `shunfeng` ✓ 正确 |
| 奇门回传快递100 码 | `shunfeng` | 未命中 → 原样回落 ✓ **碰巧对** |
| 奇门回传中文名 / `OTHER` | `OTHER` | 未命中 → 原样回落 ✗ **查询失败** |

第 3 行靠运气，第 4 行是真炸弹——而且失败是静默的，只在 `last_fail_message` 里留痕。

### 4.3 后果二：`logisticsCode()` 方向反了（已修复）

`CainiaoNormalizer::logisticsCode()` 原本把平台 `code` 转成 **快递100 编码**（`SF` → `shunfeng`）准备发给奇门——**方向恰恰反了**，菜鸟不认 `shunfeng`。

所幸该方法全仓无任何调用点（测试中出现的 `logisticsCode` 是奇门报文字段名，不是这个方法），属于死代码，未造成实际事故。**已于本次清理**。

### 4.4 根因

`channel_code` 单列的设计隐含假设「全系统只有一个第三方」，这在 WMS 接入后不成立。

---

## 5. 解决方案

### 5.1 数据层：多渠道映射列（迁移 000113）

给 `express_companies` 增加 `carrier_codes` JSON 列：

```json
{
  "kuaidi100": "shunfeng",
  "cainiao": "SF",
  "jd_cloud": "JD"
}
```

**保留原 `channel_code` 列**做兼容——已有数据、admin UI、`Kuaidi100Channel` 都依赖它，一次性替换风险大于收益。迁移时把现有 `channel_code` 回填为 `carrier_codes.kuaidi100`，保证升级后行为完全不变。

### 5.2 解析层：`App\Support\CarrierCode`（唯一真源）

双向解析，取代散落各处的 `ExpressCompany::where(...)` 直查：

| 方法 | 方向 | 用途 | 未命中时 |
|---|---|---|---|
| `forChannel($platformCode, $channel)` | 平台 → 渠道 | 出站（查轨迹、下发仓配指令） | 回落平台 code（保持旧行为） |
| `fromChannel($externalCode, $channel)` | 渠道 → 平台 | **入站归一** | 返回 `null`，调用方保留原值并告警 |

> **⚠️ 两个方向的隔离策略是不对称的，这是有意设计。**
>
> - **正查严格隔离**：`channel_code` 只参与快递100 渠道的回落。因为它要给第三方发指令，
>   发错编码会被直接拒单（这正是被删掉的 `logisticsCode()` 踩的坑——把 `shunfeng` 发给菜鸟）。
> - **反查刻意不做隔离**：目标是「从任意标识猜出这是哪家公司」，多一条线索只会更准。
>   仓方回传的编码并不总能遵守我们的渠道划分，严格隔离反而会漏判
>   （开发过程中就被集成测试抓到过：奇门回传 `shunfeng` 在严格模式下识别不出）。
>
> 一句话：**出站怕发错，入站怕认不出。**

带进程内缓存；字典后台可维护，故提供 `flushCache()` 供长驻进程（队列 / 定时任务）与测试隔离使用。

`forChannel` 的取值优先级：

1. `carrier_codes[渠道]` —— 精确配置，最高优先；
2. `channel_code`（仅当渠道为 `kuaidi100`）—— 兼容历史数据；
3. 平台 `code` 本身 —— 保守回落。

### 5.3 入站归一

`DeliveryOrderConfirmHandler` 在写库前用 `fromChannel()` 反查：

```
仓方回传 SF        → 归一为 SF        → shippings.company_code = SF  ✓
仓方回传 shunfeng  → 归一为 SF        → shippings.company_code = SF  ✓
仓方回传 OTHER     → 未命中 → 保留原值 + Log::warning             ⚠
```

归一后 `shippings.company_code` 语义**统一为平台 code**，`shipping_packages.carrier_code` 保留仓方原始值以便对账溯源自证。

未命中不再静默：告警日志带 `warehouse_id`、原始编码、订单号，可在「物流监控」异常列表里被主动发现。

---

## 6. 管理端配置模型建议

**不要**做一个把快递100 / 菜鸟 / 京东云仓并列的下拉。正确拆法是两页：

| 页面 | 作用域 | 内容 |
|---|---|---|
| 仓储配送配置 | 按仓 | 选 provider（菜鸟 / 京东云仓）、填仓方凭证 |
| 轨迹查询配置 | 全局 | 选渠道（快递100 / Mock / 关闭）、显示密钥是否已配置 |

现存实现中，「轨迹查询」已由物流监控页顶部的渠道卡片承担（`shipping.channel`）；「仓储配送」为 `wms_configs` 按仓维护。**两者不应合并。**

字典页 `ExpressCompanyView` 已支持按渠道分别维护编码（快递100 / 菜鸟奇门 / 京东云仓三列），映射到 `carrier_codes`。

---

## 7. 约束清单

| # | 约束 | 原因 |
|---|---|---|
| 1 | 轨迹查询渠道全局唯一 | 它是只读消费方，按仓拆分无意义 |
| 2 | WMS 按仓配置，允许多 provider 共存 | 不同仓可能签约不同服务商 |
| 3 | `shippings.company_code` 只存平台 code | 否则快递100 解析行为不可预期 |
| 4 | `shipping_packages.carrier_code` 存仓方原值 | 用于对账溯源，不做转换 |
| 5 | 拉取间隔 ≥ 30 分钟 | 快递100 会锁单（详见 KUAIDI100_SETUP.md） |
| 6 | 新增编码务必先查 `CarrierCode` 而非直查模型 | 避免回落优先级被绕过 |
| 7 | 电子面单渠道全局唯一且与轨迹查询对称 | 同属"单号契约"两端，按仓拆分无意义 |
| 8 | Mock 渠道运单号必须确定性派生 | 防止并发/重试产生重复单号 |
| 9 | 缺面单凭证降级为手动录入（Null）而非阻断 | 发货是主流程，与 WMS fail-closed 不同 |
| 10 | `waybill.channel` 与 `shipping.channel` 各自独立配置 | 申请方与查询方可分别切换（如 mock 申请 + 真实查询） |

---

## 8. 源码索引

| 职责 | 文件 |
|---|---|
| WMS 服务商枚举 | `backend/app/Support/WmsProvider.php` |
| WMS 适配器工厂 | `backend/app/Services/Wms/WmsAdapterFactory.php` |
| WMS 适配器接口 | `backend/app/Services/Wms/Contracts/WmsAdapter.php` |
| 菜鸟归一（已删 `logisticsCode`） | `backend/app/Services/Wms/Adapters/Cainiao/CainiaoNormalizer.php` |
| 回传解析 | `backend/app/Services/Wms/Callback/CallbackMessageParser.php` |
| **入站归一落点** | `backend/app/Services/Wms/Callback/Handlers/DeliveryOrderConfirmHandler.php` |
| **编码解析真源** | `backend/app/Support/CarrierCode.php` |
| 轨迹查询渠道 | `backend/app/Support/Shipping/Kuaidi100Channel.php` |
| 轨迹拉取服务 | `backend/app/Services/Shipping/TracePullService.php` |
| 渠道配置迁移 | `backend/database/migrations/2026_09_22_000112_add_shipping_channel_config.php` |
| 编码映射迁移 | `backend/database/migrations/2026_09_22_000113_add_carrier_codes_to_express_companies.php` |
| 字典种子 | `backend/database/seeders/ExpressCompanySeeder.php` |
| 管理端字典页 | `admin/src/views/order/ExpressCompanyView.vue` |
| **电子面单渠道接口** | `backend/app/Support/Shipping/WaybillChannelInterface.php` |
| **电子面单请求 DTO** | `backend/app/Support/Shipping/WaybillRequest.php` |
| **电子面单结果 DTO** | `backend/app/Support/Shipping/WaybillResult.php` |
| **Mock 面单渠道** | `backend/app/Support/Shipping/MockWaybillChannel.php` |
| **快递100 面单渠道** | `backend/app/Support/Shipping/Kuaidi100WaybillChannel.php` |
| **Null 面单渠道** | `backend/app/Support/Shipping/NullWaybillChannel.php` |
| **面单编排服务** | `backend/app/Services/Shipping/WaybillService.php` |
| 面单字段迁移 | `backend/database/migrations/2026_09_23_000117_add_waybill_fields_to_shippings_table.php` |
| **面单接口测试** | `backend/tests/Feature/WaybillIssuanceTest.php` |

---

## 9. 电子面单申请层实现（2026-09-23 新增）

### 9.1 渠道抽象

`WaybillChannelInterface`（镜像 `ShippingChannelInterface`）：

```php
interface WaybillChannelInterface {
    public function issue(WaybillRequest $req): WaybillResult; // 申请运单
    public function available(): bool;                        // 渠道是否可用
    public function channelName(): string;                    // 渠道标识（mock / kuaidi100 / null）
}
```

三个实现：

| 渠道 | 类 | `available()` | `issue()` 行为 |
|---|---|---|---|
| mock | `MockWaybillChannel` | `true` | 产出**确定性**运单号 `MOCK` + `crc32(orderNo\|companyCode)`（十六进制大写），附 HTML 面单；本地/开发默认，无需签约即可跑通"发货 → 写回单号"全链路 |
| kuaidi100 | `Kuaidi100WaybillChannel` | 需 `WAYBILL_KEY` + `WAYBILL_CUSTOMER` | 同轨迹查询签名 `md5(param.$key.$customer)`，POST `poll/order.do`，解析 `data.kuaidinum`（兜底顶层）+ 面单模板 |
| null | `NullWaybillChannel` | `false` | 一律 `fail` → 编排层降级为"手动录入单号"，不阻断发货 |

> **确定性纪律**：Mock 渠道的运单号由 `crc32` 种子派生，与 `MockChannel` 轨迹时间的确定性同一思路——避免并发/重试时产生重复运单号或重复插入。

### 9.2 编排层 `WaybillService`

`issueForOrder(Order $order, string $companyCode): WaybillResult`：

- 收件人取自 `$order->address_snapshot`（姓名 / 电话 / 地址）；
- 寄件人取自 `config('services.waybill.sender_*')`；
- 重量取自 `config('services.waybill.default_weight_gram')`（默认 1000g）；
- 公司编码经 `CarrierCode::forChannel($companyCode, CarrierCode::KUAIDI100)` 归一后下发。

### 9.3 配置与运行时切换

- `config/services.php` 新增 `waybill` 数组：`channel`（env `WAYBILL_CHANNEL`，默认 `mock`）、`key` / `customer`（复用 `SHIPPING_*` 凭证，**不入库**）、`order_url`、`timeout`、`default_weight_gram`、`sender_*`。
- `AppServiceProvider` 按 `channel` 绑定 `WaybillChannelInterface`：`mock → MockWaybillChannel`、`kuaidi100 → Kuaidi100WaybillChannel`、其余 → `NullWaybillChannel`。
- 运行时覆写：`applyWaybillChannelOverride()` 读 `system_configs.waybill.channel`（与 `shipping.channel` 同机制），`off` → 强制 Null；优先级高于 env 配置。

### 9.4 发货入口钩子 `OrderService::shipForShipment()`

签名新增末参 `?bool $issueWaybill = null`：

| 传值 | 行为 |
|---|---|
| `null`（默认） | 跟随渠道可用性：渠道 `available()` 则自动申请，否则保留手动单号 |
| `true` | 强制申请；渠道报错则抛 `BusinessException` 阻断发货 |
| `false` | 手动模式：使用调用方传入的 `$trackingNo`，不申请面单 |

**所有既有调用方（admin 发货、BatchShip、WMS 履约、`FulfillmentOrderService`）默认 `null` 且未改变行为**——即"签约前手敲单号"流程完全不受影响；只有显式开启的发货路径才会自动申请面单。

### 9.5 落库字段（迁移 000117）

`shippings` 新增三个面单专属字段：

| 字段 | 类型 | 含义 |
|---|---|---|
| `waybill_channel` | string(20) nullable | 申请渠道标识（mock / kuaidi100 / …） |
| `waybill_printed_at` | timestamp nullable | 申请成功时间 |
| `waybill_data` | jsonb nullable | 第三方回执 / 面单原始数据 |

> 注：`tracking_no` 已于迁移 `000034` 存在，故本次只补三个面单专属字段，避免冗余列。

写回逻辑：申请成功后，`Shipping::create()` 落入 `tracking_no`（来自面单）、`waybill_channel`、`waybill_printed_at = now()`、`waybill_data = $raw`，并同步 `orders.tracking_no`。

### 9.6 面单打印端点（离线重打）

发货出单时，`OrderService` 已把可打印模板（`WaybillResult.labelData`）一并落库到 `waybill_data.print_template`，因此「重打」**不依赖再次调用第三方**（重打不应产生新单号 / 费用）。

**端点**：`GET /admin/shippings/{id}/waybill?format=html|json`（权限 `order.view`，与 `show` 一致）

| 形态 | 行为 |
|---|---|
| `format=html`（默认） | 返回自包含打印页：顶部打印按钮 + 面单内容 + `@media print` 样式（隐藏工具栏），`Content-Type: text/html`；客服在新标签打开后直接「打印面单」 |
| `format=json` | 返回结构化模板 `{ tracking_no, company_name, company_code, channel, printed_at, template }`，供程序化消费（如批量打印 / 转 PDF） |

**模板解析**（`Shipping::resolvePrintTemplate()`）取值优先级：

1. `waybill_data.print_template`（出单时落库，最权威）；
2. 快递100 旧结构 `data.printTemplate` / `printTemplateBase64` 兜底；
3. 均无 → 返回 `null`，端点报 `40022`（手动录入或渠道未返回面单数据的运单）。

`normalizePrintContent()` 把原始内容规整为可嵌入 HTML：

- HTML 片段 / 文档 → 原样嵌入（快递100 云打印模板自带脚本，按预期渲染）；
- base64 → 解码后按图片（`data:` URI `<img>`）/ HTML / 纯文本（`<pre>` 转义）分流；
- 纯文本 → `<pre>` 转义兜底。

**admin UI 接入（已完成）**：后台 SPA 用 Bearer Token 认证，`window.open(endpointUrl)` 直链不会带 Token，故新增 `openWaybillPrint(id)` 工具——
1. 同步 `window.open('', '_blank')` 拿到句柄（必须在用户手势调用栈内，规避弹窗拦截）；
2. 经已认证的 `request` 拉取 `…/waybill`（`responseType: blob`）；
3. 成功（`text/html`）`document.write` 进新标签页；失败（如 `40022` 无模板，返回 JSON）则关闭空白页并 `toast` 提示。

按钮落点（权限 `order.view`）：
- 订单详情页 `OrderDetailView` 物流信息卡右上角「打印面单」；
- 物流监控 `ShippingMonitorView` 列表行操作「面单」+ 轨迹详情弹层底部「打印面单」。

`toast` 由新增极简 `useToast` / `ToastHost`（挂载于 `App.vue`）统一渲染，失败路径优雅降级。

**仍待办**：批量打印（循环拉取 `format=json` 再合并排版）；PDF/ZPL 导出（加 dompdf / browsershot 属可选增强）。
