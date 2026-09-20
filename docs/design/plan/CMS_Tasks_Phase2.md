# 内容中心 CMS 二期任务清单（CMS-201 ~ CMS-204）

> 上游：`docs/design/plan/CMS_Tasks.md`（一期，CMS-101 ~ CMS-112 已交付）
> 开工日期：2026-09-20
> 阶段出口：CMS-204 验收通过 —— 帮助中心前台成树、单页具备 SEO 与 sitemap、
> 单页可用区块自由编排、公告并入内容管理统一维护。

---

## 1. 与一期的衔接

二期**全部复用一期底座，不新建任何表**（延续「原地演进」决策）：

| 一期已有能力 | 二期怎么用 |
|---|---|
| `cs_faq_category`：`parent_id`/`level`/`path`/`type`/`slug`/`template`/`show_in_nav` | CMS-201 出树；CMS-202 挂 SEO 三列；CMS-203 加 `blocks` 模板 key；CMS-204 新增 `type=channel` 的「公告」栏目 |
| `cs_faq_article`：`page_fields`(JSON)/`cover_image`/`is_hot`/`published_at` | CMS-203 加 `blocks`(JSON)；CMS-204 承接 `cs_announcement` 存量行 |
| `App\Support\CmsPageTemplate`（模板 → 字段 schema 真源） | CMS-202/203 抽出字段工具；CMS-203 与之并列新增 `CmsBlock` 真源 |
| `App\Services\Cms\CmsCategoryService`（树逻辑唯一入口） | CMS-201 复用 `tree()`；CMS-204 复用 `create()` |
| `web/src/views/pages/Page*.vue` 自动装配 + 守卫测试 | CMS-203 用同一体例新增 `views/blocks/Block*.vue` 守卫 |
| 公开 `/api/cms/*` | CMS-201/202/203 的出口都在这里 |

**迁移号**：一期用到 `000095`，二期从 `000096` 起连续编号（`000096` SEO、`000097` 区块、`000098` 公告）。

---

## 2. 设计决策（本轮拍板）

| 编号 | 决策点 | 候选 | 结论 |
|---|---|---|---|
| D6 | CMS-203 与固定模板的关系 | ① 新增 `blocks` 列，`template=blocks` 走区块，其余模板原样保留<br>② 区块彻底取代模板（about/contact 转成区块预设，删掉固定模板渲染） | **①**（已拍板） |
| D7 | CMS-203 拖拽实现 | ① 原生 HTML5 drag & drop，零新依赖<br>② 引入 `vuedraggable` / `sortablejs` | **①**（已拍板） |
| D8 | CMS-202 sitemap 出口 | ① 后端 `GET /sitemap.xml`（`routes/web.php`），`robots.txt` 加 Sitemap 行<br>② 前端 web 侧产出静态文件 | **①**（已拍板） |
| D9 | CMS-204 并入程度 | ① 软并入：数据迁入「公告」栏目，公开接口契约不变（前端零改动），后台移除公告菜单<br>② 彻底并入：废掉 `/announcements` 接口，前端改走 `/cs/faq/articles`<br>③ 只统一入口、数据不迁 | **①**（已拍板） |
| D10 | CMS-201 栏目树落点 | 分类页渲染两级栏目卡；列表页左侧栏目树侧栏 + 面包屑 | 已定（无需确认） |
| D11 | 跨页面共享的栏目树 | 收敛到 Pinia store（`stores/faq.ts`），勿在各视图各自请求 | 已定（沿用既有约定） |

---

## 3. 数据模型增量

### `cs_faq_category`（迁移 `000096`）

| 列 | 类型 | 说明 |
|---|---|---|
| `seo_title` | string(128) nullable | 浏览器标题 / `<title>`，空则回落栏目名 |
| `seo_keywords` | string(255) nullable | `<meta name="keywords">` |
| `seo_description` | string(255) nullable | `<meta name="description">` |

### `cs_faq_article`（迁移 `000097`）

