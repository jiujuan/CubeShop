# CubeShop 运费升级技术方案（基于《快递费用怎么计算的分析文档》）

> 状态：✅ 四项口径已确认（2026-09-18）：A=取最大值、B=省级、C=拒单 409、D=只收首重费 → 可进入 Stage 1 实施分 3 个 Stage，每 Stage 独立可验证、可提交

---

## 一、文档要点吸收

设计文档的核心结论：

1. **运费 = f(收货地区, 重量/件数, 运费模板规则, 是否包邮)**；快递公司只是履约方式，不参与金额计算。
2. 后台应有独立「运费模板」模块：模板名 + 计费方式（fixed/weight/region）+ 地区分区 + 首重/续重 + 包邮条件。
3. 前端展示分三层：详情页预估、购物车动态预估、**结算页精确计算 + 地址切换实时刷新**；预估与实付必须同一套逻辑。
4. 异常处理：地址不在配送范围要明确提示，不能静默收默认运费。

## 二、现状盘点（代码级）

| 触点 | 现状 | 位置 |
|---|---|---|
| 运费计算 | 固定运费 + 满额包邮：`order.freight_default`(10 元)、`order.free_shipping_threshold`(满 X 免运费) | `OrderService::calcFreight()` (L654) |
| **运费模板表** | **已建未接线**：`freight_templates(id,name,mode,rules jsonb,status)`，mode=fixed/weight/region，模型只有 cast，无控制器/路由/业务引用 | 迁移 `2026_09_17_000034`、`FreightTemplate.php`（注释明确业务接入=T-053） |
| 商品重量 | `products.weight` 已有（int，**克**，默认 0），`ProductResource` 已出口 | 迁移 `2026_09_16_000007` |
| 收货地址 | `province/city/district/detail_address` 完整，订单有 `address_snapshot` | `user_addresses` |
| 金额架构 | `amount_details(v=1)` 一本账；运费在 `PricingCalculator` 之前算好，作为 `OrderContext.freightAmount` 输入，券/满减/分摊不感知运费来源 | `PricingCalculator` / `CouponService::buildContext` |
| 前端结算页 | **运费规则被前端硬编码复制**（`满 99 → 0 否则 10`），与后端配置必然漂移 | `CheckoutView.vue:70` |
| 权限 | `shipping.manage` 已存在（快递公司字典在用） | `RolePermissionSeeder` |

结论：**表和权重字段都是现成的，缺的是「计算引擎 + 模板管理 + 下单/预览接线」**，与公告功能同款补全模式。

## 三、技术方案

### 3.1 计算引擎（核心，纯函数）

新服务 `backend/app/Services/Shipping/FreightCalculator.php`：

```php
calculate(array $lines, ?AddressSnapshot $address): FreightResult
// line:  { template_id: ?int, weight_g: int, quantity: int, price: string }
// result { freight_amount: string, free_shipping: bool,
//          free_shipping_gap: string|null, not_support: bool, detail: array }
```

计算流程（先分组 → 逐组 → 汇总）：

1. **按模板分组**：`template_id=null` 的行走全局默认规则（现 `freight_default`+`threshold` 行为，保证向后兼容）。
2. **逐组按 mode 计费**（`rules` JSON 结构约定）：
   - `fixed`：`{ amount }` → 固定金额。
   - `weight`：`{ first_weight_g, first_fee, step_weight_g, step_fee }` → `first_fee + ceil((weight-first)/step)×step_fee`；组内多商品重量累加。
   - `region`：`{ default: {amount 或 first/step}, areas: [{ provinces: ["650000","540000",...], ...费用 }] }` → **按省行政区划代码（GB/T 2260 六位）匹配 `areas`**（code↔name 由 RegionService 双向映射，规避「内蒙古/内蒙古自治区」等名称变体风险），未命中走 `default`；**无 default 且未命中 → not_support=true**。
3. **组间汇总**：各组运费**取最大值**（业界主流口径，避免混合模板重复计费；见决策点 A）。
4. **包邮门槛**：全局 `order.free_shipping_threshold` 继续生效（按商品总额判断），满足则运费归 0，返回 `free_shipping_gap` 供前端「再买 ¥X 包邮」提示。
5. 引擎无 DB/Config 依赖，入参即真相 → 单测可穷举；配置与模板解析在调用方完成。

### 3.2 数据层

- 迁移 `000057`：`products` 加 `freight_template_id`（nullable，FK→freight_templates，null=走全局默认）；`system_configs` 预置 `order.freight_template_id`（空=完全旧行为）。
- `FreightTemplate` 模型补 `scopeEnabled()` + 规则结构常量（不改表结构）。
- **规则校验器**：`FreightRuleValidator`（store/update 与下单前共用），坏规则 fail-closed。
- **地区字典（统一数据源，不建库表）**：
  - `backend/resources/data/regions.json`：GB/T 2260 省市区三级（约 3200 条，`{code,name,children}` 树），提交进仓库、前后端共用同一份，杜绝两套数据漂移；乡镇级不进主文件（4 万+ 条体积过大，且地址仅存三级文本，无落库需求）。
  - `app/Services/Common/RegionService.php`：一次性加载 + 进程内缓存（Opcache），提供 `tree()` / `provinceCodes()` / `nameOf(code)` / `isValidProvinceCode(code)`。
  - 公开接口 `GET /api/regions`：返回树结构，强缓存（`Cache-Control` + ETag），前端地址选择器与运费模板编辑器共用。

