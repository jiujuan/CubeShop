# CS-117 一期阶段验收报告（Gate G1）

| 项 | 内容 |
|----|------|
| 阶段 | 客户服务中心 一期（CS-101 ~ CS-117） |
| 出口关卡 | G1 |
| 结论 | **通过**（当日复核） —— 初判为「有条件通过」，两项 P1 与两项 P2 已于 2026-09-17 修复并全量复验：AC-117.4 达标、权限双路径一致、FAQ 正文富文本正常渲染、独立客服角色 `cs_agent` 可用。剩余 2 项 P3 非阻塞缺陷见 §6 |
| 报告日期 | 2026-09-17（含当日 P1 + P2 修复复核） |
| P1 修复证据 | 缺陷 #2（移动端溢出）→ `overflow-CS-117.txt` + `ui/ui-CS-117-mobile-fixed-*.png`；缺陷 #3（权限双路径）→ `CS-103/p1-fix-CS-103.txt` |
| 证据根目录 | `docs/testing/evidence/cs/` |

---

## 1. 交付范围

| 批次 | 任务 | 交付物 | 状态 |
|------|------|--------|------|
| A 数据层 | CS-101 ~ CS-103 | 核心五表迁移、模型与状态机常量/Seeder、客服权限码与角色授权迁移 | 通过 |
| B 后端 | CS-104 ~ CS-107 | 用户端 FAQ 四接口、`CsTicketService`（建单/消息/状态机）、用户端工单六接口、图片上传与站内通知 | 通过 |
| C 后端 | CS-108 ~ CS-110 | 后台工单列表/详情（筛选）、后台处理五动作、后台 FAQ 分类/文章管理 | 通过 |
| D 前端 | CS-111 ~ CS-115 | WEB 三页（服务中心/帮助中心/工单）、ADMIN 两页（服务工单/帮助中心）+ 侧栏菜单 | 通过 |
| E 质量 | CS-116 ~ CS-117 | 状态机/权限/并发专项、端到端冒烟与阶段验收 | 通过（见 §6 遗留项） |

---

## 2. 验收标准逐条对照（AC-117.1 ~ AC-117.7）

| AC | 标准 | 结论 | 证据 |
|----|------|------|------|
| AC-117.1 | 端到端 10 步全过（接口通道 + 浏览器通道各一次） | ✅ **通过** | 接口通道：`smoke-CS-117-http.txt`（**28 PASS / 0 FAIL**）；自动化等价用例 `pest-CS-117-e2e.txt`（5 passed / 59 assertions）；浏览器通道：`ui/ui-CS-117-e2e-*.png` + `ui/ui-CS-117-mobile-*.png` 逐页核验 |
| AC-117.2 | 内部备注在用户端不可见、不通知用户（第 6 步硬断言） | ✅ **通过** | 接口：用户端 `is_internal=true` 计数 = 0 且文本不含内部内容；`/me/notifications` 仍为 0 条。UI：`CS-114/ui-CS-114-detail.png` 客服侧显示「仅客服可见」，`CS-113/ui-CS-113-detail.png` 用户侧无该消息 |
| AC-117.3 | 全链路状态流转与 CS-102 矩阵完全一致 | ✅ **通过** | `CsTicketStateMachineApiTest`（25 条路径笛卡尔积，合法成功/非法 40009 且无副作用）+ `CsConcurrencyTest`；矩阵 `pending→[processing,closed]`、`processing→[waiting_user,completed,closed]`、`waiting_user→[processing,closed]`、`completed→[processing,closed]`、`closed→[]` |
| AC-117.4 | H5 三页（首页/列表/详情）在 375px 视口可用 | ✅ **通过**（P1 修复后） | 修复前 375px：`scrollWidth 656` vs `clientWidth 360`（溢出 296px、越界元素 36 个）；修复后：`360 vs 360`、页面级越界元素 **0**，桌面 1440px 亦为 0。证据 `overflow-CS-117.txt` + `ui/ui-CS-117-mobile-fixed-1..4*.png` + `ui/ui-CS-117-desktop-after-service-center.png`，回归 `web/tests/shop-header-responsive.test.ts`（8 例） |
| AC-117.5 | R1~R7 回归全绿 | ✅ **通过** | `pest-CS-117.txt`：R1 7 / R2 41 / R3 16 / R4 10 / R5 13 / R6 20（后端共 **107 passed**）+ R7 侧栏 2 passed |
| AC-117.6 | 证据齐全：CS-101~CS-116 每目录 ≥3 个证据文件 + 验收报告 | ✅ **通过** | 18 个目录全部 ≥3 文件（本次回填 CS-101~CS-110 缺失目录与 CS-111~115 的 UI 截图） |
| AC-117.7 | 设计文档 §10 成功标准逐条对照 | ⚠️ 见 §3 | 5 项达标 / 1 项二期 |

---