| 列 | 类型 | 说明 |
|---|---|---|
| `blocks` | json nullable | 区块化正文（有序数组），`page_fields` 的并列能力 |

### 迁移 `000098`（CMS-204）

- 播种 `type=channel` 栏目「公告」（`sort` 靠后，`show_in_nav=false`）。
- 把 `cs_announcement` 存量行拷入 `cs_faq_article`（挂在「公告」栏目下）：
  `title`/`content`/`content_md`/`published_at`/`status` 直传，`is_top → is_hot`。
- ⚠️ 幂等：仅当「公告」栏目下**尚无文章**时执行拷贝；`down()` 只回滚「拷贝进来的行 + 栏目」，
  **不动 `cs_announcement` 原表**（原表保留，作为可回退的存档）。

---

## 4. 任务明细

### CMS-201 [web+后端] 帮助中心父子分类前台适配

**后端**
- `FaqService::categories()`：由「平铺激活栏目」改为**返回带 `children` 的树**，
  仍只出 `type=channel`（单页不进帮助中心导航），并附带 `parent_id`/`level`。
- ⚠️ 契约是**兼容式扩展**（原字段 `id/name/sort/published_count` 全部保留），
  现有前端不会 500；新前端按 `children` 递归渲染。
- 面包屑**不加接口**：树已在手，链路在前端本地算（少一次请求、避免两级数据不一致）。

**web**
- 新增 `stores/faq.ts`（D11）：`loadTree()` 带缓存、`breadcrumbOf(categoryId)` 返回
  `根→…→当前` 的链路、`childrenOf(parentId)`。
- `FaqCategoryView.vue`：一级栏目卡 + 其下子栏目 pill（两级；三级及以上在列表页侧栏展开）。
- `FaqListView.vue`：左侧栏目树侧栏（`lg:` 显示，窄屏折叠为顶部下拉），面包屑按链路渲染。
- `FaqDetailView.vue`：面包屑按链路渲染，末级为文章标题。

**验收**：AC-201.1 分类页可见父子层级；AC-201.2 「服务中心 > 帮助中心 > 父栏目 > 栏目」链路正确；
AC-201.3 未登录可访问（延续 D4）；AC-201.4 单页栏目不出现在帮助中心树里。

---

### CMS-202 [后端+web] 单页 SEO 与 sitemap

**后端**
- 迁移 `000096` 加 `seo_*` 三列；`CsFaqCategory` `fillable` 扩展。
- 后台栏目接口：`type=page` 时接受 `seo_title`/`seo_keywords`/`seo_description`
  （`nullable|string|max`，**禁 `required`** —— 与系统配置同一教训：清空必须能过）。
- 公开 `GET /api/cms/pages/{slug}` 响应增加 `seo` 对象（已做空值回落：`title` 兜栏目名）。
- sitemap（按 D8）：列出
  - 静态核心页：`/`、`/service-center/faq`、`/announcements`、`/coupons/center`
  - 已发布帮助文章：`/service-center/faq/{id}`（`CsFaqArticle` 对外就是 int id）
  - 已启用单页：`/p/{slug}`
  - `lastmod` 取各自 `updated_at`；XML 走 Blade 视图或字符串拼装，设 `Content-Type: application/xml`。
- `backend/public/robots.txt` 增加 `Sitemap:` 行。

**web**
- 新增 `composables/useSeo.ts`：`applySeo({title, description, keywords})` + `resetSeo()`。
- ⚠️ **必须配套 reset**：`router.afterEach` 现有逻辑只在 `beforeEach` 之后重设 `document.title`，
  meta 标签若不重置会**残留上一页的 description**（SPA 常见坑）。守卫里统一 `resetSeo()` 再落地页面自己的 SEO。
- `PageView.vue` 用后端 `seo` 调 `applySeo()`；`FaqListView`/`FaqDetailView` 用栏目名 + 文章摘要兜底。

