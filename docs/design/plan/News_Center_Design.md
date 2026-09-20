# 新闻中心设计方案（News Center）

> 目标：后台「内容管理」里新增**新闻中心**大分类；前台有**独立设计的新闻中心页**，页内分
> 「图文新闻 / 列表新闻」两个小分类，点标题进详情页；服务于**站点 SEO** 与**种草文章**。
>
> 状态：**已拍板，待实施**（决策结论见 §5）。本文只做方案，未实施。

---

## 1. 需求拆解

| # | 需求 | 落到系统的能力 |
|---|------|----------------|
| N1 | 后台内容管理有「新闻中心」大分类 | 栏目树里一个根栏目 + 子栏目 |
| N2 | 单独设计的新闻中心页面 | 前台 `/news` 独立页面（不是帮助中心的皮肤） |
| N3 | 页面内小分类：图文新闻 / 列表新闻 | 两个子栏目，且**两种展示形态**（卡片 / 纯列表） |
| N4 | 点标题进新闻详情页 | 前台 `/news/{id}` 详情页（正文 + 相关新闻） |
| N5 | SEO / 种草文章 | meta 三要素、sitemap 收录、稳定的 URL、封面图 |

---

## 2. 现状盘点（能白嫖多少）

| 能力 | 现成资产 | 复用方式 |
|------|----------|----------|
| 栏目树 | `cs_faq_category`（type=channel/page，parent_id + path 物化路径，两级以上也支持） | 新闻中心 = channel 根栏目 + 2 个 channel 子栏目 |
| 文章 | `cs_faq_article`（title / summary / content_md / content / **cover_image** / is_hot / sort / status / published_at / view_count） | 新闻 = 一篇文章，字段完全够用 |
| 正文编辑 | md-editor-v3 + `MarkdownRenderer` + `HtmlSanitizer`，落库即净化 HTML | 零改动 |
| 图片上传 | `POST /admin/cs/faq/upload`（权限 cs.faq.manage） | 复用 |
| 公开列表 | `GET /api/cs/faq/articles?category_id=&keyword=&page=&per_page=`（分页，已解除登录） | Service 层可复用 |
| 公开详情 | `GET /api/cs/faq/articles/{id}`（全文 + 同栏目 related + 浏览量自增） | Service 层可复用 |
| 栏目树接口 | `GET /api/cms/categories?parent_id=`、`GET /api/cs/faq/categories` | 复用取子栏目 |
| SEO | 栏目级 `seo_title/keywords/description` + 前台 `useSeo.applySeo()` + `router.afterEach(resetSeo)` | 列表页用栏目 SEO，详情页用文章标题/摘要回落 |
| sitemap | `SitemapController` 已产出静态页 + `/service-center/faq/{id}` + `/p/{slug}` | 增加 `/news/{id}` 分支 |
| 前台入口 | 后台「导航管理」（nav.manage）可加自定义链接条目 | 加 `/news` 条目，零代码 |
| 首页挂载 | 单页区块 `faq_embed` 可列出指定栏目文章 | 首页挂「最新资讯」零后端代码 |

### 缺口（必须补的）

| 缺口 | 影响 | 补法 |
|------|------|------|
| 文章**没有 slug** | URL 只能是 `/news/{id}` | 一期用 id；要语义化再补 slug 列（见 D5） |
| 文章**没有 seo 三列** | 详情页 meta 只能用标题 + summary 回落 | 一期回落够用；精细化再补列（见 D4） |
| 后台文章**没有封面图 UI**（`cover_image` 列存在但无表单/校验） | 图文新闻的卡片图没地方配 | 后台文章表单加封面上传 + 接口加校验（约 20 行） |
| 栏目**没有「列表形态」标记** | 图文/列表两种渲染无从区分 | 加 `list_style` 列（card/list，见 D2） |
| sitemap 只认 FAQ URL | 新闻文章进不了 sitemap | 按栏目子树分流（见 §6） |
| 帮助中心会**混入新闻栏目** | 侧栏/列表出现「新闻中心」，同一文章两个 URL | 三处排除（见 §6） |
| 前台没有 `/news` 页面 | — | 新增 2 个页面 + 路由（约 400 行，可照 FaqList/Detail 改） |

