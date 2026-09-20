# 内容中心 CMS 一期任务清单（帮助中心 → 通用 CMS）

**任务编号**：CMS-101 ~ CMS-112（本文件只列一期；二期 CMS-201~ 见 §8，规划不实施）
**阶段目标**：把只做 FAQ 的「帮助中心」升级为**内容中心 CMS** ——
① 栏目树支持父子分类；② 站点单页（关于我们 / 联系我们 / 公司介绍…）作为**特殊栏目**维护；
③ 文章与单页共用同一套后台入口（复用现有文章管理，不另起管理端）；
④ 前台单页**公开可访问**，页脚死链接通。
**预估**：9~12 人天（1 后端 + 1 前端并行）
**开工前置**：§1 五项设计决策已拍板（2026-09-20）
**阶段出口**：CMS-112 验收通过 —— 后台可维护栏目树与单页字段，前台 `/p/about` 可公开访问，页脚三个入口可点击。
**当前状态**：✅ 一期（CMS-101 ~ CMS-112）已全部实施完成，验收结论见 §10。

> 迁移号从 `000095` 起（`000094` 已被站点配置占用）；新增迁移须**手工在 pgsql 执行一次**，SQLite 测试库由 Pest 自动迁移。

---

## 1. 设计决策（已拍板 2026-09-20）

| # | 决策项 | 结论 | 影响面 |
|---|--------|------|--------|
| D1 | 单页内容档位 | **L2 模板字段** —— 模板注册表 + 按 schema 生成表单 | 需 `CmsPageTemplate` + `PageFieldForm.vue` |
| D2 | 权限码 | **沿用 `cs.faq.manage`**，不新增 `cms.manage` | 零权限迁移，角色无需调整 |
| D3 | 单页 URL | **`/p/:slug`**（如 `/p/about`） | 与既有路由零冲突 |
| D4 | 帮助中心登录 | **解除登录**，`/cs/faq/*` 转为公开 | 路由移出 `auth:sanctum` 分组 |
| D5 | 公告并入 | **暂不并入**，`cs_announcement` 保持独立 | 二期再评估（CMS-204） |

**核心判断（为什么原地演进而不新建 `cms_*` 表）**：
需求明确要求"复用后台帮助中心的新增文章、文章管理"；而重命名表名 / 权限码的回归成本（权限码是 Seeder + 幂等迁移双改的真源、前台接口与前端页面全量引用）远大于语义收益。因此 CMS 是**概念升级**，**数据零搬运**。

---

## 2. 改造前后对比

| 维度 | 改造前 | 改造后 |
|------|--------|--------|
| `cs_faq_category` | name / sort / is_active（**完全平铺**） | 加 `parent_id`/`level`/`path`/`type`/`slug`/`template`/`show_in_nav`/`icon`，**支持无限父子** |
| `cs_faq_article` | 文章正文 markdown | 加 `page_fields`(JSON) 承载单页结构化字段；普通文章该列为 null |
| 栏目语义 | 全部是 FAQ 分类 | `type=channel` 走文章列表，`type=page` 走单页字段 |
| 后台入口 | 「帮助中心」FAQ 管理 | 「内容管理」：左栏目树 + 右内容区（文章 / 单页字段） |
| 前台 | `/service-center/faq*`，**强制登录** | 帮助中心**公开**；新增单页 `/p/:slug`**公开** |
| 站点单页 | 不存在（catch-all 一律跳首页） | `type=page` 栏目 + 预设计 Vue 模板，内容后台可改 |

---

## 3. 数据模型总览

### `cs_faq_category` 新增列

| 列 | 类型 | 默认 | 说明 |
|----|------|------|------|
| `parent_id` | bigint | 0 | 父栏目 id；**0 表示根**（不用 null，规避双库 unique-null 语义差异） |
| `level` | tinyint | 1 | 层级，根为 1 |
| `path` | varchar(255) | '' | 物化路径 `/{id}/{id}/`，用于子树查询与**防环** |
| `type` | varchar(16) | `channel` | `channel` 栏目（挂文章） / `page` 单页 |
| `slug` | varchar(64) | null | 单页 URL 标识，唯一；非单页为 null |
| `template` | varchar(32) | null | 单页模板 key，取自 `CmsPageTemplate` |
| `show_in_nav` | boolean | false | 是否进入前台导航（`/cms/nav`） |
| `icon` | varchar(32) | null | 可选图标名 |