## 3. 设计文档 §10 成功标准逐条对照

| # | 成功标准 | 结论 | 依据 |
|---|----------|------|------|
| ① | 1 分钟内找到常见问题答案 | ✅ | 服务中心「热门问题」直出 + 搜索框 + 分类入口（`CS-111/ui-CS-111-desktop.png`）；关键词→详情→有帮助反馈闭环（`smoke` 步骤 1~2） |
| ② | 可提交工单并查看进度与回复 | ✅ | 提单（类型联动必填订单、凭证 ≤9 张）→ 列表状态 Tab → 详情气泡流（`CS-113/*.png`） |
| ③ | 客服可处理并关联订单 | ✅（二期深化） | 处理五动作全通；工单详情含订单卡片（订单号/状态/实付/首图）。深化项归 CS-202 |
| ④ | 状态清晰、记录可追溯 | ✅ | 5 态 + 关闭，状态标签 + 系统消息 + `first_replied_at/completed_at/closed_at` 时间锚点；`order_logs` 类同款系统消息 |
| ⑤ | FAQ 后台维护即时生效 | ✅ | `smoke` 前置步骤：后台发布草稿 → 用户端搜索立即命中（同一时刻，无缓存延迟） |
| ⑥ | 基础服务数据可查 | ❌ 二期 | CS-210（看板） |

---

## 4. 测试与证据汇总

| 层次 | 范围 | 结果 | 证据文件 |
|------|------|------|----------|
| 后端（SQLite） | CS 模块 12 个测试文件全量 | **144 passed / 464 assertions** | `CS-117/pest-CS-117-cs-module-total.txt` |
| 后端（PG `cubeshop_test`） | 专项三件套（状态机 25 + 权限矩阵 + 并发） | 40 passed / 100 assertions | `CS-116/pest-pgsql-CS-116.txt` |
| 后端（双库一致性） | CS-101/102/103/104/106/108/110 | 双库结果逐项一致 | 各任务 `pest-pgsql-CS-*.txt` |
| 端到端（接口通道，真实服务） | 10 步冒烟 | **28 PASS / 0 FAIL** | `CS-117/smoke-CS-117-http.txt` + `run-e2e-http.sh` |
| 端到端（自动化等价用例） | 10 步链路 | 5 passed / 59 assertions | `CS-117/pest-CS-117-e2e.txt` |
| 端到端（浏览器通道） | WEB 6 页 + 移动端 3 页 | 逐页截图核验 | `CS-117/ui/ui-CS-117-e2e-*.png`(6) + `ui-CS-117-mobile-*.png`(3) |
| 专项 CS-116 | 状态机 25 路径 / 权限矩阵 4×11 / 并发幂等 / 一致性 | 40 passed | `CS-116/pest-CS-116.txt`、`loadtest-CS-116.md`、`sql-CS-116.md` |
| 前端 WEB | `service-center` / `faq` / `cs-ticket` | 19 passed | `CS-117/vitest-CS-117-web.txt` |
| 前端 ADMIN | `cs-ticket` / `cs-faq` / `sidebar-icon` | 19 passed | `CS-117/vitest-CS-117-admin.txt` |
| 回归 R1~R7 | 订单/退款/通知/权限/报表/上传/侧栏 | 全绿（107 + 2） | `CS-117/pest-CS-117.txt` |
| **P1 修复后复验（后端 · SQLite）** | CS 模块全量（12 文件 + 新增 `CsPermissionSyncTest`） | **150 passed / 481 assertions** | `CS-103/pest-CS-103-p1fix.txt` |
| **P1 修复后复验（后端 · PG `cubeshop_test`）** | 同上 | **150 passed / 481 assertions** | `CS-103/pest-pgsql-CS-103-p1fix.txt` |
| **P1 修复后复验（前端 web）** | 全量（含新增 `shop-header-responsive` 8 例） | **18 files / 141 tests passed** | `CS-117/overflow-CS-117.txt` §五 |
| **P2 修复后复验（后端 · SQLite）** | `Cs`/`Faq`/`HtmlSanitizer`/`AccountRole` 过滤集 | **206 passed / 694 assertions** | `CS-112/pest-CS-112-p2fix.txt` |
| **P2 修复后复验（后端 · PG `cubeshop_test`）** | 同上 | **206 passed / 694 assertions**（与 SQLite 逐项一致） | `CS-112/pest-pgsql-CS-112-p2fix.txt` |
| **P2 修复后复验（后端 · 全量）** | 全项目 Pest 套件 | **811 passed / 3403 assertions** | 见 §9 复验记录 |
| **P2 修复后复验（前端 admin）** | 全量（含新增 `router-landing` 5 例 + 侧栏显隐、工具条、预览用例） | **16 files / 153 tests passed**，`vue-tsc -b` 零错误 | `CS-117/vitest-CS-117-admin-p2fix.txt` |
| **P2 修复后复验（前端 web）** | 全量（含 FAQ 富文本渲染 2 例） | **18 files / 142 tests passed**，`vue-tsc -b` 零错误 | `CS-117/vitest-CS-117-web-p2fix.txt` |