**验收**：AC-202.1 后台可填可清空三个 SEO 字段；AC-202.2 `/p/about` 的 title/description/keywords
随数据变化；AC-202.3 切走页面后 meta 不残留；AC-202.4 `/sitemap.xml` 返回合法 XML 且含已发布内容。

---

### CMS-203 [后端+admin+web] 区块化编辑器

**真源**：新增 `App\Support\CmsBlock`（与 `CmsPageTemplate` 并列）
- 区块类型白名单 `hero` / `text_image` / `gallery` / `rich_text` / `faq_embed`，各带
  字段 schema（沿用现有六种字段类型）。
- ⚠️ 先把 `CmsPageTemplate` 里的字段工具（`emptyValueOf`/`defaultsOf`/`filterPayload`/`rules`）
  抽到共用 `App\Support\CmsField`，两处真源复用一份 —— 避免第二份复制的校验逻辑漂移。

**后端**
- 迁移 `000097` 加 `cs_faq_article.blocks`(json)。
- `template` 新增 key `blocks`（"自由区块"）—— 配套 `web/src/views/pages/PageBlocks.vue`，
  因此一期守卫测试 TC-CMS-T08 天然继续成立。
- 保存：`blocks` 逐项校验（`type` 白名单 + `data` 按该块 schema 过滤），未知块类型**整条丢弃**
  （与字段的"未知键静默丢弃"同口径），每类块数量上限（防单页塞几百块）。
- 公开 `/cms/pages/{slug}`：
  - `blocks` 下发**源**（供后台回显）；
  - 额外下发 `rendered_blocks`：markdown 字段渲染成净化 HTML；
  - `faq_embed` 由后端**注入** `resolved_items`（该栏目已发布文章 ≤ limit），前端零请求。

**admin**
- 新增 `components/PageBlockEditor.vue`：区块列表 + 拖拽/上下移排序 + 增删 + 选类型插入；
  每块的字段表单**直接复用 `PageFieldForm`**（传该块 schema + `v-model=block.data`），不写第二套渲染器。
- `CsFaqView.vue`：单页栏目的 `template=blocks` 时渲染 `PageBlockEditor`，否则维持 `PageFieldForm`。

**web**
- 新增 `views/pages/PageBlocks.vue`（渲染器）+ `views/blocks/Block{Hero,TextImage,Gallery,RichText,FaqEmbed}.vue`；
  用 `import.meta.glob('./blocks/Block*.vue')` 自动装配（同一期体例）。
- 守卫测试：`CmsBlockTest` 扫 `web/src/views/blocks/Block*.vue` 与 `CmsBlock::blockKeys()` 比对。

**验收**：AC-203.1 后台可增删/拖拽排序区块；AC-203.2 五种区块前台渲染正确、缺字段优雅降级；
AC-203.3 `about`/`contact` 两个存量单页**零变化**（向后兼容）；AC-203.4 守卫测试拦截漏写的区块组件。

---

### CMS-204 [后端+admin+web] 公告并入 CMS

按 D9 结论实施。推荐方案（①）的落点：

**后端**
- 迁移 `000098` 播种「公告」栏目 + 拷贝存量（见 §3）。
- `AnnouncementController`（公开）改为**读「公告」栏目下的已发布文章**，
  ⚠️ **响应契约一字不改**（`id/ title/ is_top/ published_at/ summary`、详情含 `content`）
  —— 前端与首页公告位零改动；`is_top` 由 `is_hot` 映射。
- 后台 `Admin\AnnouncementController` 的四个写接口标记废弃（保留路由一个版本，避免已发布前端 404），
  实际维护统一走 `/api/admin/cs/faq/*`。

**admin**
- 侧栏移除「公告管理」；`AnnouncementListView.vue` 保留文件但路由摘除（与"内容管理统一入口"对齐）。
- 内容管理页的公告栏目里，文章的「热门」在**公告语境下即置顶** —— 文章表单的标签按栏目类型
  动态显示（公告栏目显示「置顶」）。