> ⚠️ `name` 的唯一性**只存在于控制器 validate**（建表时无 unique 约束），父子化后只需把校验规则改为「同父下唯一」，**不必改表结构**。

### `cs_faq_article` 新增列

| 列 | 类型 | 说明 |
|----|------|------|
| `page_fields` | json nullable | 单页结构化字段（键为 schema 的 field key）；普通文章为 null |
| `cover_image` | varchar(255) nullable | 列表封面（可选） |

---

## 4. 文件地图

**后端**

| 文件 | 动作 |
|------|------|
| `database/migrations/2026_09_20_000095_extend_cs_faq_to_cms.php` | 新建 |
| `app/Support/CmsPageTemplate.php` | 新建（模板真源） |
| `app/Services/Cms/CmsCategoryService.php` | 新建 |
| `app/Http/Controllers/Admin/CsFaqController.php` | 改造 |
| `app/Http/Controllers/CmsController.php` | 新建（前台公开） |
| `routes/api.php` | 改造 |
| `tests/Unit/CmsPageTemplateTest.php`、`tests/Feature/Cms{CategoryTree,PageApi,PublicApi}Test.php` | 新建 |

**admin**

| 文件 | 动作 |
|------|------|
| `src/components/MarkdownEditor.vue` | 新建（抽取复用） |
| `src/components/PageFieldForm.vue` | 新建 |
| `src/views/cs/CsFaqView.vue` | 改造 |
| `src/api/cms.ts` | 新建 |
| `src/layouts/AdminLayout.vue`、`src/router/index.ts` | 改造（文案） |

**web**

| 文件 | 动作 |
|------|------|
| `src/api/cms.ts` | 新建 |
| `src/views/PageView.vue` | 新建（单页容器） |
| `src/views/pages/PageAbout.vue`、`PageContact.vue` | 新建（预设计模板） |
| `src/router/index.ts` | 改造（`/p/:slug`） |
| `src/components/ShopFooter.vue` | 改造（死链接通） |

---

## 5. 批次划分

| 批次 | 范围 | 任务 |
|------|------|------|
| A | 数据底座与模板契约 | CMS-101 ~ CMS-103 |
| B | 后端接口 | CMS-104 ~ CMS-107 |
| C | 后台管理端 | CMS-108 ~ CMS-111 |
| D | 前台单页与导航 | CMS-112 |

---

## 6. 任务明细

### CMS-101 [后端] 迁移：`cs_faq_*` 扩展为 CMS 底座

| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 批次 A |
| 依赖 | — |
| 产出文件 | `backend/database/migrations/2026_09_20_000095_extend_cs_faq_to_cms.php` |
| 预估 | 0.5 人天 |

**目标**：为栏目树与单页提供数据结构，并保证老库升级幂等、可回滚。

**实现步骤**
1. `cs_faq_category` 加列：`parent_id`(bigint default 0)、`level`(tinyint default 1)、`path`(varchar 255 default '')、`type`(varchar 16 default 'channel')、`slug`(varchar 64 nullable unique)、`template`(varchar 32 nullable)、`show_in_nav`(boolean default false)、`icon`(varchar 32 nullable)；补索引 `[parent_id, sort]`、`[type, is_active]`。
2. `cs_faq_article` 加列：`page_fields`(json nullable)、`cover_image`(varchar 255 nullable)。
3. **回填存量**：`parent_id=0`、`level=1`、`type='channel'`（新增列默认值已覆盖），`path` 需按 id 回填为 `/{id}/`（PHP 循环写，不用 SQL 字符串拼接以兼容双库）。
4. **播种默认单页**（幂等 `firstOrCreate` 语义）：`关于我们`(slug=`about`, template=`about`)、`联系我们`(slug=`contact`, template=`contact`)，`type='page'`、`is_active=true`、`show_in_nav=true`、`sort` 递增。
5. 双库兼容：JSON 用 `$table->json()`；布尔用 `boolean`；时间用 `timestamps`；**不写 PG 专有类型**。
6. `down()`：先删两个默认单页，再 drop 新增列（SQLite 加列可回滚，drop 列 Laravel 11+ 原生支持）。