### 4.1 证据目录清点（AC-117.6）

| 目录 | 文件数 | 目录 | 文件数 | 目录 | 文件数 |
|------|--------|------|--------|------|--------|
| CS-101 | 4 | CS-107 | 3 | CS-113 | 4 |
| CS-102 | 3 | CS-108 | 3 | CS-114 | 4 |
| CS-103 | 3 | CS-109 | 3 | CS-115 | 3 |
| CS-104 | 3 | CS-110 | 3 | CS-116 | 4 |
| CS-105 | 3 | CS-111 | 3 | CS-117 | 12 |
| CS-106 | 3 | CS-112 | 3 | — | — |

> 说明：CS-101~CS-110 的证据目录在本次验收前**不存在**，已按各任务「验收证据」清单回填最小值集
> （真实测试输出 + 双库运行 + SQL/grep 核对，均为实测生成，未编造）。
> 部分任务清单中更细的文档（如 CS-105 的 `loadtest-CS-105.md`、CS-108/109/110 的 `curl-*.md`）
> 尚未逐份补齐，见 §7 交接项 T1。

---

## 5. 端到端 10 步结果（接口通道）

| 步 | 操作 | 结果 |
|----|------|------|
| 1 | 买家登录 → 服务中心首页读 FAQ 分类 | ✅ 5 个分类 |
| 2 | 搜索「物流」→ 详情 → 提交「有帮助」 | ✅ 命中 id=2；`helpful_count` 递增 |
| 3 | 物流问题 + 关联订单 + 上传 2 张凭证 → 提交 | ✅ 2 张凭证；工单 `TK20260917000006` |
| 4 | 工单号 / `pending` / 我的列表可见 | ✅ 三项全过 |
| 5 | 客服登录 → 待处理数 → 打开工单 | ✅ `pending_count=1` |
| 6 | 内部备注 → 用户端不可见 + 不通知 | ✅ 4 项硬断言全过 |
| 7 | 客服公开回复 → 自动流转 + `first_replied_at` + 站内信 | ✅ `pending→processing`；`cs_ticket_reply ×1` |
| 8 | 置「等待用户回复」→ 买家回复 → 回 `processing` | ✅ 两次流转均合规 |
| 9 | 标记完成 → 用户端可见完成 + 系统消息 | ✅ 系统消息 2 条 |
| 10 | 买家关闭 → 再次关闭 | ✅ `closed`（reason=user）；复关 `409 / 40009` |

---

## 6. 未解决问题清单

### #1 [P2 · 已修复 2026-09-17] FAQ 详情把 HTML 富文本按纯文本渲染，`<p>` 标签对用户可见

- **现象**：`/service-center/faq/2` 正文区直接显示 `<p>一般情况下，付款成功后 48 小时内完成发货（节假日顺延）。</p>` 等原始标签。
- **复现**：`CS-112/ui-CS-112-detail.png`。
- **根因**：`FaqDetailView.vue` 为彻底规避 XSS，用 `whitespace-pre-wrap` 纯文本渲染（不使用 `v-html`）；但 `CsFaqCategorySeeder` 与后台编辑器产出的 `content` 是 HTML 片段。
- **影响**：只影响观感与可读性，无安全风险（当前是「过度安全」的一侧）。
- **建议**：二选一 —— ① 后台保存前用白名单 sanitize（如 `DOMPurify` + 受限标签集）后前端 `v-html` 渲染；② 后台限纯文本并按 `\n` 渲染。推荐 ①，并在 CS-115 编辑器侧同步。

**✅ 已修复（2026-09-17）** —— 采用建议 ①，但把净化放在**后端写入侧**而非前端：

- **新增 `App\Support\HtmlSanitizer`**（白名单净化器，零新增依赖）：标签 + 属性双白名单；
  `script/style/iframe/form/svg` 等连子树丢弃，未知标签「去壳保留文字」；`href`/`src` 校验协议
  （`javascript:`、`data:text/html` 被剥离，允许 http(s)/相对路径/`data:image/*`）；
  事件属性（`on*`）永不通过；`target="_blank"` 自动补 `rel="noopener noreferrer"`；
  无标签的纯文本按「转义 + 换行转 `<br>`」处理；**不含块级标签的行内片段**也把换行转 `<br>`
  （否则作者写的多行会被 HTML 折叠成一行）。
- **写入唯一入口**：`CsFaqArticle::setContentAttribute()` 模型写入器调用净化器 ——
  接口、种子数据、tinker 三条路径全覆盖，无法靠「先建后改」绕过。
