# Stage P0：仓库数据模型 + WMS 配置 / SKU 映射 + 后台配置能力

**状态**：⬜ 未开始
**工期**：约 1 周（5 人日）
**对应设计文档**：§3.1～§3.4（配置入口/配置项/页面原型）、§4.1（`wms_config`、`wms_sku_mapping`）、§9.1（后台配置 API）

---

## 1. 目标与功能

### 1.1 目标
把「仓库」这个概念引入 CubeShop（当前不存在），并在仓库之上落地 WMS 对接配置与 SKU 映射，使后续所有推送知道「推给谁、用什么凭据、SKU 怎么翻译」。本阶段结束时，即便没有任何外部 WMS 账号，平台内部也应该能在配置页完成一次"伪连通性测试"（Mock 模式）。

### 1.2 交付功能清单
| # | 功能 | 类型 | 说明 |
|---|------|------|------|
| F1 | 仓库档案 | 后台 CRUD | `warehouses`：编码、名称、联系人、省市区、启用状态 |
| F2 | WMS 对接配置 | 后台读写（按仓库唯一） | provider/enabled/auto_push/auto_push_return/retry/sku_mapping_mode + 菜鸟凭证 + 环境 |
| F3 | 配置写入安全 | 后端 | `app_secret`/`access_token` 用 `Crypt` 加密存储、接口返回掩码、只写不读 |
| F4 | 连通性测试 | 接口 + 页面按钮 | 调用 Adapter 的 `queryInventory`（沙箱）或 Mock，返回耗时与结果 |
| F5 | 回调地址自动生成 | 后端 | 按 `config('app.url')` + 仓库 token 生成只读回调 URL，供复制到菜鸟后台 |
| F6 | SKU 映射管理 | 后台增删 + 批量导入 | `same` 模式回落平台 `sku_code`；`manual` 模式必须命中映射，缺失则拒绝推送 |
| F7 | 权限与菜单 | 后端 + admin | `wms.config.manage`、`wms.order.view`、`wms.order.manage`、`wms.return.manage` |
| F8 | 配置变更审计 | 后端 | 所有写操作落 `sys_operation_log` |

### 1.3 明确不做
- 不推送任何单据（P1 起）
- 不做京东配置的表单（字段预留，P8 启用；provider=jd_cloud 时保存即报"敬请期待"）

---

## 2. 依赖

### 2.1 前置
- 无（本阶段是整条链路的起点）

### 2.2 依赖的现有资产
- 权限源：`database/seeders/RolePermissionSeeder::PERMISSIONS` + 存量幂等迁移（同改）
- 审计：`operationLog->record()`（参考 `RefundService::process()` 的调用写法）
- 商品/SKU：`ProductSku`（`sku_code` 为平台侧唯一编码，映射默认源）
- admin 前端：`admin/src/views/` 扁平页面 + `components/TablePagination.vue` 复用 + 侧栏 icon 双注册

### 2.3 外部依赖
- 菜鸟开放平台沙箱账号（AppKey/AppSecret/货主编码/仓库编码）——若申请未下来，**使用 Mock 凭证先跑通**（`provider=cainiao` + `api_env=sandbox` + Mock gateway 开关）

---

## 3. 实施步骤

**Step 1｜迁移：仓储域四张基础表（后端）**
新增（编号接当前最新 `000058`）：
- `2026_09_20_000059_create_warehouses_table.php`：`id, code(unique), name, contact_name, contact_phone, province, city, district, address, status(tinyint), timestamps`
- `2026_09_20_000060_create_wms_configs_table.php`：按 README §3-D2/D6，`unique(warehouse_id)`；`provider/enabled/auto_push/auto_push_return/push_retry_times/sku_mapping_mode`；`app_key/app_secret_enc/access_token_enc/customer_id/owner_no/warehouse_code/warehouse_no/api_env/callback_token/extra_config(json)/remark`；FK `warehouse_id → warehouses.id`（restrict）
- `2026_09_20_000061_create_wms_sku_mappings_table.php`：`warehouse_id, sku_id, platform_sku_code, wms_sku_code, barcode, status`，`unique(warehouse_id, sku_id)`
- `2026_09_20_000062_create_wms_api_logs_table.php`：`direction(outbound/inbound), provider, api_name, request_id, biz_no, request_body(json), response_body(json), http_status, success, error_msg, created_at`（P2/P3 复用，提前建便于 P0 记录连通性测试）

⚠️ 迁移必须 PDO 双兼容：全部用 `$table->json()`（PG 下即 jsonb）；写完后 `cd backend && php artisan migrate --force` 同步本地 PG 开发库。

