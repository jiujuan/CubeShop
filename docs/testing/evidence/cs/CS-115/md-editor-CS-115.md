# CS-115 衍修：FAQ 正文编辑器改用 md-editor-v3（markdown 编辑器）

- 任务：CS-115（后台 FAQ 分类与文章管理）
- 关联：`CS-117/acceptance-CS-117.md` §6 缺陷 #1；`plan/CS/README.md` §10.5
- 日期：2026-09-17

## 1. 动机

缺陷 #1 的修复把用户端改成「渲染已净化的 HTML」后，后台编辑器仍要求作者**手写 HTML 标签**
（标签文案甚至是「正文（富文本 HTML）」）。作者既不会写标签、标注又与实现不符。

改用 Vue 3 生态主流的 markdown 编辑器 **md-editor-v3**：作者写 markdown，系统渲染 HTML。
净化仍留在后端写入侧（一次净化、多端受益），编辑器只需降低写作成本。

## 2. 方案：双列正文（markdown 源 + HTML 产物）

| 列 | 角色 | 说明 |
|----|------|------|
| `cs_faq_article.content_md` | **可编辑载体** | markdown 源；接口只收这一列（`store`/`update`）；编辑器回显用 |
| `cs_faq_article.content` | **渲染产物** | 由 `content_md` 派生；用户端与后台预览 `v-html` 渲染 |

一致性由**模型 `saving` 钩子**保证：仅当 `isDirty('content_md')` 时重算
`content = HtmlSanitizer::cleanHtml(MarkdownRenderer::toHtml(content_md))`。
这样 API 契约、两端展示层、既有净化链**零改动**（展示端拿到的仍是一段安全 HTML）。

## 3. 改动清单

### 后端
| 文件 | 改动 |
|------|------|
| `app/Support/MarkdownRenderer.php` | **新增**。单例 `MarkdownConverter`：CommonMark 核心 + GFM 扩展（表格/删除线/自动链接/任务列表，`DisallowedRawHtml`）+ HeadingPermalink（`insert=none`、`apply_id_to_heading=true`、`id_prefix=content` 生成中文锚点）。安全项：`html_input=escape`、`allow_unsafe_links=false`。 |
| `app/Support/HtmlSanitizer.php` | 新增 `cleanHtml()`（渲染产物专用，跳过「纯文本/行内启发」，避免 `<img>` → `&lt;img&gt;` 二次转义）；`SAFE_ID` 正则改 Unicode `^[\p{L}][\p{L}\p{N}_.:\-]*$/u`，放行中文标题锚点。 |
| `app/Models/CsFaqArticle.php` | `fillable` 加 `content_md`；新增 `booted()` `saving` 钩子派生 `content`；保留 `setContentAttribute()` 走 `clean()` 作旧路径兜底。 |
| `app/Http/Controllers/Admin/CsFaqController.php` | `storeArticle`/`updateArticle` 校验改 `content_md`（不再接受 `content` 入参）；`previewArticle` 与列表返回 `content_md`。 |
| `database/migrations/2026_09_17_000043_add_content_md_to_cs_faq_article.php` | **新增**。加 `content_md` TEXT NULL 列。 |
| `database/migrations/2026_09_17_000044_convert_cs_faq_article_content_to_markdown.php` | **新增**。存量正文 HTML→markdown 幂等回填（`league/html-to-markdown`，`chunkById(200)`，仅处理 `content_md` 为 NULL 的行；回填触发钩子重算 `content`）。 |
| `database/seeders/CsFaqCategorySeeder.php` | 种子正文改 markdown（如 `1. 浏览商品…\n2. 在购物车…`），写 `content_md`。 |
| 依赖 | `league/commonmark ^2.10`、`league/html-to-markdown ^5.1`。 |

### 前端（admin）
| 文件 | 改动 |
|------|------|
| `src/views/cs/CsFaqView.vue` | 引入 `md-editor-v3`（`MdEditor` + `style.css`）；删除旧 textarea 工具条（`SNIPPETS`/`applySnippet`）；`onUploadImg(files, callback)` 调现有 `uploadImage` 并回填 `{url,alt,title}`；模板 `<MdEditor v-model="form.content_md" language="zh-CN" :toolbars-exclude="['github','save']" :style="{height:'420px'}" :on-upload-img="onUploadImg" />`。 |
| `src/api/cs.ts` | `CsFaqArticleRow` 加 `content_md`；`CsFaqArticlePayload.content_md`（移除 `content`）。 |
| `tests/cs-faq.test.ts` | `vi.mock('md-editor-v3')` 用同契约 stub（CodeMirror 6 在 jsdom 不可用）；用例改测 `content_md` 绑定/回显/存量兜底/图片上传/422。 |

## 4. 验证

| 通道 | 内容 | 结果 | 证据 |
|------|------|------|------|
| HTTP 端到端 | 登录 → 建文章（含标题/有序列表/表格/图片/内嵌 `<script>`/`javascript:` 链接）→ 断言产物 → 更新 → 预览 → 清理 | **17 PASS / 0 FAIL** | `verify-md-http.py`、`http-CS-115-md.txt` |
| 后端 SQLite | `MarkdownRendererTest` + `CsFaqRichContentTest` + `AdminCsFaqApiTest` + `CsAgentRoleTest` | **38 passed / 170 assertions** | `pest-CS-115-md.txt` |
| 后端 PG | 同上（`-c phpunit.pgsql.xml`，库 `cubeshop_test`） | **38 passed / 170 assertions** | `pg-CS-115-md.txt` |
| 前端 admin | `npx vitest run tests/cs-faq.test.ts` | **13 passed** | `vitest-CS-115-md.txt` |
| 类型检查 | `npx vue-tsc -b` | 零错误 | — |
| 浏览器 | 编辑弹窗（左源右预览 + 工具栏）、空编辑器 | 截图（md5 互异） | `ui/ui-CS-115-md-editor-edit.png`、`ui/ui-CS-115-md-editor-create.png`、`ui/shot-md-editor.sh` |

**HTTP 端到端关键断言**（`http-CS-115-md.txt`）：

- `content_md` 回显 markdown 源（仅首尾空白被 Laravel `TrimStrings` 裁剪）；
- `content` 为渲染产物：含 `<h2>`/`<ol>`/`<table>`/`<img>`；
- 中文标题锚点 `id="content-如何下单"` **保留**（验证 `SAFE_ID` 改 Unicode 后生效）；
- 内嵌 `<script>` 被转义为文本 `&lt;script&gt;`（无裸 `<script>`）——`html_input=escape`；
- `javascript:` 链接被丢弃——`allow_unsafe_links=false`；
- 更新正文后 `content` 重新派生（含新增 `<h3>`）；`preview` 同时返回 `content_md` 与 `content`。

## 5. 开发库存量核对

`php artisan migrate --force` 应用 `000043`/`000044` 后，`tinker` 抽查历史文章：
HTML 正文已转为 markdown 源、`content` 由钩子重渲染，正文结构与原样一致（标题/列表/表格/图片）。

## 6. 取舍与遗留

- **分包体积**：`CsFaqView` 分包因 md-editor-v3（CodeMirror 6 内核）增大至 **~922 kB（gzip 317 kB）**。
  该页是低频后台页，暂接受；后续可对编辑器组件按需 `import()` 延迟加载（Vite 动态导入 + `defineAsyncComponent`）。
- **存量迁移不回滚**：`000044::down()` 留空（markdown→HTML 反解无意义），与 `000042` 一致。
- `content` 仍保留唯一写入点（模型钩子），业务代码不得直接写 `content`。
