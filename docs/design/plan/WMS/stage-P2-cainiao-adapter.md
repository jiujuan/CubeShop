# Stage P2：菜鸟 Adapter（签名 / 网关 / 出库创建 / 出库取消 / 库存查询）

**状态**：⬜ 未开始
**工期**：约 1.5 周（含等待沙箱资料）
**对应设计文档**：§7.1（deliveryorder.create）、§7.3（取消）、§7.6（inventory.query）、§10（可靠性）、§12（风险）

---

## 1. 目标与功能

### 1.1 目标
把 P1 的 `MockAdapter` 换成真正的菜鸟（奇门仓配）协议实现：完成**签名/网关/报文转换/错误映射/日志脱敏**，使 `PushOutboundJob` 在配置真实沙箱凭证后能成功创建出库单，并具备接口级幂等与失败重试能力。

### 1.2 交付功能清单
| # | 功能 | 说明 |
|---|------|------|
| F1 | 配置文件 `config/wms.php` | 网关 URL（sandbox/prod）、超时、`version`、`sign_algo`；**密钥不落配置文件**（来自 `wms_configs` 加密字段） |
| F2 | 奇门签名组件 | 按菜鸟规则生成 `sign`，支持 VCRole 单位切换；生产联调前用官方工具类校验通过 |
| F3 | HTTP 网关 | `Http::` 客户端封装：超时、重试（仅幂等 GET）、`request_id` 透传、脱敏日志 |
| F4 | 创建出库单 | 平台 `OutboundDto` → 奇门 `deliveryorder.create` 报文（含 `orderLines.orderLineNo`、`ownerCode`、`sourcePlatformCode`） |
| F5 | 取消出库单 | `Pushed/Picking` 状态可取消 → 奇门取消接口 |
| F6 | 库存查询 | `inventory.query`：按 `itemCodes` 批量查，返回 `availableQty`（P5 同步复用） |
| F7 | 错误与异常映射 | 业务失败（`flag=failure`）→ `PushFailed/Exception`；网络类 → 重试；重复单号 → 幂等成功 |
| F8 | 报文脱敏与留痕 | 每次调用落 `wms_api_logs`，正文脱敏（手机号/地址部分掩码、密钥永不落地） |
| F9 | Mock ↔ 真实切换 | `api_env=sandbox` + 未配置 `app_key` → Mock；配置了真凭证 → 真实网关（fail-closed：prod 环境缺凭证直接报错而非静默 Mock） |

### 1.3 不做
- 退货入库接口实现放 **P4**（同网关、独立步骤）
- 回调验签放 **P3**

---

## 2. 依赖

### 2.1 前置
- **P0**（配置/Adapter 工厂/日志表）、**P1**（发货单状态机、Job、`OutboundDto`）

### 2.2 外部依赖（关键路径）
- 菜鸟开放平台**沙箱**账号：AppKey / AppSecret / 货主编码（ownerCode）/ 仓库编码（warehouseCode）
- 奇门网关地址与当前版本

> ⚠️ 若账号尚未到位：**先把 Step 1~4（配置、签名、网关、DTO 转换）做完并用 fixture 单测锁定**，待账号到位后只填真凭证做一次真实跑通。

---

## 3. 实施步骤

**Step 1｜配置与常量（后端）**
- 新建 `backend/config/wms.php`：
  ```php
  'providers' => [
      'cainiao' => [
          'gateway' => ['prod' => env('WMS_CAINIAO_GATEWAY_PROD'), 'sandbox' => env('WMS_CAINIAO_GATEWAY_SANDBOX')],
          'timeout' => 15,
          'connect_timeout' => 5,
          'version' => '2.0',
          'sign_algo' => 'md5',   // 以当下官方文档为准
      ],
  ],
  'mask_fields' => ['sender.phone', 'receiverInfo.mobile'],
  ```
- ⚠️ 遵循 SEC-01 约定：**任何密钥类 env 不设默认值**，缺失时 fail-closed

