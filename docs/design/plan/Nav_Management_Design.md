# 前台顶部导航可管理化 —— 方案分析

> 需求（用户原话）：前台最上面的导航「首页 / 热销推荐 / 运动户外 / 美妆个护 / 家具生活 / 数码配件 / 服装鞋包 / 开发童装 / 手机」，除「首页」外都来自后台大分类、按排序展示。希望能管理这些导航：可加入其它类别（如「新闻中心」「客服中心」），加入位置可自定义，「新闻中心」这类可以链接到任意位置（不像分类是自动加的链接）。问**最简单的实现方案**。

---

## 1. 现状

### 1.1 导航条的实际构成

`web/src/components/ShopHeader.vue:255-290`，一条 `<nav data-testid="category-nav">` 里横向排四段：

| 段 | 来源 | 链接 |
|---|---|---|
| 「全部商品分类」下拉 | `getCategories()` 全量 | hover 展开一/二级 |
| **首页** | 硬编码 | `/` |
| **热销推荐** | 硬编码 | `/search?sort=sales_desc` |
| 一级分类横排 | `v-for root in categories` | `/category/{public_id}`（`goCategory()` :81） |

即：**「首页」「热销推荐」是死的，分类是活的且只有它按 sort 排。**

### 1.2 后端分类接口

`backend/app/Http/Controllers/Storefront/ProductController.php:129-146`，`GET /products/categories`（公开、无 Service、无分页）：

- 一级概念 = `parent_id = 0`；排序 `sort DESC, id ASC`
- 返回字段**只有** `id`（= `public_id`）、`name`、`children[]`
- 表 `categories`：`id / parent_id / name / sort / status / softDeletes`（`2026_09_15_000003`，模型 `Category.php` fillable 仅四列）
- **无 icon、无 show_in_nav、无 URL 字段** —— 分类天生表达不了外链

### 1.3 已存在但没被用上的东西

- `GET /api/cms/nav`（`CmsController::nav()`，`routes/api.php:129`）已实现，前端 `web/src/api/cms.ts:70` 也定义了 `getCmsNav()`，但**零调用**。
  它只覆盖 CMS 栏目（单页 / 帮助中心），**不含商品分类、不支持任意 URL** —— 不能直接用，但它是「后台有开关、公开接口返回导航」的既有先例。
- `system_configs` 是 key-value 表（`config_key / config_value / description`），后台「系统设置」页按 key 渲染输入框。

### 1.4 结论

要满足三条诉求（加非分类条目 / 位置自定义 / 任意 URL），**必须引入一份「导航条目」数据**。关键分歧只在：这份数据放哪、分类怎么参与。

---

## 2. 三个方案对比

### 方案 A：独立 `nav_items` 表 + 分类「引用型」条目 ★ 推荐

新增一张表，每条导航是一个条目，分两类：

- `type=category`：**存 `category_id` 引用**，标题与链接由分类派生（改名/改 URL 自动跟随）
- `type=custom`：自定义 `title` + `url` + `target`（站内 `/news` 或站外 `https://…`）

位置由统一 `sort` 决定，后台拖拽/上移下移或手填数字。

| 诉求 | 满足方式 |
|---|---|
| 加入「新闻中心」「客服中心」 | 新增 custom 条目，填标题 + 任意 URL |
| 加入位置可自定义 | 统一 `sort`，可与分类交叉排列 |
| 分类链接自动 | 引用而非拷贝，分类改名/停用自动反映 |
| 额外收益 | 可隐藏某个分类（删条目即可）、可单条启停、可扩展二级菜单/图标 |

- 工作量：迁移 + Model + 控制器 + 后台页 + 前端改造 + 测试 ≈ **600~700 行**
- 代价：多一张表、多一个后台页、多一套权限

### 方案 B：`system_configs` 存一个 JSON（`site.nav_items`）

- 工作量 ≈ **300~400 行**，少一张表 + Model
- 后台要在系统设置页里塞一个结构化数组编辑器（`ConfigView` 现在按 key 渲染单值输入框，需特判）
- 若 JSON 里只存自定义条目 → 自定义项只能排在分类**之后**，满足不了「位置可自定义」
- 若 JSON 里也登记分类 → 需存 `category_id` 引用才能自动跟随，此时 JSON 等价于一张没有约束的表：**无外键、无单条校验、无法单条启停、无法建索引**，后续加二级菜单/图标会越改越脏
- 判定：**省的是建表成本，欠的是后续可维护性**。若确定「只加两三个外链、永远不会再扩展」，B 够用