---

## 3. 三个方案对比

| 维度 | **A. 复用 CMS（推荐）** | B. 独立 news 表 | C. 单页 + 区块（零后端） |
|------|------------------------|----------------|--------------------------|
| 做法 | 栏目树 + 文章表，前台新增 `/news` 页面与 `/api/news/*` 薄接口 | 新表 `news_posts` + 独立后台管理页 + 独立接口 + 前台页面 | 新闻中心做成一个「单页」，用 `faq_embed` 区块列文章 |
| 后端工作量 | 小（1~2 个迁移 + 1 个 Controller + sitemap 分流） | 大（表 + CRUD + 权限 + SEO + 上传，且重复一遍 markdown/净化/SEO 逻辑） | 零 |
| 后台工作量 | 小（栏目形态下拉 + 文章封面） | 大（整套管理页） | 零 |
| 前台工作量 | 中（2 页面 + 路由 + 接口封装） | 中（同左） | 小（复用 PageView） |
| 列表能力 | 分页 / 关键词 / 排序 / 热门，全套现成 | 要自己写一遍 | ❌ 区块只取前 N 条，**无分页** |
| 详情页 URL | `/news/{id}`，独立、干净 | `/news/{id}` | ❌ 只能复用 `/service-center/faq/{id}`（与帮助中心混，SEO 不友好） |
| 两种展示形态 | 子栏目 `list_style` 决定，运营可切 | 同 | ❌ 做不到（区块无 tab 概念） |
| 长期维护 | 与帮助中心共用一套编辑器/净化/SEO | 两套并存，容易漂移 | 受限于区块能力，需求一变就要改代码 |
| 主要风险 | 与帮助中心共表，**必须做排除/分流**（§6） | 工作量大、重复建设 | 列表与 SEO 先天不足 |

**结论：选 A。** 需求（列表分页 / 详情 / 两种形态 / SEO）刚好落在 CMS 已有能力的交集里，缺的只是
「一个形态字段 + 一个封面上传 + 两个前台页面 + sitemap 分流」。

---

## 4. 推荐方案细节

### 4.1 数据结构（只加 1 列）

```
cs_faq_category
  └ 新闻中心            type=channel, slug=news,      parent_id=0, list_style=list
      ├ 图文新闻        type=channel, slug=news-graphic, list_style=card
      └ 列表新闻        type=channel, slug=news-list,    list_style=list

cs_faq_article  → 新闻文章（category_id 指向上面两个子栏目）
  标题=title / 摘要=summary / 正文=content_md / 封面=cover_image / 置顶=is_hot+sort
```

- 新增列：`cs_faq_category.list_style`（`varchar(16)` 可空，取值 `card` / `list`，默认 `list`）。
  ⚠️ 不用现成的 `template` 列 —— 那是**单页模板**语义，复用它会让两个概念互相污染。
- 真源常量：`App\Support\CmsListStyle`（card/list + 中文标签 + 默认值），照 `CmsPageTemplate` 的模式，
  并配守卫测试（改动即校验，防止前后台取值漂移）。
- 锚定：常量 `CsFaqCategory::NEWS_SLUG = 'news'`，`NewsService::root()` **按 slug 找、按 name 兜底**
  （与「公告」按名字锚定是同一套路）；后台对新闻中心根栏目**锁定 slug 不可改**，避免运营改坏。

### 4.2 接口（前台只依赖 `/api/news/*`，与帮助中心解耦）

| 接口 | 说明 | 实现 |
|------|------|------|
| `GET /api/news/channels` | 新闻中心根栏目（名称/SEO）+ 子栏目列表（id/name/slug/list_style/已发布文章数） | 新增 `Storefront\NewsController@channels` |
| `GET /api/news/articles?channel_id=&page=&per_page=` | 分页列表，**字段裁剪**（id/title/summary/cover_image/published_at/view_count/channel_id） | 复用 `FaqService::articles()`，出口裁剪，不带正文 |
| `GET /api/news/articles/{id}` | 详情（全文 HTML + 封面 + 同栏目相关新闻 + 浏览量自增） | 复用 `FaqService::detail()` |