**Step 2｜签名组件（后端）**
- `app/Services/Wms/Adapters/Cainiao/Signature.php`
  - `sign(array $params, string $secret): string`
  - 规则草案（**以菜鸟官方最新规则为准，联调时校准**）：参数按 key 字典序拼 `key+value`，首尾包裹 secret 后 `md5`（或 `hmac-md5`）并大写
  - `verify(array $params, string $secret, string $sign): bool`（P3 回调复用）
- `CainiaoNormalizer.php`：平台值 → 菜鸟值（承运商 code 映射：`express_companies.channel_code` → `logisticsCode`；省市区取 `orders.address_snapshot`）

**Step 3｜HTTP 网关（后端）**
- `app/Services/Wms/Adapters/Cainiao/CainiaoGateway.php`
  - `post(string $method, array $bizContent, WmsConfig $config): array`
  - 统一：`request_id` 生成与透传、`Http::timeout()->retry(0)`（**写接口不做 HTTP 层重试，交给 Job**）、异常包装为 `WmsGatewayException`
  - 返回：原始 `flag/code/message + payload`，由 Adapter 层翻译
- `app/Services/Wms/Support/PayloadMasker.php`：按 `mask_fields` 脱敏后再写日志（手机号保留后 4 位）

**Step 4｜Adapter 实现（后端）**
- `app/Services/Wms/Adapters/CainiaoAdapter.php` 实现 Stage P0 定义的接口：
  - `createOutbound(OutboundDto $dto)`：映射 §7.1 字段表；`orderType=JYCK`；行号 `orderLineNo` 用行索引+1；`ownerCode` 取配置 `customer_id`；成功记录 `deliveryOrderId → wms_outbound_no`；业务码「单据已存在」→ 返回幂等成功（避免重复建单）
  - `cancelOutbound(CancelOutboundDto $dto)`：按 §7.3；已出库/已发货的业务错误映射为不可取消 → 抛 `WmsBizException` 让上层转 `Exception` 状态
  - `queryInventory(InventoryQueryDto $dto)`：按 §7.6 批量查询，返回 `['CN-SKU-001' => availableQty]`
  - 未实现的 `createReturnInbound/cancelReturnInbound` 暂抛 `WmsUnsupportedException`（P4 补齐）
- 错误映射表 `CainiaoErrorCode`：网络/超时 → `retryable=true`；业务失败 → `retryable=false`；重复单据 → `idempotent=true`

**Step 5｜工厂切换（后端）**
- `WmsAdapterFactory`：`provider=cainiao` → `CainiaoAdapter`；`api_env=sandbox` 且 `app_key` 为空 → `MockAdapter`（并记 warning）；`api_env=prod` 缺凭证 → 抛错（fail-closed）

**Step 6｜fixture 与报文快照（测试资产）**
- `backend/tests/Fixtures/cainiao/deliveryorder_create_success.json`、`..._failure.json`、`..._duplicate.json`、`inventory_query_success.json`
- 在 Adapters 附近保留一份「当前对接的字段快照」文档片段（写到 `docs/design/plan/WMS/_snapshots/cainiao-v1.md`），防止官方改版后无处追溯

**Step 7｜与 Job 接通**
- `PushOutboundJob` 改为对 `retryable` 错误才重试，`idempotent` 视为成功；取消流程新增 `CancelOutboundJob`
- 新增命令 `php artisan wms:probe {warehouseId}`：调 `queryInventory` 打印连通性 + 库存样例（排障用）

**Step 8｜文档更新**
- 更新 README 风险表：沙箱账号状态；记录当前使用的接口版本

---

## 4. 测试

### 4.1 单元测试（Pest）
- `tests/Unit/CainiaoSignatureTest.php`（≥6 例）
  - 官方样例向量（联调后用真实报文回填 fixture）生成 sign 一致
  - 参数含空值/中文/布尔的处理（官方规则差异点）
  - `verify()` 正例/反例（篡改任一参数必须 false）