**测试要求**
- `Schema::hasColumn` 断言全部新列；迁移在 SQLite（Pest 自动）与 pgsql（手工）均成功。

**验收标准**
- AC-101.1 双库 `migrate` 通过，`migrate:rollback` 后新增列消失，可再次 migrate（幂等）。
- AC-101.2 存量分类回填后 `type='channel'`、`level=1`、`path='/{id}/'`。
- AC-101.3 两个默认单页存在且 `slug` 唯一；重复执行迁移不产生重复行。

---

### CMS-102 [后端] `CmsPageTemplate` 模板注册表 + 守卫测试

| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 批次 A |
| 依赖 | — |
| 产出文件 | `backend/app/Support/CmsPageTemplate.php`、`backend/tests/Unit/CmsPageTemplateTest.php` |
| 预估 | 0.5 人天 |

**目标**：建立"单页模板 → 字段 schema"的**唯一真源**，让后台表单与前台组件共用一份契约。

> 体例对齐既有真源：`App\Support\ConfigGroup`（配置分组）、`App\Support\AdminRole`（后台角色）。

**实现的功能**
- `TEMPLATES`：模板 key → `{label, fields[]}`；字段结构 `{key, label, type, required?, default?, hint?, item?}`（`item` 仅 `repeater` 用，描述子字段）。
- 字段类型白名单：`text` / `textarea` / `markdown` / `image` / `image_list` / `repeater`。
- 内置模板：`about`（关于我们）、`contact`（联系我们）。

**实现步骤**
1. 定义 `TEMPLATES` 常量与 `FIELD_TYPES` 白名单。
2. 静态方法：`labels()`（key→label）、`templateKeys()`、`exists(key)`、`schema(key): array`、`defaultsOf(key): array`（按 default 生成空表单初值）、`filterPayload(key, array payload): array`（丢弃未知键、补 default）。
3. `about` 字段建议：`banner`(image)、`intro`(markdown)、`milestones`(repeater: year/event)、`values`(repeater: title/desc)；
   `contact` 字段建议：`address`(text)、`phone`(text)、`email`(text)、`work_time`(text)、`map_image`(image)、`intro`(markdown)。
4. **不加 `optional` 之外的魔法**：保持纯数组，便于守卫测试与前端镜像。

**测试要求**
- `CmsPageTemplateTest`：① 每个模板的每个字段 `type` 都在白名单内；② `filterPayload` 丢弃未知键、保留合法键、缺失键补 default；③ `exists()` 对未知 key 返回 false。
- **守卫（关键）**：模板 key 必须与 `web/src/views/pages/` 下的组件一一对应 —— 测试用正则扫组件目录（参照 `admin/tests/sidebar-icon.test.ts` 的做法），新增模板漏写前台组件时直接失败。

**验收标准**
- AC-102.1 模板 key 集合与前台 `Page{X}.vue` 组件集合完全一致，多一个少一个都测试失败。
- AC-102.2 所有字段 type 均在白名单内。
- AC-102.3 `filterPayload` 对未知键静默丢弃，不抛异常（防脏数据入库）。

---

### CMS-103 [后端] `CmsCategoryService` 分类树服务

| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 批次 A |
| 依赖 | CMS-101 |
| 产出文件 | `backend/app/Services/Cms/CmsCategoryService.php` |
| 预估 | 1.0 人天 |

**目标**：把"树"的所有复杂度收敛到一个服务，控制器只做校验与转发。

**实现的功能**
- `tree(array $filters = [])`：构建树（含 `articles_count` / `published_count`），仅激活或全量可选。
- `create(array $data)` / `update(int $id, array $data)`：写入时维护 `level` 与 `path`。
- `move(int $id, int $parentId)`：改父 —— **防环**（不可移到自己或自己的后代）、重算自身与整棵子树的 `level`/`path`。
- `delete(int $id)`：有子节点或有文章则抛 `BusinessException::conflict`（沿用现有文案风格）。
- `nextSort(int $parentId)`：同级排序取最大值 +1。
- slug 校验：格式 `^[a-z0-9-]{2,64}$` 且不在**保留字黑名单**（`login`/`cart`/`orders`/`p`/`search`/`api` 等，避免与既有路由撞车）。