**web**
- `/announcements`、`/announcements/:id` 路由与视图**不动**（接口契约不变）。

**验收**：AC-204.1 存量公告在内容管理「公告」栏目可见且可编辑；AC-204.2 首页公告位与公告页
展示与迁移前一致；AC-204.3 后台不再有独立公告菜单；AC-204.4 迁移可回滚（原表未被破坏）。

---

## 5. 全局约定与风险

| # | 事项 | 约定 |
|---|---|---|
| R1 | 迁移号 | 从 `000096` 起；SQLite 由 Pest 自动迁移，**必须手工在 pgsql 跑一次** |
| R2 | 净化 | 唯一入口 `HtmlSanitizer`；markdown → HTML 一律 `MarkdownRenderer` 派生（含区块内 markdown） |
| R3 | 真源模式 | 区块类型 → `App\Support\CmsBlock`；字段工具 → `App\Support\CmsField`；**禁止旁路硬编码** |
| R4 | 权限 | 不新增权限码；`cs.faq.manage` 继续承载 CMS 全部后台操作 |
| R5 | 测试全局函数 | 新增测试文件的函数名必须全局唯一（否则全量 `Cannot redeclare` fatal） |
| R6 | 向后兼容 | `about`/`contact` 存量单页、`/announcements` 公开契约、帮助中心旧字段三处**零破坏** |
| R7 | 不做 | 不做页面版本历史、不做多语言、不做拖拽嵌套容器、不做区块级权限 |

---

## 6. 验收清单（阶段出口）

- [x] AC-201 ~ AC-204 逐项通过
- [x] 后端全量 `php -d memory_limit=1G vendor/bin/pest` 无新增失败（**1367 passed / 0 failed**）
- [x] `admin` / `web` 各自 `vue-tsc -b` 0 错、vitest 全绿、build 通过
- [x] curl 端到端：帮助中心树 / `/p/about` 的 SEO / `/sitemap.xml` / 公告列表（未登录）
- [x] 迁移在 pgsql 上跑通且可回滚（000098 已 `rollback --step=1` → `migrate` 实测，原表未被破坏）

> vitest 的两处「并发 flaky」（admin `home-recommended`、web `account-center`）均为全量并发下
> jsdom 负载导致的超时，单跑稳定通过；与二期改动无关，未在本轮处理。

---

## 7. 实施结论

> 体例同一期 §10：逐项结论 + 计划外增补 + 遗留。

### 7.1 逐项结论

| 编号 | 交付 | 落点 | 验收 |
|---|---|---|---|
| CMS-201 | 帮助中心父子分类前台 | 后端 `FaqService::categories()` 出树（字段裁剪 + `children`）；web `stores/faq.ts` + `FaqBreadcrumb.vue` + 三个视图改造 | AC-201.1~.4 ✅ |
| CMS-202 | 单页 SEO 与 sitemap | 迁移 `000096`；`useSeo` / `applySeo` / `resetSeo`；`SitemapController` + `routes/seo.php`；admin 栏目弹窗 SEO 表单 | AC-202.1~.4 ✅ |
| CMS-203 | 区块化单页 | 迁移 `000097`；`CmsField` / `CmsBlock` 真源；admin `PageBlockEditor.vue`（原生拖拽）；web `PageBlocks.vue` + 5 个 `Block*.vue` | AC-203.1~.4 ✅ |
| CMS-204 | 公告并入 | 迁移 `000098`；公开 `AnnouncementController` 改读「公告」栏目（契约不变）；admin 移除公告菜单 + 公告语境显示「置顶」 | AC-204.1~.4 ✅ |

**测试增量**：后端新增 `CmsBlockTest`（12）、`CmsAnnouncementMergeTest`（10）、`CmsPageApiTest` 扩 T45~T51、
`SitemapTest`（7）、`CmsCategoryTreeTest`；admin 新增 `cs-faq-blocks.test.ts`（8）+ `cs-faq.test.ts` 扩 2；
web 新增 `cms-blocks.test.ts`（6）+ `seo.test.ts`（6）。