- `tests/Unit/CainiaoOutboundMappingTest.php`（≥10 例）
  - `OutboundDto` → 报文字段逐一对应（含 `receiverInfo.province/city/area/detailAddress`）
  - `manual` 映射模式下 `orderLines.itemCode` 为 `wms_sku_code`
  - 行号连续、从小写 1 开始；多行金额/数量边界（qty=0 拒绝）
  - `sourcePlatformCode` 取配置或默认 `OTHER`
- `tests/Unit/CainiaoErrorMappingTest.php`（≥5 例）：三类错误的 `retryable/idempotent` 判定
- `tests/Unit/PayloadMaskerTest.php`（≥3 例）：手机号脱敏、密钥字段永不出现在输出
- `tests/Feature/WmsPushOutboundTest.php`（≥6 例，`Http::fake()` 驱动）：
  - 成功 → `Pushed` + `wms_outbound_no`
  - duplicate 码 → 幂等成功且仅调用一次外部接口
  - 网络异常 → 重试到上限 → `PushFailed`
  - 缺 AppSecret + prod → fail-closed 报错（不静默 Mock）
  - 每次调用写 1 条 `wms_api_logs`（脱敏校验）

### 4.2 回归测试（必跑）
- `php -d memory_limit=1G vendor/bin/pest` ≥ P1 基线，零失败
- `php artisan route:list --path=wms` 路由无重复、权限中间件齐全
- admin / web：`vue-tsc -b` + vitest 无新增失败（本阶段前端无改动则仅需确认未被破坏）

### 4.3 集成测试
| 场景 | 步骤 | 期望 |
|---|---|---|
| 沙箱创建出库单 | 配好沙箱凭证 → 后台「测试连通性」→ 下单支付 → Job 推送 | 菜鸟沙箱返回成功，`fulfillment_orders.status=pushed`，`wms_outbound_no` 非空 |
| 重复推送幂等 | 手工触发二次推送（同 `outbound_no`） | 菜鸟返回重复单据码 → 平台判定幂等成功，外部单据不重复 |
| 取消出库 | 对 `pushed` 状态发货单执行取消 | 菜鸟侧单据取消成功，本地 `cancelled` |
| 已发货不可取消 | 对 `shipped` 发货单取消 | 本地拒绝（409），不调用外部接口 |
| 库存查询 | `php artisan wms:probe 1` | 输出各 SKU 可用量；日志脱敏 |

---

## 5. 验收清单

- [ ] `config/wms.php` 无密钥默认值，缺失 fail-closed
- [ ] 签名单元测试含**真实报文向量**（至少 1 条官方样例）
- [ ] 出库创建/取消/库存查询三个接口均有 fixture + 单测覆盖
- [ ] 所有出站报文脱敏后才写日志，`wms_api_logs` 中搜不到 AppSecret/完整手机号
- [ ] 重复单据码被识别为幂等成功
- [ ] 生产环境缺凭证时不静默降级 Mock（fail-closed 生效）
- [ ] 沙箱真实跑通一次「创建出库单」（截图/日志存 `docs/testing/evidence/wms/`，不入库）
- [ ] 单元测试 / 回归 / 集成测试通过
- [ ] 报文字段快照归档到 `_snapshots/cainiao-v1.md`

### 验收记录
| 日期 | 人 | 结果 | 备注 |
|---|---|---|---|
|  |  |  |  |

---

## 6. 完成情况

- [ ] Step 1 配置文件
- [ ] Step 2 签名组件（含官方样例向量）
- [ ] Step 3 HTTP 网关 + 脱敏
- [ ] Step 4 `CainiaoAdapter`（3 个接口 + 错误映射）
- [ ] Step 5 工厂切换（含 fail-closed）
- [ ] Step 6 fixture + 字段快照
- [ ] Step 7 Job/取消 Job + `wms:probe`
- [ ] Step 8 文档更新
- [ ] 单元测试通过
- [ ] 回归测试通过
- [ ] 集成测试（沙箱）通过
- [ ] 验收清单全勾选

**阶段状态**：⬜ 未开始 → 完成后改为 ✅ 并同步 `README.md` §4