### 方案 C：给 `categories` 表加字段 / 复用 CMS 栏目 ❌ 排除

- 给分类表加 `nav_url` 等字段：把「导航」塞进「分类」表，分类停用/删除时语义混乱，且「新闻中心」根本不是分类，会造出假分类
- 复用 `cs_faq_category.show_in_nav`：它只管 CMS 栏目，**商品分类不在 CMS 体系里**，无法表达商品分类导航，也缺 sort 与 URL

---

## 3. 推荐方案 A 的细节设计

### 3.1 数据模型 —— 迁移 `2026_09_20_000099_create_nav_items_table.php`

| 列 | 类型 | 说明 |
|---|---|---|
| `id` | bigint PK | |
| `type` | varchar(16) | `category` / `custom` |
| `title` | varchar(64) nullable | 仅 custom 用；category 型为 `null`，标题取自分类（避免改名后不一致） |
| `url` | varchar(255) nullable | 仅 custom 用 |
| `category_id` | bigint nullable | 仅 category 型；**唯一索引**（同一分类只登记一次，null 不冲突） |
| `target` | varchar(16) default `_self` | custom 型站外可设 `_blank` |
| `sort` | int default 0 | **越大越前**，与 `categories.sort` 体例保持一致 |
| `is_active` | bool default true | 单条启停 |
| timestamps | | |

⚠️ `category_id` 不加物理外键：分类是软删除，物理外键会挡住软删；改为**聚合时跳过失效引用**（见 3.2）。

### 3.2 接口

**公开 `GET /api/nav`**（新增，无需登录，挂 `routes/api.php` 公开段）

后端**一次性聚合排序**，前端拿到即渲染，不做合并逻辑：

1. 取 `is_active` 条目，`sort DESC, id ASC`
2. `category` 型：查分类，分类不存在 / `status != 1` / 已软删 → **静默跳过**（不返回空壳项）
3. `custom` 型：直出 `title / url / target`
4. 响应：`[{ id, type, title, url, target }]`（category 型补 `category_id`，前端高亮用）

**后台 `GET/POST/PUT/DELETE /admin/nav-items`**（权限 `nav.manage`）+ `POST /admin/nav-items/{id}/move`（上移/下移，交换相邻 sort）

### 3.3 迁移播种（同一迁移或 `000100`）

- 现有 `status=1 AND parent_id=0` 的分类按 `sort` 依次登记为 `category` 型
- 播一条 custom：「热销推荐」→ `/search?sort=sales_desc`（原来硬编码的那条，迁进来才可控）
- **「首页」不登记**：用户明确「除了首页」，且首页永远在第一位、永远存在，硬编码最省
- 幂等：`nav_items` 已有行则整段跳过

### 3.4 后端校验

- `type=category`：`category_id` 必填且存在，`title/url` 置 null
- `type=custom`：`title` 必填 ≤64，`url` 必填 ≤255
- ⚠️ `url` **禁 `required` 之外的强校验**（不强行要求 http 前缀，站内 `/news` 也要能填）；站内外由**前端按 `url` 是否以 `http` 开头**判断跳转方式，不存冗余的 `is_external` 列

### 3.5 前端改造（`web/src/components/ShopHeader.vue`）

- 「首页」保持硬编码
- 「热销推荐」+ `v-for categories` → 换成 `v-for item in navItems`
- 跳转：站内（不以 `http` 开头）→ `router.push(url)`；站外 → `<a :href="url" target="_blank" rel="noopener">`
- 高亮：由 `activeRootId === root.id` 改为 `route.path === item.url`（分类条目 url 形如 `/category/{public_id}`，与现有分类页路径一致）
- 「全部商品分类」下拉**不动**：它展示的是完整分类树，属于商品浏览入口，不是导航条目
- 数据请求：可复用 `stores/site.ts` 的 localStorage 缓存范式（`site_info` 键），避免每次刷新都打接口