- **存量清洗**：迁移 `2026_09_17_000042_normalize_cs_faq_article_content.php` 分批（200/批）重写历史行，
  仅在内容确实变化时写库（净化幂等）。开发库实测：注入的脏数据
  `<p>正常段落</p><script>alert(1)</script><img src="/storage/uploads/a.png" onerror="alert(2)">`
  迁移后变为 `<p>正常段落</p><img src="/storage/uploads/a.png">`。
- **前端渲染**：`web/src/views/FaqDetailView.vue` 与 admin 预览弹窗改 `v-html`，
  并各自补齐 `.faq-body` 排版样式（Tailwind Preflight 会清掉列表符号/标题字号；
  净化器刻意不放行 `style`/`class`，排版统一由前端控制）。
- **后台编辑器**（CS-115）：正文标签最初由「Markdown / 纯文本」更正为「富文本 HTML」并加轻量工具条；
  后于同日**改用 markdown 编辑器 `md-editor-v3`** —— 正文改「双列模型」：`content_md` 存 markdown 源、
  `content` 存服务端渲染（`MarkdownRenderer`）并净化（`HtmlSanitizer::cleanHtml`）的 HTML 产物，
  作者写 markdown、系统渲染 HTML（详见 `CS-115/md-editor-CS-115.md` 与 `plan/CS/README.md` §10.5）。
- **回归**：`tests/Unit/HtmlSanitizerTest.php`（14 例 / 62 assertions，含 6 组危险输入数据集、
  幂等性、行内换行）+ `tests/Feature/CsFaqRichContentTest.php`（5 例 / 19 assertions，
  新建/编辑/用户端详情/后台预览/种子数据）。变异验证：跳过协议校验 → 协议用例失败；
  放行 `on*` 属性 → 2 条事件属性用例失败（确认测试非空转）。
- **视觉复验**：`ui/ui-CS-112-richtext-desktop.png`、`ui-CS-112-richtext-mobile.png`（用户端 4 段正确分段）、
  `ui/ui-CS-112-admin-preview-richtext.png`（后台预览）、`ui/ui-CS-112-admin-editor-toolbar.png`（编辑器工具条）。
- **行为变化须知**：正文不再可能承载内联样式/自定义 class（历史数据由 000042 清洗）；
  若确有排版诉求，走标题/列表/表格等结构标签。

### #2 [P1 · 已修复 2026-09-17] 移动端 375px 下全局 `ShopHeader` 未适配，客服三页出现横向滚动

- **现象**：375×812 视口下，顶部全局导航（"全部商品分类" + 首页/热销推荐/运动户外… 一级类目横排）不折叠、不换行，导致整页横向溢出并出现横向滚动条；客服页内容区本身正常。
- **复现**：`CS-117/ui/ui-CS-117-mobile-1-service-center.png`、`ui-CS-117-mobile-2-ticket-list.png`、`ui-CS-117-mobile-3-ticket-detail.png`（三张均可见底部横向滚动条与挤压的导航文字）。
- **根因**：`ShopHeader` 的类目导航为固定横排，未做窄屏折叠（汉堡菜单/横向滚动容器）。
- **影响**：直接导致 **AC-117.4「无横向滚动」不达标**；客服三页内容可用，但整页体验不合格。
- **建议**：为 `ShopHeader` 增加 `sm:flex` 断点（窄屏改为横向可滚动容器或折叠抽屉）。改动落在全局组件，建议独立小任务处理，避免与 CS 一期耦合。

**✅ 已修复（2026-09-17）**

- **根因修正**：不只是「类目导航横排」——实测主头部一行内 logo + 搜索框 + 右侧入口总宽超视口，
  且搜索框 `flex-1` 因 `min-width:auto` **无法收缩**，把右侧购物车/用户入口整体推出视口（右边界 626px）。
  另有公告条长文案 + 账号入口同排挤压。
- **改动**（`web/src/components/ShopHeader.vue`）：公告条 `px-3 sm:px-6` + 文案 `truncate` + 账号入口 `hidden sm:flex`；
  主头部 `gap-3 lg:gap-8`、logo 文字块 `hidden sm:block`、搜索框容器 **`min-w-0`**、右侧入口组 `shrink-0`
  且次要文字 `hidden lg:block`；分类导航 `overflow-x-auto` + `lg:overflow-x-visible`（子项移动端
  `shrink-0 whitespace-nowrap`、`lg:` 回退保持桌面原行为），分类下拉 `hidden lg:block`（避免被滚动容器裁剪）。
- **复验**：375px `scrollWidth == clientWidth == 360`、页面级越界元素 0；1440px `1425 == 1425`；
  截图 5 张（移动端 4 + 桌面 1）；新增回归 `web/tests/shop-header-responsive.test.ts`（8 例），
  web 全量 `18 files / 141 tests passed`。
- **行为变化须知**：< `lg` 时「我的订单」入口收进用户菜单、购物车与用户名文字标签只留图标（图标 + 角标不变）；
  分类下拉仅在桌面端可展开（触摸设备本无 hover）。全局 `ShopHeader` 改动对全站生效，已抽查首页与客服三页。