### 3.3 下单接线（不改金额架构）

`OrderService::create()` 中替换 `calcFreight($totalAmount)`：

```php
$freight = $this->freight->calculate(
    lines:  $cartItems->map(fn($i) => [template_id => $i->sku->product->freight_template_id,
                                       weight_g  => $i->sku->product->weight,
                                       quantity  => $i->quantity, price => $i->sku->price]),
    address: $address,   // region 模式必需
);
if ($freight->notSupport) throw BusinessException::conflict('该地区暂不支持配送');
$ctx = $this->coupons->buildContext($itemRows, $freight->freightAmount);  // 下游全部不动
```

- `amount_details` 仍为 v=1，`freight_amount` 语义不变，只是来源升级；`PricingCalculator`/分摊/退款零改动。
- 计算失败降级：模板被删/规则坏 → 回退旧 `calcFreight` 并记 warning（文档建议的降级策略）。

### 3.4 接口

| 接口 | 说明 |
|---|---|
| `POST /api/orders/freight-preview`（auth:sanctum） | 入参 `{ items:[{sku_id,quantity}], address_id? }` → `{ freight_amount, free_shipping, free_shipping_gap, not_support, detail }`。结算页/购物车共用；传 address_id 才能算 region |
| `GET/POST/PUT/DELETE /api/admin/freight-templates`（permission:**shipping.manage**，已有权限零成本） | 模板 CRUD + 规则校验（region 的 provinces 必须是合法省 code，由 RegionService 校验）；启用中的模板删除前校验引用 |
| `GET /api/regions`（公开） | 地区字典树（强缓存 + ETag），admin 模板编辑器省份多选与 web 地址选择器共用同一数据源 |
| 商品编辑 | `ProductController` store/update 增收 `freight_template_id`（nullable） |

### 3.5 前端

**admin**
- 新页面「物流管理 → 运费模板」：列表 + 编辑弹层，按 mode 切换表单（fixed=金额；weight=首重/首费/续重单位/续费；region=默认规则 + **省份多选规则行，选项来自 `GET /api/regions`，提交省 code**），权限 `shipping.manage`。
- 商品编辑页加「运费模板」下拉（不选=全局默认）。

**web**
- `CheckoutView.vue`：**删除硬编码运费**；选地址后调 `freight-preview`（地址切换即刷新），未选地址显示「运费待结算」；展示「再买 ¥X 包邮」；`not_support` 时禁用提交并提示「该地区暂不支持配送」。
- 结算页金额明细已单列运费行，无需改版式。

### 3.6 测试

- `FreightCalculatorTest`（纯函数）：fixed/weight/region 各口径、边界（0kg、首重临界、step 取整）、混合模板取 max、包邮门槛、not_support、**region 按 code 匹配与非法 code**。
- `RegionServiceTest`：树结构加载、code↔name 映射、省 code 校验。
- `FreightTemplateApiTest`：CRUD + 权限 401/403 + 坏规则 422（含非法省 code 拒绝）。
- `OrderServiceTest` 增补：region 模板下单金额落库、not_support 拒单、模板缺失降级旧逻辑。
- `freight-preview` Feature 测试；`GET /api/regions` 冒烟（结构 + 缓存头）。

## 四、决策点（需拍板）

| # | 决策 | 推荐 | 备选 |
|---|---|---|---|
| A | 混合模板订单运费口径 | **各模板分组计算后取最大值**（主流，避免重复计费） | 求和 / 拆单 |
| B | region 匹配粒度 | **省级 + 行政区划代码匹配**（已确认；code 规避名称变体，字典零成本） | 市级（需地区字典扩展，二期） |
| C | 无 default 且地址未命中 | **拒单 409**（明确提示，符合文档） | 降级收默认运费 |
| D | weight=0 商品在 weight 模板下 | 只收首费（0 续重） | 视为 fixed |

## 五、实施分期

- **Stage 1（后端基座）**：`regions.json` 地区字典 + `RegionService` + `GET /api/regions` + `FreightCalculator`（region 按 code 匹配）+ 规则校验器 + 模板 CRUD API + 单测。可独立验证：接口 curl。
- **Stage 2（接线）**：products 绑定列 + 下单接线 + `freight-preview` + CheckoutView 接线 + 集成测试。pgsql dev 库同步迁移。
- **Stage 3（体验，可选）**：admin 商品表单模板下拉 + 详情页/购物车运费预估展示。

向后兼容保证：不建模板、不改配置时，运费行为与现在完全一致。