**实现步骤**
1. 物化路径约定：根节点 `path = /{id}/`；子节点 `path = parent.path + {id}/`。
2. `move` 校验：`$id === $parentId` 或 `str_starts_with($parent->path, $node->path)` → 拒绝（403/409 语义）。
3. 子树重算用一次性取子树（`where('path','like',$node->path.'%')`）后内存改写，避免 N+1。
4. 所有写操作包 `DB::transaction()`。

**测试要求**
- 建树顺序与层级正确；同级可重名、跨级可重名；移动后 `path`/`level` 全子树同步；移到自身/后代被拒；删除有子节点的分类被拒。

**验收标准**
- AC-103.1 三层树构建正确，`level` 与 `path` 一致。
- AC-103.2 移动后原子树 `path` 前缀全部改写，且顺序稳定。
- AC-103.3 任意非法移动（自环、跨树）均被拒且数据不变。

---

### CMS-104 [后端] 后台分类接口支持父子与类型

| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 批次 B |
| 依赖 | CMS-103 |
| 产出文件 | `backend/app/Http/Controllers/Admin/CsFaqController.php`、`routes/api.php` |
| 预估 | 1.0 人天 |

**实现步骤**
1. `categories()`：返回**扁平列表**（带 `parent_id`/`level`/`path`/`type`/`template`/`slug`/`show_in_nav`/`icon`/计数），前端自行建树 —— 避免接口形态变化过大，也便于前端复用既有表格。
2. `storeCategory` / `updateCategory`：新增字段校验；
   - `name` 唯一性改为**同父下唯一**：`Rule::unique('cs_faq_category','name')->where('parent_id', $parentId)->ignore($id)`；
   - `type` 限 `channel|page`；`type=page` 时 `template` 必填且须存在于 `CmsPageTemplate::templateKeys()`；`slug` 仅 `type=page` 可填且唯一、过保留字。
3. 新增 `POST /admin/cs/faq/categories/{id}/move`，body `{parent_id}`，走 `CmsCategoryService::move()`。
4. `destroyCategory` 改为委托 service（校验子节点 + 文章）。
5. 全部写操作记 `sys_operation_log`（沿用现有 `log()` 私有方法）。
6. 权限 `permission:cs.faq.manage` 不变。

**测试要求**：同父重名 422、跨父同名 200、`type=page` 缺 template 422、非法 slug 422、移动到后代被拒。

**验收标准**
- AC-104.1 分类接口可完整表达三层树。
- AC-104.2 所有非法入参返回 422 且错误明细在 `data.errors`。
- AC-104.3 既有分类接口用例（无父子场景）全部保持通过。

---

### CMS-105 [后端] 后台单页内容接口（schema + 保存）

| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 批次 B |
| 依赖 | CMS-102、CMS-104 |
| 产出文件 | 同 CMS-104 |
| 预估 | 1.0 人天 |

**目标**：单页字段的读写入口 —— 复用 article 表承载内容，不新增表。

**实现步骤**
1. `GET /admin/cs/faq/pages/{id}`：
   - 校验分类存在且 `type='page'`，否则 422（`data.errors` 说明原因）；
   - 返回 `{ category: {...}, template: {key,label,fields[]}, values: {...} }`；
   - `values` = 该分类下唯一 article 的 `page_fields` 与 schema default 合并结果（**后端合并**，前端不做默认值兜底）。
2. `PUT /admin/cs/faq/pages/{id}`：
   - body `{ fields: {...} }`；经 `CmsPageTemplate::filterPayload()` 过滤未知键；
   - 必填校验（`required=true` 且值为空 → 422，错误明细到字段级）；
   - 写入该分类下唯一 article（不存在则 `create`，`title` 取分类名、`status='published'`、`content_md=''`）；
   - ⚠️ 单页 article 的 `page_fields` 直接赋值，**不触发** markdown 渲染钩子（`content_md` 未 dirty 即可）。