**Step 2｜模型与枚举（后端）**
- `app/Models/Warehouse.php`、`WmsConfig.php`、`WmsSkuMapping.php`、`WmsApiLog.php`
- `WmsConfig`：`app_secret_enc/access_token_enc` 用 accessor/mutator 自动 `Crypt` 加解密；新增 `maskedAppSecret` 只读属性（返回 `****` + 后 4 位）
- `app/Support/WmsProvider.php`：`PROVIDER_CAINIAO='cainiao'`、`PROVIDER_JD_CLOUD='jd_cloud'`；`app/Support/WmsMappingMode.php`：`same`/`manual`
- 单号：`NoGeneratorService` 暂不新增前缀（履约单号在 P1 加），本阶段只用 `callback_token`（随机 32 位，用于回调路由识别仓库）

**Step 3｜Adapter 契约占位 + Mock 实现（后端）**
- `app/Services/Wms/Contracts/WmsAdapter.php`（接口）：`queryInventory(), createOutbound(), cancelOutbound(), createReturnInbound(), cancelReturnInbound()`——**签名先按 README D1 去掉 tenant 参数**
- `app/Services/Wms/Dto/`：`InventoryQueryDto`、`OutboundDto`、`ReturnInboundDto`（PHP 8 promoted readonly class，参考 `app/Services/Shipping/Dto` 风格）
- `app/Services/Wms/Adapters/MockAdapter.php`：按入参返回固定成功报文，记录日志，`api_env=sandbox` 或未配置真实凭证时兜底（**fail-closed 只在密钥缺失且 env=prod 时抛错**）
- `app/Services/Wms/WmsAdapterFactory.php`：按 provider + env 解析实现，绑定单例

**Step 4｜配置服务（后端）**
- `app/Services/Wms/WmsConfigService.php`：
  - `getForWarehouse(int $warehouseId): ?WmsConfig`
  - `save(int $warehouseId, array $data, int $operatorId): WmsConfig`（凭证字段空白表示"不覆盖"）
  - `testConnection(WmsConfig $config): array`（成功/耗时/错误；写 `wms_api_logs`）
  - `resolveSkuCode(WmsConfig $config, int $skuId): string`（`same` 返回 `sku_code`；`manual` 查映射，缺失抛 `BusinessException` 409/40009 提示"该仓库未配置 WMS 货品编码"）
  - `callbackUrl(WmsConfig $config): string`

**Step 5｜后台接口（后端 + 路由）**
在 `routes/api.php` 的 `Route::prefix('admin')` 组内新增 `wms` 前缀：
```
GET    /api/admin/wms/warehouses                            permission:wms.config.manage
POST   /api/admin/wms/warehouses                            permission:wms.config.manage
PUT    /api/admin/wms/warehouses/{id}                       permission:wms.config.manage
GET    /api/admin/wms/warehouses/{id}/config                permission:wms.config.manage
PUT    /api/admin/wms/warehouses/{id}/config                permission:wms.config.manage
POST   /api/admin/wms/warehouses/{id}/config/test           permission:wms.config.manage
GET    /api/admin/wms/warehouses/{id}/sku-mappings          permission:wms.config.manage
POST   /api/admin/wms/warehouses/{id}/sku-mappings/batch    permission:wms.config.manage
DELETE /api/admin/wms/warehouses/{id}/sku-mappings/{skuId}  permission:wms.config.manage
```
控制器：`app/Http/Controllers/Admin/WmsConfigController.php`
- 出口脱敏：`app_secret`/`access_token` 永不返回明文（返回 `masked`）
- 校验：`provider in cainiao,jd_cloud`、`api_env in prod,sandbox`、`push_retry_times 0..10`、`sku_mapping_mode in same,manual`
- 每个写操作 `operationLog->record($adminId, 'wms', 'config_saved', 'warehouse', $warehouseId, [...])`

**Step 6｜权限与种子（后端）**
- `RolePermissionSeeder::PERMISSIONS` 追加 4 码（新装路径）
- 幂等迁移 `2026_09_20_000063_sync_wms_permissions.php`：给 `permissions` 表补行 + 给超管角色赋权（**必须与 Seeder 同时改**，否则存量环境缺权限）
- Seed 数据：`WarehouseSeeder`（1 个默认仓 `WH_DEFAULT`）+ 幂等，注册到 `DatabaseSeeder`；不要造 WMS 配置真数据（避免把假凭证带入开发库）