### 7.2 计划外增补（实施中发现的必要项）

1. **抽出 `App\Support\CmsField`**：区块与固定模板需要同一套「schema → 默认值 / 过滤 / 校验规则」，
   复制第二份必然漂移。`CmsPageTemplate` 的四个方法改为委托，常量保留别名（标 `@deprecated`），一期调用点零改动。
2. **单页模板 props 契约统一**：`PageView` 向所有模板组件统一下发 `name/fields/html/blocks`。
   `PageAbout`/`PageContact` 因此也要声明 `blocks?` —— 否则未声明的 prop 会变成根元素上的
   `blocks="[object Object]"` 垃圾属性（透传属性优先级高于根元素自带属性，同一期 MarkdownEditor 的教训）。
3. **「公告」承载栏目必须从用户端类目与 sitemap 摘除**（计划只写了 `show_in_nav=false`，漏了这一层）：
   公告栏目的 `type` 必须是 `channel`（不然挂不了文章），于是它会自然混进
   `/api/cs/faq/categories`、`/api/cms/categories`（用户在帮助中心看到「公告」类目）与
   `/sitemap.xml`（同一内容两个 URL）。
   落点：`CsFaqCategory::announcementCarrierId()` 作为唯一取法，
   树走新加的 `CmsCategoryService::tree(['exclude_ids' => …])`，sitemap 在 `whereHas` 里排除。
4. **公告对外 `id` 的语义变化**：由 `cs_announcement.public_id`（ULID）改为文章数值 id 的字符串形式。
   字段名/类型不变、前端只回传列表里拿到的值，故调用方零改动；仅并入前的旧链接会 404（页面有兜底提示）。
   刻意不给 `cs_faq_article` 加 `public_id`：那是为一列废弃模块给核心表加列 + 回填，收益不抵风险。
5. **SEO 端点改挂独立路由文件**（`routes/seo.php`，由 `bootstrap/app.php` 的 `then:` 注册）：
   `routes/web.php` 的路由会自动套 `web` 中间件组，而该组要启动数据库会话 ——
   本项目纯 API 后端（`SESSION_DRIVER=database`）并没有 `sessions` 表，`/sitemap.xml` 与
   `/robots.txt` 实测 500。抓取请求本就不需要会话，剥离后 200。
6. **守卫面扩展**：`form-focus-style.test.ts` 扫描目标加 `PageBlockEditor.vue`；
   `CmsPageTemplateTest` TC-CMS-T01 跳过 `blocks` 模板（它的字段 schema 刻意为空）；
   `CsTicketStateMachineTest` 的两条 Seeder 计数按「排除公告承载栏目」重新表述。

### 7.3 遗留（明确不在本轮）

1. **公告承载栏目靠名字锚定**：后台改名等价于清空前台公告位（迁移、`AnnouncementController`、
   `CsFaqCategory::announcementCarrierId()` 三处同口径）。已在代码注释里写明这是刻意的 ——
   与其猜一个 id，不如让改名有一个明确可观察的后果。若将来要防呆，应加「系统栏目」标记列。
2. **旧公告后台写接口保留一个版本**：`Admin\AnnouncementController` 已标 `@deprecated`，
   它写的是 `cs_announcement` 原表、**不会**出现在前台，`AnnouncementApiTest` 已把这一后果固化为断言。
   下个版本连同 `AnnouncementListView.vue` 一并删除。
3. **并入前的公告 ULID 旧链接不兼容**（见 7.2-4）。
4. **sitemap 不含商品/分类**：量级大，应走 sitemap index + 分片。
5. **一期遗留的聚焦描边**：`CsTicketView.vue` 指派下拉、`LoginView.vue`(4)、
   `BatchShipView.vue`(1)、`WmsApiLogView.vue`(6) 仍未加 `focus:border-[#1677ff]`（本次未动）。