3. 图片字段存 URL 字符串；`image_list` 存数组；`repeater` 存对象数组。

**测试要求**：取 schema 与默认值、保存后回读一致、未知键被丢弃、必填缺失 422、对 `type=channel` 的分类调单页接口 422。

**验收标准**
- AC-105.1 后台保存后 `GET /cms/pages/{slug}` 立即返回新内容。
- AC-105.2 单页 article 不污染文章列表（列表按 `type` 过滤或前端区分展示）。
- AC-105.3 未知字段不会落库。

---

### CMS-106 [后端] 前台公开 `/cms/*` 接口 + 帮助中心解除登录

| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 批次 B |
| 依赖 | CMS-105 |
| 产出文件 | `backend/app/Http/Controllers/CmsController.php`、`routes/api.php` |
| 预估 | 0.5 人天 |

**实现步骤**
1. 新建 `CmsController`（无鉴权中间件）：
   - `GET /api/cms/nav`：`show_in_nav=true` 且 `is_active=true` 的栏目树（只出 `id/name/slug/type/template/icon/children`）；
   - `GET /api/cms/categories?parent_id=0`：指定父下的栏目树（供帮助中心侧栏）；
   - `GET /api/cms/pages/{slug}`：`type='page'` 且 `is_active` 的分类；返回 `{ name, template, fields, updated_at }`；slug 不存在 → 404（`code 40400` 语义，按现有 `ApiResponse` 约定）。
2. **帮助中心解除登录**：把 `/cs/faq/categories`、`/cs/faq/articles`、`/cs/faq/articles/{id}` 三条路由**移出** `auth:sanctum` 分组到公开分组；`feedback`（有帮助反馈）保持需登录（写操作）。
3. 保持现有响应结构不变，避免前端三页返工。

**测试要求**：三个接口**未携带 token 可访问**；slug 不存在 404；非 page 分类不暴露；`show_in_nav=false` 不出现在 nav。

**验收标准**
- AC-106.1 未登录 `curl /api/cms/pages/about` 返回 200 与完整字段。
- AC-106.2 未登录 `curl /api/cs/faq/categories` 返回 200（登录限制已解除）。
- AC-106.3 `/cs/faq/articles/{id}/feedback` 未登录仍 401。

---

### CMS-107 [后端] 后端测试（分类树 / 单页 / 公开接口 / 模板守卫）

| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 批次 B |
| 依赖 | CMS-104 ~ CMS-106 |
| 产出文件 | `backend/tests/Unit/CmsPageTemplateTest.php`、`backend/tests/Feature/CmsCategoryTreeTest.php`、`CmsPageApiTest.php`、`CmsPublicApiTest.php` |
| 预估 | 1.0 人天 |

**实现步骤**
1. 四个测试文件，每文件 `uses(RefreshDatabase::class)`。
2. 覆盖矩阵见各任务"测试要求"。
3. ⚠️ **测试内定义全局函数必须全局唯一**（与既有测试文件重名会导致全量跑 fatal），建议前缀 `cmsLoginToken`。
4. ⚠️ 造单据注意既有约束（如需要真实订单的场景本任务不涉及）。

**验收标准**
- AC-107.1 新增用例全绿；后端全量回归无新增失败。
- AC-107.2 模板守卫用例能真实拦截"新增模板未写前台组件"的情况（可临时删组件验证）。

---

### CMS-108 [admin] 抽取 `MarkdownEditor` 复用组件

| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 批次 C |
| 依赖 | — |
| 产出文件 | `admin/src/components/MarkdownEditor.vue` |
| 预估 | 0.5 人天 |

**实现步骤**
1. 封装 `md-editor-v3`：props `modelValue`/`height`/`disabled`，emit `update:modelValue`；内置 `language="zh-CN"`、`toolbars-exclude=['github','save']`、`onUploadImg` → `uploadImage`。
2. 改造 `CsFaqView.vue` 与 `AnnouncementListView.vue` 使用该组件，删除两处重复 import。
3. 行为与现网一致（上传中提示、高度 420px 默认）。