- 全部**公开无鉴权**（新闻必须未登录可看，且要被搜索引擎抓）。
- 走独立 `/api/news/*` 而不是让前端直接打 `/api/cs/faq/*` 的理由：语义干净、可裁剪字段，
  将来真要拆独立表时前端零改动。

### 4.3 前台页面（web）

| 路由 | 组件 | 内容 |
|------|------|------|
| `/news` | `NewsListView.vue` | 页头（栏目名 + SEO 描述）→ 子栏目 tab（图文新闻 / 列表新闻）→ 列表（card 型：封面 + 标题 + 摘要 + 日期；list 型：标题 + 日期紧凑行）→ 分页 → 空态 |
| `/news/:id` | `NewsDetailView.vue` | 封面 → 标题 / 发布时间 / 浏览量 → 正文（`.cms-prose` + v-html）→ 相关新闻 → 面包屑回列表 |

- 路由**必须放在 catch-all 之前**（与 `/p/:slug` 同理）。
- 蓝本：`FaqListView.vue`（254 行）、`FaqDetailView.vue`（252 行）—— 结构照抄，换皮肤与接口。
- SEO：`applySeo({ title, description })`；详情页 title = 文章标题 + 站点名后缀，description 回落 summary；
  `router.afterEach(resetSeo)` 已存在，无需再动。
- 入口：后台「导航管理」加一条 custom 条目 `新闻中心 → /news`（**零代码**，运营自己配）。

### 4.4 后台（admin）改动

1. 栏目表单加「列表形态」下拉（card=图文卡片 / list=列表行），仅 `type=channel` 时显示。
2. 文章表单加「封面图」上传（复用 `/admin/cs/faq/upload`），卡片型栏目必填提示。
3. 文章列表可加「所属栏目」筛选（可选，帮助中心已有）。
4. 权限：仍用 `cs.faq.manage`（CMS 全部操作统一这一个权限码，**不新增**）。

### 4.5 SEO 与 sitemap

- sitemap 增加新闻分支：`/news/{id}`（lastmod = `updated_at`）。
- **分流规则**（⚠️ 关键，否则同一篇文章两个 URL）：
  - 文章所属栏目在**新闻中心子树**（`path` 以 `/{newsRootId}/` 开头）→ 只出 `/news/{id}`；
  - 其余已发布文章 → 保持 `/service-center/faq/{id}`；
  - 公告承载栏目继续排除。
- 列表页 `/news` 也可以进 sitemap（静态页清单里加一项）。

### 4.6 影响面排除清单（必须做，否则串台）

| 位置 | 现状 | 处理 |
|------|------|------|
| `GET /api/cs/faq/categories`（帮助中心侧栏） | 返回全部根栏目 | 排除新闻中心根（与排除「公告」同款 `exclude_ids`） |
| `GET /api/cs/faq/articles`（帮助中心列表/搜索） | 搜得到新闻文章 | 排除新闻子树（keyword 搜索同理） |
| `GET /api/cms/categories`（帮助中心侧栏另一路） | 同上 | 同上 |
| `SitemapController` | 只出 FAQ URL | 按 §4.5 分流 |
| 单页区块 `faq_embed` | 可挂任意栏目 | 保持可用（首页挂新闻正是要这个能力） |

---

## 5. 决策结论（已拍板 2026-09-21）