**Step 7｜admin 前端页面**
- `admin/src/views/wms/WarehouseListView.vue`（页面骨架：`rounded-lg bg-white p-5 shadow-sm` + 纯文本 `h2` + 朴素表格 + `TablePagination`）
- `admin/src/views/wms/WmsConfigView.vue`：供应商单选（京东禁用 + 提示"P8 支持"）、自动推送两个开关、SKU 映射方式、菜鸟凭证（AppSecret 输入框 type=password，编辑时不回填明文，留空表示不修改）、环境单选、只读回调地址 + 复制按钮、「测试连通性」按钮（结果用行内徽标展示耗时）
- `admin/src/views/wms/WmsSkuMappingView.vue`：列表 + 手工新增 + CSV 批量导入（列：`sku_code,wms_sku_code,barcode`；导入前校验 `sku_code` 存在性，逐行返回结果）
- `admin/src/api/wms.ts` 封装上述接口；侧栏菜单「仓库与物流 / WMS 对接」入口 + 图标双注册（lucide import + icons 映射表）

**Step 8｜文档与标志位**
- 本文件 §5 完成情况勾选
- README §4 进度表 P0 行状态更新为 ✅

---

## 4. 测试

### 4.1 单元测试（Pest，新增 `backend/tests/`）
- `tests/Feature/WmsConfigApiTest.php`（≥10 例）
  - 未带 `wms.config.manage` 权限 → 403
  - 新建仓库、重名 `code` → 422
  - 保存配置：`app_secret` 落库为密文、`ApiException` 出口不含明文、`provider` 非法值 422
  - 二次保存留空 `app_secret` → 原值保留
  - 连通性测试：Mock 成功 / 失败分别返回 `success=true|false` 并落 `wms_api_logs`
  - `sku_mapping_mode=manual` 且无映射 → `resolveSkuCode()` 抛 40009
  - `same` 模式返回平台 `sku_code`
- `tests/Unit/WmsConfigTest.php`（≥4 例）：加解密往返、掩码格式、回调 URL 生成、`callback_token` 唯一
- `tests/Unit/WmsAdapterFactoryTest.php`（≥3 例）：按 provider 解析正确实现、未知 provider 抛错、Mock 兜底条件

### 4.2 回归测试（必跑）
- `php -d memory_limit=1G vendor/bin/pest` 全量 ≥ **994 + 新增**（不允许任何失败）
- `php artisan migrate:fresh` 后可完整播种（验证 Seeder 幂等）
- admin：`npx vue-tsc -b` 0 error、`npx vitest run --fileParallelism=false` 无新增失败
- 既有 GanTan已有特性不受影响核查：商品/订单/支付/退款核心用例全绿

### 4.3 集成测试
| 场景 | 步骤 | 期望 |
|---|---|---|
| 配置页完整回显 | admin 打开 WMS 配置页 → 填写 Mock 凭证 → 保存 → 刷新页面 | AppSecret 显示为掩码；其余字段正确回显 |
| 连通性测试 | 点「测试连通性」 | 页面显示"连通成功（Mock，xx ms）"，`wms_api_logs` 新增 1 条 outbound |
| 密钥不覆盖 | 保存后再次编辑，AppSecret 留空保存 | 旧密钥仍生效，日志显示未修改 |
| SKU 映射导入 | 上传 3 行 CSV（含 1 行非法 `sku_code`） | 2 行成功、1 行报错行号提示，不整体回滚 |
| 权限隔离 | 用无 `wms.config.manage` 的子账号访问 | 列表 403、菜单不显示 |

---

## 5. 验收清单

- [ ] 4 张迁移文件已合入，且**已在本地 PG 开发库执行 `migrate --force`**
- [ ] `wms_configs` 中 `app_secret_enc` 查询为密文，任何 API 出口不含明文
- [ ] 后台可新建仓库 → 配置 WMS → 测试连通性（Mock）成功
- [ ] SKU 映射批量导入成功，含逐行错误反馈
- [ ] 权限码同时存在于 Seeder 与幂等迁移，超管自动拥有
- [ ] 所有写操作有 `sys_operation_log` 记录
- [ ] 回调地址可在页面一键复制
- [ ] 单元测试、回归测试、集成测试全部通过（粘贴结果到本文档末"验收记录"）
- [ ] 代码按「后端 / admin」两个 commit 提交，不含无关文件

### 验收记录
| 日期 | 人 | 结果 | 备注 |
|---|---|---|---|
|  |  |  |  |

---

## 6. 完成情况（勾选后才允许改为 ✅）

- [ ] Step 1 迁移（4 张表 + PG 同步）
- [ ] Step 2 模型 / 枚举 / 单号
- [ ] Step 3 Adapter 契约 + Mock 实现 + 工厂
- [ ] Step 4 `WmsConfigService`
- [ ] Step 5 后台接口 + 路由
- [ ] Step 6 权限同步（Seeder + 幂等迁移）+ Seeder
- [ ] Step 7 admin 三个页面 + 菜单
- [ ] Step 8 状态更新与提交
- [ ] 单元测试通过
- [ ] 回归测试通过
- [ ] 集成测试通过
- [ ] 验收清单全勾选

**阶段状态**：⬜ 未开始 → 完成后改为 ✅ 并同步 `README.md` §4