**验收标准**
- AC-108.1 admin 内不再有第二处 `import { MdEditor } from 'md-editor-v3'`。
- AC-108.2 帮助中心与公告的编辑体验无回退（`vue-tsc -b` 0 错、既有测试全绿）。

---

### CMS-109 [admin] 内容管理页（左栏目树 + 右内容区）

| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 批次 C |
| 依赖 | CMS-104、CMS-105、CMS-108 |
| 产出文件 | `admin/src/views/cs/CsFaqView.vue` |
| 预估 | 2.0 人天 |

**实现步骤**
1. 布局：左侧栏目树（按 `level` 缩进、展开/收起），右侧内容区自动按选中栏目的 `type` 切换。
2. 栏目树操作：新增根栏目 / 新增子栏目 / 编辑 / 移动（选择新父）/ 启停 / 排序 / 删除（失败时展示后端 conflict 文案）。
3. `type=channel` → 现有文章管理表格（筛选、分页、新增、编辑、发布、下架、预览、删除）**原样保留**。
4. `type=page` → 渲染 `PageFieldForm`（CMS-110）。
5. 新增/编辑弹窗增加：类型选择（栏目/单页）、模板下拉（仅单页）、slug 输入（仅单页）、是否显示在导航。
6. 骨架沿用 `rounded-lg bg-white p-5 shadow-sm` + 主色 `#1677ff`。

**验收标准**
- AC-109.1 三层栏目树可正常展示与操作，移动后刷新顺序正确。
- AC-109.2 切换栏目类型右侧内容区正确切换，不串数据。
- AC-109.3 无 `cs.faq.manage` 权限时整体只读（沿用现有行为）。
- AC-109.4 每个关键交互带 `data-testid`，可被 vitest 覆盖。

---

### CMS-110 [admin] 单页字段表单（按 schema 动态渲染）

| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 批次 C |
| 依赖 | CMS-105、CMS-108 |
| 产出文件 | `admin/src/components/PageFieldForm.vue` |
| 预估 | 1.5 人天 |

**实现步骤**
1. props：`schema`（模板 fields）、`modelValue`（字段值对象）；emit `update:modelValue`。
2. 按 `type` 分发渲染：
   - `text` / `textarea` → 输入框；
   - `markdown` → `MarkdownEditor`；
   - `image` → 上传 + 预览 + 移除（复用站点 logo 上传接口，落 `uploads/site` 或通用上传接口）；
   - `image_list` → 多图上传 + 排序 + 删除；
   - `repeater` → 行列表，支持增/删/上移/下移，按 `item` 子 schema 渲染。
3. 每字段 `data-testid=page-field-{key}`，repeater 行 `page-field-{key}-row-{index}`。
4. 未知字段不渲染（schema 驱动，天然免疫脏数据）。

**验收标准**
- AC-110.1 六种字段类型均可用，值往返不丢。
- AC-110.2 repeater 增删行后保存、刷新回显一致。
- AC-110.3 `vue-tsc -b` 0 错。

---

### CMS-111 [admin] 菜单文案与 API 层

| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 批次 C |
| 依赖 | CMS-104 |
| 产出文件 | `admin/src/api/cms.ts`、`admin/src/layouts/AdminLayout.vue`、`admin/src/router/index.ts` |
| 预估 | 0.5 人天 |

**实现步骤**
1. 新增 `api/cms.ts`：分类树相关（`getCmsCategories`/`createCmsCategory`/`updateCmsCategory`/`moveCmsCategory`/`deleteCmsCategory`/`sortCmsCategories`）、单页相关（`getCmsPage`/`saveCmsPage`）+ TS 类型（`CmsCategory`/`CmsPageSchema`/`CmsPageField`）。
2. 菜单「帮助中心」→「内容管理」（`AdminLayout.vue` 的 `menuGroups` 与 `router/index.ts` 的 `meta.title` **两处同步**）；路径 `/cs/faq`、权限 `cs.faq.manage` **保持不变**。
3. ⚠️ 图标保持 lucide 原名（`sidebar-icon.test.ts` 的正则只认 `^\s*Name,` 形式，不要写 `Alias: Name`）。