| # | 问题 | 结论 |
|---|------|------|
| D1 | 数据载体 | **A 复用 CMS**：新闻中心 = 栏目树根，图文/列表 = 子栏目，新闻 = 文章 |
| D2 | 图文/列表形态怎么存 | **A 新增 `list_style` 列**（card / list），运营可在后台切 |
| D3 | 封面图 | **A 后台文章表单加封面上传**（`cover_image` 列已存在，补 UI + 校验） |
| D4 | 文章级 SEO 三列 | **B 一期不加**：title 用文章标题 + 站点名，description 回落 summary |
| D5 | 详情页 URL | **A `/news/{id}`**，与帮助中心一致，零迁移（slug 二期再说） |
| D6 | 前台接口 | **A 新增 `/api/news/*`** 薄封装（channels / articles / detail），可裁剪字段 |
| D7 | 首页挂最新资讯 | **A 挂**：用现成 `faq_embed` 区块，零后端代码，运营后台自配 |
| D8 | 附加项（本轮新增） | **上一篇/下一篇导航** + **详情页 JSON-LD（Article 结构化数据）** 一并做 |

### 由此新增的两项设计

**上一篇 / 下一篇**
- `GET /api/news/articles/{id}` 的响应里多带 `prev` / `next`（各含 id + title，没有则为 null）。
- 排序口径与列表保持一致：`is_hot desc, sort asc, id desc`；按该序取紧邻的一条（
  上一篇 = 序在前的那条，下一篇 = 序在后的那条），只在**同栏目**内取，跨栏目不串。
- 实现：`FaqService::detail()` 新增可选的邻居查询（帮助中心详情页不传就不查，零影响）。

**详情页 JSON-LD（Article）**
- 前端在详情页注入 `<script type="application/ld+json">`：
  `@type: Article`、`headline`（标题）、`datePublished`（published_at）、`dateModified`（updated_at）、
  `image`（cover_image，无则省略该字段）、`description`（summary）、`author`/`publisher`（站点名）。
- ⚠️ 由 `useSeo` 的同学生命周期管理：路由离开时移除该 script 节点，避免跨页残留
  （与 `resetSeo()` 同源，放在同一个 composable 里最稳）。
- 后端不改：所有字段现成。

---

## 6. 实施步骤（拍板后按 backend / admin / web 三条提交）

**backend**
1. 迁移 A：`cs_faq_category` 加 `list_style` 列（可空，默认 list）。
2. 迁移 B：播种「新闻中心」根栏目（slug=news）+「图文新闻」(card) /「列表新闻」(list) 两个子栏目；幂等。
3. `App\Support\CmsListStyle` 真源 + 守卫测试；`CsFaqCategory::NEWS_SLUG` 常量。
4. `Storefront\NewsController`（channels / articles / detail）+ 路由（公开）。
5. `FaqService::articles()` 出口支持字段裁剪（新增可选参数，不改动帮助中心默认行为）。
6. 后台 `Admin\CsFaqController`：栏目 `list_style` 校验、文章 `cover_image` 校验。
7. `SitemapController` 分流 + 排除。
8. 测试：`NewsApiTest`（接口契约 / 形态 / 排除 / sitemap 分流）、`NewsSeedTest`（播种幂等）。

**admin**
9. 栏目表单加形态下拉；文章表单加封面上传。
10. 测试：`admin/tests/news-cms.test.ts`（形态下拉 + 封面上传）。

**web**
11. `NewsListView.vue` / `NewsDetailView.vue` + 路由（catch-all 之前）+ `api/news.ts`。
12. 测试：`web/tests/news.test.ts`（tab 切换 / 两种形态渲染 / 详情跳转 / SEO meta）。

**验证**
13. 后端 `php -d memory_limit=1G vendor/bin/pest` 全量；admin / web `vue-tsc -b` + vitest + build。
14. curl：`/api/news/channels`、`/api/news/articles`、`/api/news/articles/{id}`、`/sitemap.xml` 含 `/news/`。
15. 帮助中心侧栏与搜索确认**没有**新闻栏目/文章。

---

## 7. 后续可增强（一期不做）

- 文章 slug 与 `/news/{slug}` 语义化 URL；
- 文章级 SEO 三列 + 后台折叠区；
- 种草文章的**商品挂载**：正文插商品链接（零成本）→ 或新增 `BlockProduct` 区块（商品卡）；
- 标签/专题（repeater 字段即可，不必新表）；
- 阅读量排行、上一篇/下一篇；
- 新闻详情页 JSON-LD（Article 结构化数据，利于搜索结果展现）。
