# CubeShop 接入快递100（第一期）设计与执行清单

> 版本：v1.0 ｜ 日期：2026-09-22 ｜ 范围：快递实时查询 + 智能单号识别

---

## 一、选型结论

**选快递100，不选快递鸟。** 这不是势均力敌的选择——CubeShop 的代码在设计期（V1.1 T-045）就按快递100铺好了路。

### 1.1 项目现状即证据

| 位置 | 现状 |
|---|---|
| `ExpressCompanySeeder.php` | 注释明写「channel_code 按**快递100 通用编码**预填」，8 家已填 `shunfeng/zhongtong/yuantong/yunda/shentong/jd/ems/jtexpress` |
| `AppServiceProvider.php` | 注释占位就是 `'kuaidi100' => new Kuaidi100Channel(...)` |
| `config/services.php` | 已有 `shipping.key` + `shipping.customer`，恰是快递100 的签名参数 |
| `ShippingChannelInterface` | 已就绪，`PullShippingTraces`、`TracePullService` 无需改动 |

快递鸟需 `EBusinessID + AppKey`（与现有配置对不上），编码是 `SF/YD/EMS` 大写体系（`channel_code` 要全部重映射）。

### 1.2 两家关键差异

| 维度 | 快递100 | 快递鸟 |
|---|---|---|
| 轨迹状态 | **8 态**（在途/揽收/疑难/签收/退签/派件/退回/转投） | **3 态**（仅 在途/签收/问题件） |
| TraceStage 覆盖 | 完整覆盖四态 | `PICKUP`/`DELIVERING` 成死代码 |
| 免费额度 | 套餐制，0.025 元/次起，量大 0.01 元 | 即时查询**每日硬限 500 次**，超出须改订阅模式 |
| 覆盖范围 | 3000+ 快递公司 | 1600～2700+ |
| 生态 | 订阅推送/地图轨迹/电子面单/寄件/时效 | 在途监控/电子面单/轨迹地图 |

---

## 二、全产品目录筛选（约 30 项 → 2 项）

文档总目录 `https://api.kuaidi100.com/document/` 六大类：快递查询 6 / 电子面单与云打印 3 / 寄件服务 7 / 跨境服务 2 / 增值服务 11。

| 筛除层 | 项目 | 理由 |
|---|---|---|
| 无业务场景 | 国际寄件、国际电子面单、国际地址解析、同城急送、电动车托运 | 全仓无 `international` 代码，无跨境业务 |
| 缺前置能力 | 自定义打印、上门取件、拦截改址、运单附件、面单 OCR、快递可用性、订单导入、智慧大屏 | 都要求先有电子面单或云打印，项目这两项为零 |
| 已有实现/口径冲突 | 智能地址解析、快递价格预估 | 前者已有本地 `AddressService::parse()`；后者会与 `FreightCalculator` 撞成两套运费口径 |
| 超前 | 短信发送、物流 MCP Server / Skills | 项目无短信体系；AI 场景未到 |

**第一期保留：① 快递实时查询 ② 智能单号识别。**

---

## 三、任务执行清单（全部已完成）

### 阶段 A：基础设施

| # | 任务 | 产出 |
|---|---|---|
| A1 | 运单手机号字段 | `2026_09_22_000110_add_phone_to_shippings_table.php` |
| A2 | 配置项 | `config/services.php` 新增 `query_url`/`autonumber_url`/`autonumber_enabled`/`autonumber_batch_limit`/`timeout` |
| A3 | 环境说明 | `.env.example` 补齐 `SHIPPING_*` 与 30 分钟间隔警告 |

### 阶段 B：实时查询渠道

| # | 任务 | 产出 |
|---|---|---|
| B1 | 渠道实现 | `app/Support/Shipping/Kuaidi100Channel.php` |
| B2 | 接口扩展 | `ShippingChannelInterface::query()` 增加可选 `?string $phone = null` |
| B3 | 手机号透传 | `TracePullService::pull()` 传 `resolvePhone()`；命令加 `with('order')` |
| B4 | 渠道注册 | `AppServiceProvider` 的 match 分支 |
| B5 | 发货冗余 | `OrderService::shipForShipment()` 写 phone（三条发货路径汇聚于此，改一处全覆盖） |

### 阶段 C：智能单号识别

| # | 任务 | 产出 |
|---|---|---|
| C1 | 识别服务 | `app/Support/Shipping/Kuaidi100AutoNumber.php` |
| C2 | 单条发货接口 | `POST /admin/orders/detect-company` |
| C3 | 批量校验集成 | `BatchShipService::validateRows()` 返回 `warnings`（仅提示不阻断） |
| C4 | 前端 | 批量结果展示 warnings；单条发货「识别」按钮 + 「采用」 |

### 阶段 D：验证

| 项 | 结果 |
|---|---|
| 后端 pest 全量 | 1484 passed / 0 failed（新增 20 用例） |
| admin `vue-tsc -b` | 0 错 |
| admin vitest | 302 passed（新增 2 用例） |

---

## 四、关键设计与坑

### 4.1 行级 stage 只能靠文案匹配

快递100 的 `state` 是**运单级**的，`data[]` 每行**没有** state。因此：

- 行级 stage 优先按**文案关键词**匹配，顺序即优先级：`签收 → 派件 → 揽收`
  （否则「派件已签收」会被误判成派送中）；
- 末行（order=asc 即最新）未命中关键词时，用运单级 `state` 兜底；
- `2/4/6/7`（疑难/退签/退回/转投）统一映射 `in_transit`（未签收），异常由文案体现。

### 4.2 手机号是硬门槛

快递100 对**顺丰速运、顺丰快运、中通快递**强制要求手机号，缺失直接查询失败。
`shippings` 原本无 phone 字段，故：

- 新增 `phone` 冗余快照（同 `company_name` 口径），发货时从 `address_snapshot.contact_phone` 固化；
- 历史数据靠 `Shipping::resolvePhone()` 回落，无需回填迁移；
- 电商虚拟号 `138****1234-5678` 取「-」后四位。

### 4.3 拉取间隔天然合规

`PullShippingTraces` 是 `everyThirtyMinutes()`，恰好满足快递100「每单查询间隔 ≥30 分钟否则锁单」的要求。**不要调高调度频率。**

### 4.4 PHP 接口签名兼容

接口加可选参数后，所有实现类必须同步：`NullChannel`、`MockChannel`、测试中的 FakeChannel。

### 4.5 Http::fake 首个 stub 优先

同一测试内多次 `Http::fake()` 只有第一个生效，多次响应必须用 `Http::sequence()`。

### 4.6 顺手修复的既有 bug

admin `BatchShipView.vue` 读 `row.message`，但后端返回的是 **`reason`** → 失败明细原因一直显示空白。已修复（含测试 mock）。

---

## 五、待确认事项

1. **实时查询「40 天同号去重」规则**：二手来源称 40 天内同一运单号多次查询不重复扣费。
   该规则直接决定「轮询 vs 订阅推送」的成本结论——若不成立，30 天窗口 × 30 分钟轮询成本极高。
   **需向快递100 商务确认书面口径后再决定是否上订阅推送。**

2. **电子面单的商务前提**：官方要求「商家需申请当地快递公司的网点面单或菜鸟/淘宝类平台面单」。
   需先明确 CubeShop 是单商家还是多商家 SaaS——后者意味着每家配面单账号，工程量远大于接口对接。