### #3 [P1 · 已修复 2026-09-17] `operator` 角色在两个初始化路径下权限不同

- **现象**：`RolePermissionSeeder::run()` 的 `operator->syncPermissions([...])` 列表中**不含任何 `cs.*`**；而迁移 `2026_09_17_000039_add_cs_permissions.php` 第 42 行 `Role::findOrCreate('operator','web')->givePermissionTo(self::NEW_PERMISSIONS)` 把 **全部 3 个 `cs.*`** 授予了 `operator`。
- **实测**（`CS-103/sql-CS-103.md`，本机开发库）：`operator => cs.ticket.view,cs.ticket.handle,cs.faq.manage`（因走的是迁移路径）。
- **影响**：① 全新安装（`migrate --seed`）后 `operator` 无 `cs.*`，而升级库有全部 → 环境不一致、灰度期间行为漂移；② `CsNotificationService::notifyNewTicket()` 用 `sendToRole('operator')` 发新工单通知 —— 全新安装环境下运营**收到通知却打不开工单**（403）。
- **建议**：以设计意图为准二选一 —— ① 若运营即客服：在 Seeder 的 `operator` 权限列表补 `cs.ticket.view`、`cs.ticket.handle`、`cs.faq.manage`（与迁移对齐）；② 若运营只读：迁移改为只授 `cs.ticket.view`，并把 `notifyNewTicket` 的收件角色改为具备 `cs.ticket.handle` 的角色（见 #4）。推荐 ①（当前只有两个后台角色）。

**✅ 已修复（2026-09-17）**

- **方向选择**：采用「operator **不**持有 `cs.*`」。依据是后端两处既有契约
  （`AdminCsTicketApiTest` / `AdminCsFaqApiTest` 注释与断言锁定「operator 未授予 CS 权限，用于校验 403」，
  写于 CS-108/110 阶段、晚于 CS-103 迁移）以及职责划分（运营 = 商品/订单/发货/退款/报表/营销/支付核账；
  客服为独立职责线；独立客服角色见 #4，归二期）。
- **改动**：
  1. 新增迁移 `2026_09_17_000040_revoke_cs_permissions_from_operator.php`，回收存量库中 operator 的
     三个 `cs.*`（幂等、带表存在守卫、`down()` 不做反向授权）——已应用至开发库 PG `cubeshop`；
  2. `NotificationService::sendToPermission()`（新增，与 `sendToRole()` 对称，收件人为 0 时记 warning）
     + `CsNotificationService::notifyNewTicket()` 改为**按权限** `cs.ticket.view` 投递，不再写死角色名
     → 「有通知打不开」从根本上消除（二期新增客服角色只要授予该权限即自动覆盖）；
  3. `RolePermissionSeeder` 补注释说明「客服权限不授予 operator」及其原因，防止再次分叉。
- **回归**：`backend/tests/Feature/CsPermissionSyncTest.php`（6 例 / 17 assertions）——权限码清单、
  全新安装（超管有 / operator 无）、**存量库升级后与全新安装逐项一致**、回收迁移幂等、
  新工单通知「超管收到 1 条 / operator 收到 0 条」、无收件人时降级不抛异常。
  变异验证：注释掉回收动作后「存量库升级一致性」用例精确失败（`1 failed, 5 passed`），确认非空转。
- **复验**：SQLite `150 passed / 481 assertions`、PG 测试库 `150 passed / 481 assertions`（双库一致）。
  证据 `CS-103/p1-fix-CS-103.txt`、`CS-103/pest-CS-103-p1fix.txt`、`CS-103/pest-pgsql-CS-103-p1fix.txt`。

### #4 [P2 · 已修复 2026-09-17] 缺少独立的「客服」角色

- CS-103 与 README §5 的权限矩阵描述假定存在「有 `cs.ticket.view` 的运营」与「有 `cs.ticket.handle` 的客服」两类账号，但 `RolePermissionSeeder` 只创建 `super_admin` 与 `operator`。
- 现有 `CsPermissionMatrixTest` 通过临时角色覆盖了矩阵正确性（测试层面 OK），但**生产角色缺失**意味着「客服」不能与「运营」分权。
- **建议**：二期（或提前）新增 `customer_service` 角色并在权限矩阵文档中登记。

**✅ 已修复（2026-09-17）** —— 角色名定为 `cs_agent`（中文名「客服」，与 `cs.*` 权限前缀同源）：

- **单一来源**：新增 `App\Support\AdminRole`（角色标识 / 内置清单 / 中文名），
  `RolePermissionSeeder`、`Admin\AccountController::ADMIN_ROLES`、`Admin\RoleController::BUILTIN_ROLES`
  全部改为引用它 —— 消除「建了角色但后台分配不到」这类分叉（与缺陷 #3 同源问题）。