**验收标准**
- AC-111.1 侧栏显示「内容管理」，点击进入改造后的页面。
- AC-111.2 `sidebar-icon.test.ts` 与路由相关测试全绿。

---

### CMS-112 [web] 单页容器 + 模板组件 + 路由 + 页脚接通

| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 批次 D |
| 依赖 | CMS-106 |
| 产出文件 | `web/src/api/cms.ts`、`web/src/views/PageView.vue`、`web/src/views/pages/PageAbout.vue`、`PageContact.vue`、`web/src/router/index.ts`、`web/src/components/ShopFooter.vue` |
| 预估 | 2.0 人天 |

**实现步骤**
1. 新建 `api/cms.ts`：`getCmsPage(slug)`、`getCmsNav()` + 类型。
2. `PageView.vue`：取路由 `params.slug` → 请求 `/cms/pages/{slug}` → 按 `template` 映射到模板组件（`about` → `PageAbout`、`contact` → `PageContact`）；`template` 未知或无数据 → 404 提示（含返回首页入口）；页面自带 `ShopHeader` / `ShopFooter`（沿用现有约定）。
3. 模板组件**版式预设计**，字段作 props 注入；markdown 字段用 `v-html` 渲染（后端已净化）；缺图/缺字段时优雅降级（不出现空壳）。
4. 路由：在 `/:pathMatch(.*)*` **之前**加
   `{ path: '/p/:slug', name: 'page', component: () => import('@/views/PageView.vue'), meta: { title: '<!-- 运行时按站点名替换 --> · CubeShop' } }`（meta.title 保留品牌占位符，用 `setTitleBase` 机制）。
5. `ShopFooter.vue`：三个 `<span>` 改为 `RouterLink` → `/p/about`、`/service-center/faq`、`/p/contact`。

**测试要求**：`PageView` 按 template 分发、未知 slug 走 404 分支、页脚三链接 href 正确。

**验收标准**
- AC-112.1 浏览器访问 `/p/about`（**未登录**）可见完整页面，字段与后台一致。
- AC-112.2 页脚「关于我们 / 帮助中心 / 联系客服」可点击并跳转正确。
- AC-112.3 `vue-tsc -b` 0 错，vitest 新增用例通过，构建成功。

---

## 7. 全局约定与风险

| # | 事项 | 约定 |
|---|------|------|
| R1 | 迁移号 | 从 `000095` 起；**必须手工在 pgsql 跑一次**，SQLite 由 Pest 自动迁移 |
| R2 | 富文本净化 | 唯一入口 `HtmlSanitizer::clean()/cleanHtml()`；前台 markdown 一律走现有 `MarkdownRenderer` 派生 |
| R3 | 单页身份标识 | 后台沿用 int id（与现有一致）；前台对外走 **slug**（不暴露 id） |
| R4 | 权限 | 不做任何权限码变更；`cs.faq.manage` 沿用 |
| R5 | 测试全局函数 | 新增测试文件的函数名必须全局唯一（否则全量跑 `Cannot redeclare` fatal） |
| R6 | 文档体例 | 结构变更须同步本文件；一期实施完成后回填各任务的验收结论 |
| R7 | 不做 | 不做区块拖拽、不做页面版本历史、不做多语言、不做公告并入（均属二期） |

---

## 8. 二期规划（本文件不实施）

| 编号 | 任务 | 说明 |
|------|------|------|
| CMS-201 | 帮助中心父子分类前台适配 | 侧栏栏目树 + 面包屑，改造 `FaqCategoryView` / `FaqListView` / `FaqDetailView` |
| CMS-202 | 单页 SEO 与 sitemap | `seo_title`/`keywords`/`description` 字段 + 动态 sitemap.xml |
| CMS-203 | 区块化编辑器 | 自由拖拽区块（hero / 图文 / 图集 / FAQ 嵌入），取代固定模板 |
| CMS-204 | 公告并入 CMS | `cs_announcement` 迁为 `type=channel` 栏目，统一管理入口 |

---

## 9. 验收清单（阶段出口）