### 3.6 后台页面

`admin/src/views/site/NavView.vue`（挂「系统管理」或「运营管理」分组）：列表 + 新增/编辑弹窗（类型单选：商品分类下拉 / 自定义链接填标题+URL+打开方式）+ 启停 + 删除 + 上移下移。体例照抄 `CategoryView.vue`，字段表单复用 `PageFieldForm.vue`。

### 3.7 测试

| 层 | 用例 |
|---|---|
| 后端 Feature | 公开接口顺序与 sort 一致；失效分类（停用/软删）被跳过；custom 条目直出；未登录可访问 |
| 后端 Unit | 聚合器：category 标题取自分类而非缓存副本 |
| admin vitest | 新增两类条目、上移下移、删除、类型切换时字段联动 |
| web vitest | 导航按接口顺序渲染；站外条目 `target="_blank"`；站内条目走 `router.push`；接口失败降级（现状是置空，保留） |

---

## 4. 决策点（已拍板）

| # | 决策 | 结论 | 理由 |
|---|---|---|---|
| D1 | 分类进导航的方式 | **① 引用型条目** | ② 满足不了「位置可自定义」；引用型还能顺带做到「隐藏某个分类」 |
| D2 | 排序交互 | **① 手填 sort 数字** | 与现有分类管理页体例一致，改动最小；后续要体验再升级为上移/下移 |
| D3 | 新增分类是否自动进导航 | **① 自动追加到末尾** | 保持现状行为，零回归；运营想前置再手动调 sort |
| D4 | 权限 | **① 新增 `nav.manage`** | 语义清晰，权限矩阵可精确控制谁能改导航 |

> D3 落地方式：后台 `createCategory` 成功后追加一条 `type=category` 的 `nav_items`（`sort` 取当前最小值 - 10，即排到末尾）；
> 分类软删/停用时**不删导航条目**，由公开接口聚合时跳过（恢复启用后自动回来）。

---

## 4.1 实施结论（已完成）

按方案 A 落地，D1~D4 全部按拍板执行。三处与计划的偏差：

1. **外链判断放在前端**（`url` 是否以 `http` 开头），没存 `is_external` 列 —— 派生信息不进存储。
2. **后台新增「引用已失效」标记**：分类被软删后条目仍列出，标红提示，公开接口跳过。
   计划只写了「静默跳过」，没考虑后台列表会把这类条目显示成空白名。
3. **迁移拆成三个**：000099 建表 / 000100 播种 / 000101 同步权限。
   权限必须单独一个迁移——存量环境不重跑 seeder，否则后台页 403（与既有 000056 同体例）。

**软删除（用户补充的事实）**带来的两个处理：

- `category_id` **不加物理外键**，否则挡住分类软删；存在性校验用
  `Rule::exists()->whereNull('deleted_at')`（`exists` 规则直接查表，默认不认 SoftDeletes）。
- 分类软删 / 停用时**不动**导航条目，只在聚合时跳过 —— 恢复启用后运营排的位置还在。

## 5. 工作量与风险

| 项 | 估算 |
|---|---|
| 迁移 000099 + 播种 000100 | ~80 行 |
| Model + 控制器（后台 CRUD + move + 公开聚合） | ~200 行 |
| admin 页面 + api | ~250 行 |
| web ShopHeader 改造 + api + store | ~80 行 |
| 测试（后端 ~8 / admin ~6 / web ~4） | ~250 行 |
| **合计** | **≈ 860 行** |

**主要风险（都很可控）**

1. **导航数据变更未及时反映**：分类改名后导航要跟着变 → 引用型设计已解决；若前端加缓存需定失效策略（建议 5 分钟 TTL 或跟随 `site_info`）
2. **高亮逻辑回归**：现状按分类 id 高亮，改为按 url 匹配后，`/category/{id}` 之外的页面（如 `/search?sort=sales_desc`）高亮口径要确认
3. **接口失败降级**：现状失败置空只留「首页」，改造后要保证同样不白屏

**不做的事（避免过度设计）**

- 不做二级下拉菜单（自定义条目带子项）—— 需求没提
- 不做多导航位（顶部/底部/移动端分开管）—— 需求只说「最上面的导航」
- 不做图标上传 —— 现状无图标