- **权限集**：`cs.ticket.view`、`cs.ticket.handle`、`cs.faq.manage`，**不含任何经营数据权限**
  （无 `dashboard.view`/`order.view`/`product.view`/`refund.view`/`report.view`）。
- **两条落地路径**：Seeder（全新安装）建角色 + 授权；迁移
  `2026_09_17_000041_add_cs_agent_role.php`（存量库升级）`findOrCreate` 角色并补授权（只补不撤，
  不覆盖超管在「角色权限」页的手工调整）。两条路径的结果由
  `CsPermissionSyncTest`「客服角色双路径一致」用例逐项比对锁定；变异验证（注释掉迁移里的
  `givePermissionTo`）→ 该用例精确失败。
- **后台可用性**：`cs_agent` 纳入后台内置角色 ⇒ 可在「系统 → 管理员账号」创建/筛选/编辑客服账号
  （新增账号时角色选项由共享标签表生成，不再硬编码），且不可删除/重命名。
- **转交名单**：新增 `GET /api/admin/cs/assignees`（`cs.ticket.handle` 守卫），
  只返回**持有 `cs.ticket.view` 且启用**的账号 —— 客服无需 `account.manage` 即可转交工单，
  且不额外暴露后台全部账号；前端工作台改用该接口。
- **登录落地页**：`landingPath()`（admin 路由）按「首个有权限的入口」回退。客服没有 `dashboard.view`
  （工作台含今日销售额），登录后直接进 `/cs/tickets`；直接访问 `/dashboard` 也会被守卫回退。
  侧栏「工作台」菜单同步按 `dashboard.view` 显隐，客服看不到进不去的入口。
- **通知可达**：沿用缺陷 #3 的按权限投递（`cs.ticket.view`），客服账号开箱即收得到新工单通知且能打开。
- **回归**：`tests/Feature/CsAgentRoleTest.php`（7 例 / 51 assertions：角色与权限集、内置保护、
  账号创建与筛选、客服可用客服接口 + 拿不到经营数据、`operator` 边界不变、转交名单过滤、通知可达）
  + `admin/tests/router-landing.test.ts`（5 例）+ 侧栏显隐用例（客服视角不显示工作台）。
- **浏览器复验**：`ui/ui-CS-117-cs-agent-tickets.png`（客服 客服小美 的侧栏只有「服务工单 / 帮助中心」）；
  重定向本身改由 `redirect-verify-CS-117.txt` 的 `location.pathname` 实测证明（登录后与访问 `/dashboard` 后
  pathname 均为 `/cs/tickets`、侧栏无「工作台」）——**截图看不到 URL，无法证明重定向**，故不以截图充当该证据。
- **后续**：「客服主管」（看全部工单 + 处理量排行 + 高级分配）与按处理人可见性仍归二期 CS-201。

### #5 [P3 · 文档口径] 「状态写入唯一入口」的表述与实现有细微出入

- `CsTicketService` 头部注释与 `CsTicket` 模型注释均声明 `transitionTo()` 是「全模块**唯一**的状态写入点」。
- 实际另有 `applyAutoTransition()`（同一文件的私有方法）直接 `$ticket->update(['status' => $target])`，用于消息驱动的自动流转（`pending/waiting_user → processing`、`waiting_user/completed → processing`）。它有 `canTransitTo()` 守卫，但不写系统消息、不写操作日志 —— 这解释了为何步骤 7/8 的自动流转在消息流中**没有**系统消息。
- **结论**：实现是合理设计，属**文档表述不准**。建议将注释改为「唯一**显式**流转入口；消息驱动的自动流转见 `applyAutoTransition()`（受同一矩阵约束，不产生系统消息）」。详见 `CS-105/grep-CS-105.txt`。

### #6 [P3 · 工具链] 浏览器通道的 `agent-browser eval` 偶发挂起并导致页面重置

- 现象：调用 `agent-browser eval` 读取 DOM 时偶发挂起，随后当前页面被重置为 `about:blank`，后续元素查找全部报「Element not found」。
- 规避：本次改为「自包含脚本」（`ui/shot.sh`、`ui/shot-admin.sh`：关闭 → 登录 → 导航 → 截图，每步带 `timeout`），并统一用 `get html` + 本地解码验证码（`decode_captcha.py`，从内联 SVG 的 `<text>` 提取），避免使用 `eval`。
- 该脚本已归档为可复用工具，后续回归可直接复用。
- **口径补充（2026-09-17，P1 修复期间）**：`eval` 并非完全不可用 —— 页面就绪后它能正常返回（返回 JSON 字符串，需自行剥引号），
  横向溢出测量（`ui/diag_overflow.sh`）就是用它跑通的。真实情况是「**偶发挂起 + 挂起后页面被重置**」，
  因此结论仍是用自包含脚本 + 每步 `timeout`，只是测量类脚本可以放心用 `eval`。