- [x] AC-101 ~ AC-112 逐项通过（结论见 §10）
- [x] 后端全量 `php -d memory_limit=1G vendor/bin/pest` 无新增失败
- [x] `admin` / `web` 各自 `vue-tsc -b` 0 错、vitest 全绿、build 通过
- [x] 手工验证：后台建三层栏目 → 建单页 → 改字段 → 前台 `/p/about` 刷新即见（未登录）
- [x] 手工验证：页脚三个入口可点击跳转

---

## 10. 实施结论（2026-09-20 回填）

### 10.1 逐项验收结论

| 任务 | 结论 | 证据 / 备注 |
|------|------|-------------|
| CMS-101 | ✅ | 迁移 `2026_09_20_000095`；SQLite（Pest 自动）与 pgsql 均通过，`migrate:rollback --step=1` 后重跑幂等 |
| CMS-102 | ✅ | `CmsPageTemplate` + `tests/Unit/CmsPageTemplateTest.php` 8 例（含 T08 守卫） |
| CMS-103 | ✅ | `CmsCategoryService` + `tests/Feature/CmsCategoryTreeTest.php` |
| CMS-104 | ✅ | `CsFaqController` 栏目接口扩展；同父唯一、`type=page` 必填 slug/template |
| CMS-105 | ✅ | `showPage` / `savePage`；unknown key 静默丢弃、必填 422 |
| CMS-106 | ✅ | `CmsController` 三个公开接口；FAQ 三读接口移出 `auth:sanctum` |
| CMS-107 | ✅ | `CmsPageTemplateTest`(8) + `CmsCategoryTreeTest` + `CmsPageApiTest`(12) 全绿 |
| CMS-108 | ✅ | `MarkdownEditor.vue`；admin 内无第二处 `import { MdEditor }` |
| CMS-109 | ✅ | `CsFaqView.vue` 改为「左栏目树 + 右内容区」；`admin/tests/cs-faq.test.ts` 19 例 |
| CMS-110 | ✅ | `PageFieldForm.vue` 六种字段类型（text/textarea/markdown/image/image_list/repeater） |
| CMS-111 | ✅ | 菜单/路由文案「帮助中心」→「内容管理」，路径与权限不变；`api/cs.ts` 扩展完成 |
| CMS-112 | ✅ | `web/src/api/cms.ts`、`PageView.vue`、`views/pages/Page{About,Contact}.vue`、`/p/:slug` 路由、页脚三链接 |

### 10.2 计划外增补（实施中发现）

| # | 事项 | 说明 |
|---|------|------|
| A1 | **单页 markdown 字段的后端渲染** | 原设计文档称「markdown 字段后端已净化」，实际 `page_fields` 存的是**源**。已在 `CmsController::page()` 补 `html` 字段（`MarkdownRenderer` 渲染 → `HtmlSanitizer` 净化），前端不引入 markdown 依赖，安全边界仍只落在 `HtmlSanitizer`。 |
| A2 | **CMS-102 守卫用例落地** | T08 用正则扫 `web/src/views/pages/Page*.vue` 与 `templateKeys()` 比对（体例同 admin `sidebar-icon.test.ts`）。 |
| A3 | **既有测试同步** | `CsTicketStateMachineTest`（分类总数 5→5 channel + 2 page）、admin `cs-ticket`/`router-landing`（菜单文案）随 CMS-101/111 同步；`CsFaqApiTest`/`CsPermissionMatrixTest` 随决策 D4 同步。 |
| A4 | **帮助中心前台路由解除登录** | 后端接口转公开后，前端三条 faq 路由的 `requiresAuth` 一并移除，否则未登录点页脚「帮助中心」会被弹回登录页（D4 的完整闭环）。 |

### 10.3 遗留与二期衔接

- 帮助中心**侧栏栏目树 / 面包屑**仍按平铺渲染（CMS-201）。
- 单页 SEO（`seo_title`/`keywords`/`description` + sitemap）未做（CMS-202）。
- 公告未并入 CMS（D5 / CMS-204）。
- 单页模板为**固定版式 + 字段**，非区块拖拽（R7 / CMS-203）。