---

## 7. 二期（CS-2xx）交接项

| # | 交接项 | 归属 | 说明 |
|---|--------|------|------|
| T1 | 按各任务「验收证据」清单补齐细粒度文档 | CS-216 或专项 | CS-105 `loadtest-CS-105.md`；CS-108/109/110 的 `curl-*.md`；CS-104/106/107 的 `sql-*.md`。当前已满足 AC-117.6 的 ≥3 文件下限 |
| ~~T2~~ | ~~修复缺陷 #1（FAQ 富文本渲染）~~ → **已完成（2026-09-17）** | CS-112 / CS-115 衍修 | 见 §6 #1（后端白名单净化 + 存量清洗迁移 000042 + 前端 v-html + 编辑器改用 md-editor-v3 编辑 markdown 源，双列 `content_md`/`content`） |
| ~~T3~~ | ~~修复缺陷 #2（`ShopHeader` 移动端适配）~~ → **已完成（2026-09-17）** | 全局前端 | 见 §6 #2；新增移动端回归 8 例（`web/tests/shop-header-responsive.test.ts`） |
| ~~T4~~ | ~~修复缺陷 #3（`operator` 权限对齐）+ #4（新增客服角色）~~ → **两项均已完成（2026-09-17）** | CS-103 / CS-201 | #3 见 §6 #3（按权限投递通知 + 迁移 000040 回收）；#4 见 §6 #4（`cs_agent` 角色 + 迁移 000041 + 转交名单接口 + 落地页） |
| T4b | 若产品确认「运营也可处理工单」 | 产品决策 → CS-201 | 需三处同改：Seeder 的 operator 列表、新增回补授权迁移、通知收件人改回按角色（当前实现已就地标注） |
| T5 | 更正缺陷 #5（状态写入点注释口径） | 文档 | 纯注释改动 |
| T6 | 复用 CS-117 的 E2E 脚本作为二期回归基线 | CS-216 | `run-e2e-http.sh` 已幂等，可直接扩步 |
| T7 | CS-202 工单↔订单深化、CS-210 服务数据看板 | 二期主线 | 对应 §10 成功标准 ③（深化）与 ⑥（未达） |

---

## 8. 本次验收对开发环境的影响（需知悉）

| 操作 | 说明 |
|------|------|
| `php artisan migrate --force` | 开发库 PG `cubeshop` 存在 2 个待应用迁移 `2026_09_17_000038_create_cs_ticket_tables`、`..._000039_add_cs_permissions`，本次已应用（**仅新建表 + 新增权限行，无破坏性变更**） |
| `db:seed CsTicketTypeSeeder` / `CsFaqCategorySeeder` | 播种 8 个工单类型、5 个 FAQ 分类各 1 篇示例文章（示例文章为 `draft`） |
| `php artisan migrate --force`（P1 修复） | 新增迁移 `2026_09_17_000040_revoke_cs_permissions_from_operator` 已应用：**从 `operator` 角色回收 3 个 `cs.*` 权限**（不改表结构、不删数据）。若用 `operator`/`Operator@123` 访问后台客服工作台，将由 200 变为 **403 —— 这是本次修复的预期结果**；客服工单请用超管 `admin` |
| 冒烟脚本副作用 | `run-e2e-http.sh` 每次运行会新建 1 个测试买家、1 个测试商品、1 笔已支付订单、1 张工单，并把所有 `draft` FAQ 发布为 `published`（幂等） |
| `php artisan migrate --force`（P2 修复） | 新增两个迁移已应用：`2026_09_17_000041_add_cs_agent_role`（**创建 `cs_agent` 角色并授予 3 个 `cs.*`**）、`2026_09_17_000042_normalize_cs_faq_article_content`（**按白名单重写存量 FAQ 正文**；本机实测把注入的脏数据 `script`/`onerror` 剥掉，`<p>`/`<img>` 保留）。均为数据/权限变更，无表结构变更 |
| 开发库为浏览器验收新建的账号 | `kefu01` / `Kefu@1234`（昵称「客服小美」，角色 `cs_agent`），仅用于截图验证客服视角；**Seeder 不预置客服账号**，实际环境请通过「系统 → 管理员账号」按需创建 |
| 浏览器通道 | 使用 `admin`/`Admin@123` 与测试买家 `uibuyer`/`Test@1234`；未改动 `.env` |
| WEB 开发服务 | 运行于 IPv6 `[::1]:3000`（`http://127.0.0.1:3000` 不可达，故 curl/截图统一走 `[::1]:3000`） |

---

## 9. P2 修复复验记录（2026-09-17）

两项 P2 缺陷（#1 FAQ 富文本渲染、#4 独立客服角色）修复后，在干净工作区重跑三通道并做变异验证。

| 通道 | 命令 | 结果 |
|------|------|------|
| 后端（SQLite） | `php vendor/bin/pest --filter="(Cs\|Faq\|HtmlSanitizer\|AccountRole)"` | **206 passed / 694 assertions** |
| 后端（PG `cubeshop_test`） | `php vendor/bin/pest -c phpunit.pgsql.xml --filter="..."` | **206 passed / 694 assertions**（与 SQLite 逐项一致） |
| 后端（全量） | `php vendor/bin/pest` | **811 passed / 3403 assertions** |
| 前端 admin | `npx vitest run` + `npx vue-tsc -b` | **16 files / 153 tests passed**，类型检查零错误 |
| 前端 web | `npx vitest run` + `npx vue-tsc -b` | **18 files / 142 tests passed**，类型检查零错误 |

**新增/改动用例**

| 文件 | 例数 | 覆盖 |
|------|------|------|
| `tests/Unit/HtmlSanitizerTest.php`（新） | 14 | 危险内容剥离（6 组数据集）、协议校验、富文本能力保留、rel 补全、未知/危险标签、换行保留、幂等、边界 |
| `tests/Feature/CsFaqRichContentTest.php`（新） | 5 | 后台新建/编辑净化、用户端详情返回未转义 HTML、后台预览、种子数据形态 |
| `tests/Feature/CsAgentRoleTest.php`（新） | 7 | 角色与权限集、内置保护、账号创建与筛选、客服可用客服接口且拿不到经营数据、operator 边界、转交名单过滤、通知可达 |
| `tests/Feature/CsPermissionSyncTest.php`（扩） | +3 | 角色身份单一来源、客服角色双路径一致、000041 迁移幂等 |
| `tests/Feature/AccountRoleApiTest.php`（改） | — | 自定义角色名 `cs_agent` → `audit_agent`（避免与新增内置角色撞车，原因已就地注释） |
| `web/tests/faq.test.ts`（改） | 2 | 富文本渲染成元素、纯文本换行保留（原「纯文本转义」用例按新方案重写） |
| `admin/tests/cs-faq.test.ts`（扩） | +3 | 工具条占位插入、工具条包裹所选文字、预览按富文本渲染 |
| `admin/tests/cs-ticket.test.ts`（改/扩） | +1 | 转交名单来自 `/admin/cs/assignees`（断言选项文案与 mock 调用）、客服视角不显示工作台 |
| `admin/tests/router-landing.test.ts`（新） | 5 | 各角色落地页回退（含客服） |

**变异验证（确认用例能捕获真实回归，而非空转）**

| 变异 | 预期 | 实测 |
|------|------|------|
| 注释掉迁移 000041 的 `givePermissionTo` | 双路径一致性用例失败 | ✅ 精确失败（`1 failed / 8 passed`） |
| 净化器跳过 `href`/`src` 协议校验 | 协议校验用例失败 | ✅ 精确失败（`1 failed / 13 passed`） |
| 净化器放行 `on*` 属性 | 事件属性用例失败 | ✅ 精确失败（`2 failed / 12 passed`） |

**迁移在真实开发库上的效果（PG `cubeshop`）**

```
插入脏数据（绕过模型写入器，模拟上线前历史数据）
  RAW_BEFORE = <p>正常段落</p><script>alert(1)</script><img src="/storage/uploads/a.png" onerror="alert(2)">
php artisan migrate --force
  RAW_AFTER  = <p>正常段落</p><img src="/storage/uploads/a.png">   （script/onerror 已剥离，结构保留）
  角色       = cs_agent / 客服
  权限       = cs.ticket.view,cs.ticket.handle,cs.faq.manage
```

**浏览器通道（新增截图）**

| 文件 | 视口 | 核验点 |
|------|------|--------|
| `ui/ui-CS-112-richtext-desktop.png` | 1440×900 | 用户端 4 段正文正确分段，无字面量标签 |
| `ui/ui-CS-112-richtext-mobile.png` | 375×812 | 移动端富文本渲染正常、无横向滚动 |
| `ui/ui-CS-112-admin-preview-richtext.png` | 1600×1000 | 后台预览弹窗富文本渲染 |
| `ui/ui-CS-112-admin-editor-toolbar.png` | 1600×1000 | 正文编辑器「富文本 HTML」标签 + 8 个工具条按钮 |
| `ui/ui-CS-117-cs-agent-tickets.png` | 1600×1000 | 客服视角：侧栏仅「服务工单 / 帮助中心」，无经营数据入口 |
| `redirect-verify-CS-117.txt` | — | 客服登录落地与 `/dashboard` 回退的 `pathname` 实测（eval，含脚本与原始输出） |

> 注：原 `ui/ui-CS-117-cs-agent-dashboard-redirect.png` 已删除——它与 `ui-CS-117-cs-agent-tickets.png`
> 字节完全相同（md5 `66f04b9f715e18d11e3f60f735dae65d`），不能独立证明重定向，属重复证据。
