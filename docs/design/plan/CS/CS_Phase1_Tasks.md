# 客户服务中心 一期（MVP）任务清单

**任务编号**：CS-101 ~ CS-117
**阶段目标**：**打通人工服务闭环** —— 用户能自助查 FAQ、能提交工单并跟踪进度；客服能在工作台回复/转交/改状态/关闭；运营能维护 FAQ 内容并即时生效。
**预估**：20~26 人天（1 后端 + 1 前端 + 0.5 测试并行）
**开工前置**：① 工单状态机矩阵（CS-102）与权限矩阵（CS-103）评审通过；② 一期「不做清单」已达成共识（见 README §2.1）。
**阶段出口**：CS-117 阶段验收通过（E2E 10 步全过 + R1~R7 回归全绿 + H5 三页可用）

> 证据统一归档到 `docs/testing/evidence/cs/CS-XXX/`；回归范围固定为 README §8.2 的 R1~R7，**不做全项目回归**。

---

## 批次 A：数据底座与权限（CS-101 ~ CS-103）

### CS-101 [DBA] 客服中心核心五表迁移
| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 数据底座 |
| 对应功能 | §6.1 表清单、§6.2 核心表结构 |
| 依赖 | — |
| 关联 | CS-102 ~ CS-117 全部依赖本任务；CS-201（二期扩表）沿用本任务命名与索引规范 |
| 预估 | 1.0 人天 |

**目标**：建立 `cs_ticket` / `cs_ticket_message` / `cs_ticket_type` / `cs_faq_category` / `cs_faq_article` 五表，SQLite 与 PG 双库迁移通过，为工单与 FAQ 提供数据底座。

**实现的功能**
- 服务工单主表：工单号、类型、关联订单、状态、优先级、处理人、满意度、时间锚点
- 工单消息表：用户/客服/系统三类消息 + 图片 + 内部备注标记
- 工单类型配置表（可自定义类型与「是否必须关联订单」）
- FAQ 分类表与文章表（含浏览/有帮助计数、热门、发布状态）

**实现步骤**
1. 新建迁移（序号取 `database/migrations` 当前末位 +1，建议 `2026_09_17_000038_create_cs_ticket_tables.php` 起连续 5 个，或合并为 1 个多表迁移 + 1 个索引迁移）：
   - `cs_ticket_type`：`id`、`name`(varchar 64)、`code`(varchar 32, unique)、`require_order`(boolean default false)、`sort`(smallint default 0)、`is_active`(boolean default true)、`timestamps`
   - `cs_ticket`：`id`、`ticket_no`(varchar 32, unique)、`user_id`(bigint)、`type_id`(bigint)、`order_id`(bigint nullable)、`title`(varchar 128)、`content`(text)、`status`(varchar 16, default `pending`)、`priority`(smallint default 0)、`assignee_id`(bigint nullable)、`contact`(varchar 64 nullable)、`satisfaction`(smallint nullable)、`satisfaction_remark`(varchar 255 nullable)、`satisfaction_at`(timestamp nullable)、`first_replied_at`(timestamp nullable)、`last_message_at`(timestamp nullable)、`completed_at`(timestamp nullable)、`closed_at`(timestamp nullable)、`close_reason`(varchar 32 nullable)、`timestamps`
   - `cs_ticket_message`：`id`、`ticket_id`(bigint)、`sender_type`(varchar 16：`user`/`staff`/`system`)、`sender_id`(bigint nullable)、`content`(text)、`images`(json nullable)、`is_internal`(boolean default false)、`created_at`
   - `cs_faq_category`：`id`、`name`(varchar 64)、`sort`(smallint default 0)、`is_active`(boolean default true)、`timestamps`
   - `cs_faq_article`：`id`、`category_id`(bigint)、`title`(varchar 191)、`summary`(varchar 255 nullable)、`content`(text)、`sort`(smallint default 0)、`is_hot`(boolean default false)、`status`(varchar 16 default `draft`：`draft`/`published`/`offline`)、`view_count`(integer default 0)、`helpful_count`(integer default 0)、`unhelpful_count`(integer default 0)、`published_at`(timestamp nullable)、`timestamps`
2. 索引：
   - `cs_ticket`：`ticket_no` unique、`[user_id, status]`、`[status, created_at]`、`[assignee_id, status]`、`[order_id]`、`[type_id]`
   - `cs_ticket_message`：`[ticket_id, created_at]`、`[ticket_id, is_internal]`
   - `cs_faq_article`：`[category_id, status, sort]`、`[status, is_hot]`
3. 双库兼容：JSON 用 `$table->json('images')->nullable()`（SQLite→text、PG→jsonb），**不写 PG 专有类型、不用数据库专有全文索引**；时间统一 `timestamps()`；布尔统一 `boolean`。
4. 外键策略：与既有表一致（若既有迁移未强制外键，则只建索引不建 FK 约束，避免双库差异）；`user_id` 指向 `users`、`order_id` 指向 `orders`、`assignee_id` 指向 `sys_user`、`type_id` 指向 `cs_ticket_type`。
5. **不做软删除**（决策 D4）：工单是服务凭证，只能关闭不能删。
6. `down()` 内按外键逆序 `Schema::dropIfExists()`，保证可回滚。
7. 双库执行：`php artisan migrate`（PG 开发库）、`php artisan migrate --env=testing`（SQLite）均通过；`migrate:rollback --step=N` 可完整回滚且可再次 migrate。

**测试要求**
- 单元测试：表/字段/索引存在性断言（`Schema::hasTable`、`Schema::hasColumn`、`$connection->getDoctrineSchemaManager()` 或 `getIndexListing`）。
- 集成测试：双库下分别插入 1 条类型 + 1 条工单 + 1 条消息 + 1 篇文章，验证外键关联、JSON `images` 读写、默认值生效（`status=pending`、`priority=0`、`is_internal=false`）。
- 回归测试：**R1 订单**、**R2 退款**（新增表不得影响既有订单/退款用例）；其余回归项本任务不涉及。

**验收标准（Acceptance Criteria）**
- AC-101.1 五表在 SQLite 与 PG 下均创建成功，`php artisan migrate:status` 全部为 `Ran`。
- AC-101.2 字段清单、类型、默认值、索引与设计文档 §6.2 逐项一致（差异需在证据中说明并评审确认）。
- AC-101.3 `migrate:rollback` 后五表全部消失，再次 `migrate` 成功（幂等可重复）。
- AC-101.4 `images`（JSON）在双库下写入 `["/storage/cs/a.png"]` 后读取结构一致。
- AC-101.5 既有订单/退款相关用例无新增失败。

**验收证据**：`sql-CS-101.md`（建表 SQL + 索引清单 + 字段核对表）、`migrate-status-CS-101.txt`（双库）、`pest-CS-101.txt`、`pest-pgsql-CS-101.txt`、`commit-CS-101.txt`

---

### CS-102 [BE] 模型、状态机常量与默认数据 Seeder
| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 数据底座（核心逻辑） |
| 对应功能 | §6.2、§4.2 状态流转 |
| 依赖 | CS-101 |
| 关联 | CS-104、CS-105 直接依赖；CS-106/108/109/110 下游消费 |
| 预估 | 1.0 人天 |

**目标**：把工单状态、消息发送方、优先级等枚举固化为模型常量，定义状态流转矩阵，并预置默认工单类型与 FAQ 分类，让后续任务有统一的语义底座。

**实现的功能**
- `CsTicket` / `CsTicketMessage` / `CsTicketType` / `FaqCategory` / `FaqArticle` 五个模型与关联关系
- 状态常量与流转矩阵 `CsTicket::TRANSITIONS`、`canTransitTo()`
- 默认数据：8 个工单类型、5 个 FAQ 分类、若干示例文章（草稿态）

**实现步骤**
1. 模型与关系：
   - `CsTicket`：`belongsTo` type / user / order / assignee；`hasMany` messages；`$casts`：`images`→array、`status`→string、`satisfaction`→integer；`created_at/closed_at` 等时间字段→`datetime`
   - `CsTicketMessage`：`belongsTo` ticket；`images` cast array；`is_internal` cast boolean
   - `FaqArticle`：`belongsTo` category；`view_count/helpful_count` integer
2. 状态常量（`CsTicket`）：
   - `STATUS_PENDING = 'pending'`（待处理）、`STATUS_PROCESSING = 'processing'`（处理中）、`STATUS_WAITING_USER = 'waiting_user'`（等待用户回复）、`STATUS_COMPLETED = 'completed'`（已完成）、`STATUS_CLOSED = 'closed'`（已关闭）
   - `PRIORITY_NORMAL = 0`、`PRIORITY_URGENT = 1`
   - `CLOSE_REASON_USER/SYSTEM/STAFF/TIMEOUT`
   - `STATUS_LABELS` 中文映射（供前后端共用语义）
3. 流转矩阵 `TRANSITIONS`（**一期定稿版**）：
   - `pending` → `processing`、`closed`
   - `processing` → `waiting_user`、`completed`、`closed`
   - `waiting_user` → `processing`、`closed`
   - `completed` → `closed`
   - `closed` →（终态，不可再流转）
   - 非法流转统一抛业务冲突（沿用 40009），消息写入 `order_logs` 同款的写日志约定（本模块写 `cs_ticket_message` 的 `system` 消息）
4. `CsTicketService::canTransitTo(CsTicket $t, string $to): bool` 与 `assertTransitable()`（非法时抛异常），**控制器禁止直接改 `status`**（决策 D5）。
5. `CsTicketTypeSeeder`：8 个默认类型（售前咨询、物流问题、商品质量、退换货、支付问题、账户问题、投诉建议、其他），其中物流/质量/退换货 `require_order = true`。
6. `FaqCategorySeeder`：5 个分类（购物指南、物流配送、支付问题、售后政策、账户安全）+ 每类 1~2 篇示例文章（`status=draft`，供 CS-115 验证发布流程）。
7. Seeder 必须**幂等**（`updateOrCreate` 按 `code`/`name` 判重），可重复执行。

**测试要求**
- 单元测试（≥8 条，`tests/Unit/CsTicketStateMachineTest.php`）：
  1. 每条合法流转返回 true；
  2. 每条非法流转（如 `closed → processing`、`pending → completed`）返回 false 且 `assertTransitable()` 抛异常；
  3. `STATUS_LABELS` 覆盖全部状态；
  4. 默认类型种子中 `require_order` 标记正确；
  5. Seeder 重复执行不产生重复记录；
  6. 模型 casts 生效（`images` 数组、`is_internal` 布尔）；
  7. 关联关系可用（`$ticket->messages`、`$ticket->order`）；
  8. 优先级常量与默认值为 0。
- 集成测试：`$this->seed(CsTicketTypeSeeder::class)` 后调用用户端类型接口可返回 8 条（与 CS-104 联调）。
- 回归测试：**R3 通知**（无耦合改动）、**R4 权限**（无耦合改动）—— 本任务仅确认不破坏，实际在 CS-103 后执行。

**验收标准（Acceptance Criteria）**
- AC-102.1 五个模型与关联可用，`$casts` 对 JSON/布尔/时间生效。
- AC-102.2 状态流转矩阵与设计文档 §4.2 一致，非法流转 100% 被拦截（用例全覆盖）。
- AC-102.3 默认 8 个工单类型、5 个 FAQ 分类落库，且 Seeder 可重复执行不产生重复数据。
- AC-102.4 状态与类型标签中文映射完整，前后端共用同一份常量来源。

**验收证据**：`pest-CS-102.txt`（单元用例输出）、`sql-CS-102.md`（种子数据核对）、`commit-CS-102.txt`

---

### CS-103 [DBA] 客服权限码与角色授权迁移
| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 权限 |
| 对应功能 | §4 后台管理端、§8 权限 |
| 依赖 | —（可与 CS-101 并行） |
| 关联 | CS-105、CS-108、CS-109、CS-110 依赖；CS-212（二期数据权限）在本任务之上扩展 |
| 预估 | 0.5 人天 |

**目标**：新增客服中心权限码并授权给运营/客服角色，让后台接口可按权限控制访问（一期只做「能否进入模块」，不做数据范围隔离）。

**实现的功能**
- 权限码 `cs.ticket.view`（查看工单）、`cs.ticket.handle`（处理工单：回复/改状态/转交）、`cs.faq.manage`（FAQ 维护）
- `operator` 角色默认获得 `cs.ticket.view` + `cs.ticket.handle` + `cs.faq.manage`
- 后台侧栏「客户服务」菜单按权限显隐

**实现步骤**
1. 在 `RolePermissionSeeder::PERMISSIONS` 中新增 3 个权限码（保持 `<域>.<动作>` 风格）。
2. **同步新增幂等迁移**（存量环境不会重跑 seeder，这是硬性要求），范例 `2026_09_17_000036_add_shipping_manage_permission.php`：
   - `permissionTablesExist()` 守卫 → `app()['cache']->forget('spatie.permission.cache')` 或 `Permission::forgetCachedPermissions()`
   - `Permission::findOrCreate('cs.ticket.view', 'web')`（3 个码循环）
   - 给 `operator` 角色 `givePermissionTo([...])`
   - 再次清理权限缓存
3. 迁移可重复执行（`findOrCreate` + `hasPermissionTo` 判重），回滚时移除授权但保留权限码（避免影响他人引用）。
4. 执行 `php artisan permission:cache-reset` 验证生效。
5. 编写/更新校验用例：3 个权限码存在且 `operator` 具备（对齐既有 `PermissionSyncTest` 风格）。

**测试要求**
- 单元测试：权限码常量与 `RolePermissionSeeder::PERMISSIONS` 一致（防止只在迁移里加、seeder 漏加）。
- 集成测试（≥5 条）：
  1. 迁移执行后 3 个权限码存在；
  2. `operator` 角色具备 3 个权限；
  3. 无权限账号访问 `GET /api/admin/cs/tickets` 返回 403；
  4. 超管账号可访问（Gate::before 绕过）；
  5. 迁移重复执行不报错、权限不重复。
- 回归测试：**R4 权限**（既有权限用例全绿，确认新增权限码对既有角色无副作用）。

**验收标准（Acceptance Criteria）**
- AC-103.1 `cs.ticket.view` / `cs.ticket.handle` / `cs.faq.manage` 三个权限码在 DB 与 `RolePermissionSeeder::PERMISSIONS` 中同时存在。
- AC-103.2 `operator` 角色默认持有 3 个权限（新环境 seeder、存量环境迁移两条路径都成立）。
- AC-103.3 无权限账号访问客服后台接口返回 403，超管不受限。
- AC-103.4 迁移幂等（连续执行两次无异常、无重复数据）。

**验收证据**：`pest-CS-103.txt`（含 `PermissionSync` 相关用例）、`sql-CS-103.md`（权限与角色授权查询结果）、`commit-CS-103.txt`

---

## 批次 B：用户端能力（CS-104 ~ CS-107）

### CS-104 [BE] 用户端 FAQ 接口（分类 / 搜索 / 详情 / 反馈）
| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 服务闭环 |
| 对应功能 | §3.1 帮助中心（FAQ） |
| 依赖 | CS-102 |
| 关联 | CS-112（前端）、CS-110（后台维护同一批数据）、CS-305（三期机器人复用搜索） |
| 预估 | 1.5 人天 |

**目标**：用户端可浏览分类与文章、按关键词搜索、查看详情并记录「是否有帮助」反馈，为「自助优先」提供数据接口。

**实现的功能**
- 分类列表（含每类已发布文章数）
- 文章列表（按分类/关键词分页，关键词高亮所需字段）
- 文章详情（浏览量自增、同分类相关问题推荐）
- 「是否有帮助」反馈（有帮助/无帮助计数）

**实现步骤**
1. 路由（挂 `middleware(['auth:sanctum','account.active'])` 分组内，与既有 `/me/notifications` 同风格）：
   - `GET /api/cs/faq/categories`
   - `GET /api/cs/faq/articles?category_id=&keyword=&page=`
   - `GET /api/cs/faq/articles/{id}`
   - `POST /api/cs/faq/articles/{id}/feedback`
2. 控制器 `CsFaqController`（用户端），响应统一走 `App\Support\ApiResponse::success()`。
3. 服务层 `FaqService`：
   - `categories()`：只返回 `is_active=1` 的分类，按 `sort` 升序，附带 `published_count`
   - `articles($categoryId, $keyword, $perPage)`：只返回 `status=published`；关键词用 `LIKE %keyword%` 匹配 `title`/`summary`/`content`（**决策 D3：不用数据库专有全文索引，保证 SQLite/PG 双库一致**）；结果按 `is_hot` desc、`sort` asc、`id` desc 排序
   - `detail($id)`：文章 + 分类 + 同分类其他已发布文章（≤5 条）；**浏览量自增用 `increment()`（避免并发下读改写丢失）**
   - `feedback($id, $helpful)`：`helpful` 为 true → `helpful_count+1`，否则 `unhelpful_count+1`
4. 参数校验：keyword 长度 ≤ 50 且去除首尾空格；`category_id` 必须存在且激活；`per_page` ≤ 50。
5. 缓存策略（可选）：分类列表缓存 5 分钟，文章发布/下架时清缓存（CS-110 联动）。
6. 未发布（`draft`/`offline`）文章对用户端不可见，直接 404（不暴露存在性）。

**测试要求**
- 单元测试（≥4 条，`tests/Unit/FaqServiceTest.php`）：关键词归一化（去空格/长度截断）；只返回已发布文章；分类排序规则；浏览量自增用 `increment` 而非赋值。
- 集成测试（≥8 条，`tests/Feature/CsFaqApiTest.php`，文件头 `uses(RefreshDatabase::class);`）：
  1. 分类列表只含激活分类且计数正确；
  2. 文章列表按分类筛选；
  3. 关键词搜索命中标题/摘要/正文；
  4. 草稿与下架文章不出现在列表与详情（详情 404）；
  5. 详情浏览量 +1，重复访问累加；
  6. 详情返回同分类推荐（≤5 且不含自身）；
  7. 反馈接口分别累加 helpful/unhelpful；
  8. 未登录访问返回 401；非法 `category_id` 返回 422。
- 回归测试：**R3 通知**（无耦合）、**R4 权限**（新增路由不得影响既有鉴权）—— 仅确认全绿。

**验收标准（Acceptance Criteria）**
- AC-104.1 四个接口在 SQLite 与 PG 下行为一致（同一份用例双库通过）。
- AC-104.2 草稿/下架文章对用户端完全不可见（列表无、详情 404）。
- AC-104.3 关键词搜索在标题/摘要/正文三处命中，结果排序为 热门优先 → sort → 时间倒序。
- AC-104.4 浏览量并发自增不丢失（10 并发请求后 `view_count` 恰好 +10）。
- AC-104.5 反馈计数正确累加，重复提交不报错。

**验收证据**：`pest-CS-104.txt`、`pest-pgsql-CS-104.txt`、`curl-CS-104.md`（四个接口请求/响应）、`sql-CS-104.md`（浏览量并发核对）、`commit-CS-104.txt`

---

### CS-105 [BE] CsTicketService 服务层（建单 / 消息 / 状态机）
| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 服务闭环（**关键路径，正确性最高优先级**） |
| 对应功能 | §3.3 提交服务工单、§4.2 状态流转、§5.1/§5.2 流程 |
| 依赖 | CS-102、CS-103 |
| 关联 | CS-106、CS-108、CS-109 直接消费；CS-107（通知）挂在本服务的钩子上；CS-116 专项测试主对象 |
| 预估 | 2.5 人天 |

**目标**：把「建单 → 写消息 → 状态流转 → 权限可见性」全部收敛到一个服务层，控制器不得绕过。**这是本模块最需要正确性的代码**（对齐 `OrderService::transitionTo()` 的既有约定）。

**实现的功能**
- 建单（工单号生成、类型校验、订单归属校验、初始消息落库、时间锚点）
- 追加消息（用户/客服/系统，支持图片与内部备注）
- 状态流转（矩阵校验 + 副作用：首次回复时间、完成时间、关闭时间、系统消息）
- 发送方可见性判定（内部备注只对客服可见）
- 用户数据范围（只能看自己的工单）与客服数据范围（一期可见全部，为 CS-212 预留接口）

**实现步骤**
1. `app/Services/Cs/CsTicketService.php`：
   - `generateTicketNo(): string` → `CS` + `date('Ymd')` + 4 位序号。实现：`DB::transaction` 内插入占位记录取 `id`，回填 `ticket_no = 'CS'.date('Ymd').str_pad($id, 4, '0', STR_PAD_LEFT)`（自增 ID 唯一 → 天然不冲突；`ticket_no` 唯一索引兜底，冲突时重试最多 3 次并记录告警日志）。
   - `createTicket(User $user, array $data): CsTicket`：
     1. 校验 `type_id` 存在且激活；
     2. `require_order=true` 的类型必须传 `order_id`，且订单归属于当前用户（否则 40000）；
     3. 事务内建单 + 写第一条 `sender_type=user` 的消息（内容 = `content`，图片 = `images`）；
     4. 置 `status=pending`、`last_message_at=now()`。
   - `addMessage(CsTicket $t, array $data): CsTicketMessage`：`sender_type` ∈ `user`/`staff`/`system`；`images` ≤ 9 张；`is_internal` 仅 `staff` 可写 true（其余强制 false）；更新 `last_message_at`；若 `sender_type=staff` 且 `first_replied_at` 为空则写入首响时间。
   - `transitionTo(CsTicket $t, string $to, ?int $operatorId, string $reason = ''): CsTicket`：
     1. `DB::transaction` → `lockForUpdate()` 重读工单 → `assertTransitable()`（非法 40009）；
     2. 写状态与时间锚点（`completed_at`/`closed_at`/`close_reason`）；
     3. 写一条 `sender_type=system` 的流转消息（用户端可见，内部备注为 false）；
     4. 触发通知钩子（CS-107 接入）。
   - `visibleMessages(CsTicket $t, bool $isStaff): Collection`：非客服侧过滤 `is_internal=true`。
   - `scopeForUser()` / `scopeForStaff()` 查询作用域（一期客服可见全部，接口预留为 CS-212 留挂点）。
2. 自动流转规则（写进服务层，不由控制器判断）：
   - 客服回复 → 若当前 `pending` 自动置 `processing`；若 `waiting_user` 自动回 `processing`
   - 用户追加回复 → 若当前 `waiting_user`/`completed` 自动回 `processing`（`pending` 不变）
3. 权限判定：`assertOwner(CsTicket, User)` —— 非本人访问返回 404（不暴露存在性）。
4. 幂等与并发：状态流转在事务内加行锁，重复提交相同状态变更返回成功但不重复写消息（以当前状态判定）。
5. 写操作记录 `sys_operation_log`（与既有后台写操作日志约定一致）。

**测试要求**
- 单元测试（≥14 条，`tests/Unit/CsTicketServiceTest.php`）：
  1. 工单号格式 `CS202609170001` 且唯一；
  2. 跨天建单序号按天重置（同一天连续、不同天重新计数）；
  3. 必填订单类型不传 `order_id` 被拒；
  4. 传他人订单被拒（40000）；
  5. `require_order=false` 类型可不传订单；
  6. 建单后存在第一条 `user` 消息且内容/图片一致；
  7. 内部备注只能由 `staff` 写入（其他发送方强制 false）；
  8. 图片超过 9 张被拒；
  9. 客服首条回复写入 `first_replied_at`，第二条回复不覆盖；
  10. 客服回复自动 `pending → processing`、`waiting_user → processing`；
  11. 用户追加回复自动回 `processing`；
  12. 非法流转被拦截（40009）且不写消息；
  13. 关闭写入 `closed_at` 与 `close_reason`；
  14. 重复提交同一状态变更幂等（消息不重复增长）。
- 集成测试（≥6 条）：并发建单 50 次工单号无重复；并发状态变更只有一次生效；`addMessage` 后 `last_message_at` 更新；内部备注在用户可见性过滤后消失；非本人访问 404；事务回滚后无残留消息。
- 回归测试：**R1 订单**（工单关联订单不得影响订单用例）、**R2 退款**（无耦合）、**R4 权限**。

**验收标准（Acceptance Criteria）**
- AC-105.1 工单号全局唯一（50 并发建单无重复、无冲突异常）。
- AC-105.2 状态流转 100% 经过 `transitionTo()`，控制器中无任何直接写 `status` 的代码（以 grep 结果为准）。
- AC-105.3 非法流转一律 40009，且**不产生任何消息或日志副作用**（事务回滚验证）。
- AC-105.4 内部备注对客服可见、对用户不可见（接口返回体与 SQL 双重核对）。
- AC-105.5 首响时间只记录一次；自动流转规则 6 条全部按预期触发。
- AC-105.6 归属校验生效：用户只能读写自己的工单（越权 404）。

**验收证据**：`pest-CS-105.txt`、`pest-pgsql-CS-105.txt`、`loadtest-CS-105.md`（50 并发建单结论）、`sql-CS-105.md`（工单号唯一性与消息核对）、`grep-CS-105.txt`（状态写入点唯一性检查）、`commit-CS-105.txt`

---

### CS-106 [BE] 用户端工单接口（提交 / 列表 / 详情 / 追加回复 / 关闭）
| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 服务闭环 |
| 对应功能 | §3.3 提交服务工单、§3.4 我的工单、§7 接口概要（用户端） |
| 依赖 | CS-105 |
| 关联 | CS-113（前端）、CS-107（通知）、CS-108（后台同一批数据） |
| 预估 | 2.0 人天 |

**目标**：用户可提交工单、按状态筛选查看自己的工单、在详情页对话式沟通、主动关闭工单。

**实现的功能**
- 提交工单（含类型列表接口、凭证图片）
- 我的工单列表（状态筛选 + 分页）
- 工单详情（基本信息 + 关联订单摘要 + 消息流，内部备注过滤）
- 追加回复（文字 + 图片）
- 用户关闭工单

**实现步骤**
1. 路由（`auth:sanctum` + `account.active` 分组内）：
   - `GET /api/cs/ticket-types`
   - `POST /api/cs/tickets`
   - `GET /api/cs/tickets?status=&page=`
   - `GET /api/cs/tickets/{id}`
   - `POST /api/cs/tickets/{id}/messages`
   - `POST /api/cs/tickets/{id}/close`
2. `CsTicketController`（用户端）：
   - `store` 校验：`type_id` 必填且有效、`title` ≤ 128、`content` 必填且 ≤ 2000、`order_id` 条件必填（按类型）、`images` 数组 ≤ 9 且为合法 URL 路径、`contact` 选填（默认取账号手机/邮箱）
   - `index`：只返回当前用户工单；`status` 支持 `all/pending/processing/waiting_user/completed/closed`；分页 10 条；按 `last_message_at` 倒序
   - `show`：工单 + 类型 + 消息流（过滤内部备注）+ 关联订单摘要（订单号、金额、状态、商品首图）；非本人 404
   - `messages`：`content` 与 `images` 至少其一非空；`completed`/`closed` 状态允许追加（追加后按 CS-105 规则回流 `processing`）
   - `close`：仅 `pending/processing/waiting_user/completed` 可关闭，走 `transitionTo(closed, reason=user)`
3. 限流：建单与追加回复加 `throttle`（建议 10/分钟，与既有 `order` 限流风格一致），防刷单与灌水。
4. 响应字段统一：工单对象含 `ticket_no`、`status_label`、`type_name`、`message_count`、`last_message_at`、`can_close`、`can_reply` 等前端判定字段（避免前端复制状态逻辑）。
5. 错误码：参数校验 422、业务冲突 40009、越权 404、限流 429。

**测试要求**
- 单元测试（≥3 条）：`can_close`/`can_reply` 判定矩阵；状态筛选参数归一化；`contact` 默认值取账号信息。
- 集成测试（≥12 条，`tests/Feature/CsTicketApiTest.php`）：
  1. 提交成功返回工单号且初始消息落库；
  2. 必填订单类型缺 `order_id` → 422；
  3. 传他人订单 → 40000；
  4. 标题/描述超长 → 422；
  5. 图片超过 9 张 → 422；
  6. 未登录 → 401；
  7. 列表只含本人工单（他人工单不出现）；
  8. 列表按状态筛选正确（5 个状态逐一验证）；
  9. 详情消息流按时间正序且**不含内部备注**；
  10. 详情含关联订单摘要；
  11. 追加回复成功且状态自动回流 `processing`；
  12. 用户关闭成功，再次关闭返回冲突（40009）；
  13. 限流生效（连续 11 次建单第 11 次 429）。
- 回归测试：**R1 订单**（详情页读取订单摘要，确认订单接口响应未被改动）、**R3 通知**（建单触发通知）、**R4 权限**。

**验收标准（Acceptance Criteria）**
- AC-106.1 六个用户端接口全部按设计文档 §7 的路径与语义实现，双库用例通过。
- AC-106.2 用户侧**任何情况下**看不到内部备注（接口响应断言 + 数据库核对）。
- AC-106.3 越权访问一律 404，且不泄露工单是否存在。
- AC-106.4 状态筛选 5 个分组结果准确，分页总数与数据库一致。
- AC-106.5 关闭为终态：已关闭工单不可再回复/再关闭（40009）。
- AC-106.6 建单与回复限流生效（429）。

**验收证据**：`pest-CS-106.txt`、`pest-pgsql-CS-106.txt`、`curl-CS-106.md`（六接口请求/响应全记录）、`sql-CS-106.md`（越权与内部备注核对）、`commit-CS-106.txt`

---

### CS-107 [BE] 工单图片上传与站内通知
| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 服务闭环 |
| 对应功能 | §3.3 凭证图片、§8 消息通知 |
| 依赖 | CS-105 |
| 关联 | CS-113（前端上传与消息展示）、CS-109（客服回复同样触发通知） |
| 预估 | 1.0 人天 |

**目标**：用户与客服可上传凭证/回复图片；工单关键动作（新工单、客服回复、状态变更）通过站内信触达对应接收方。

**实现的功能**
- 用户端工单图片上传接口（复用 `FileUploadService`）
- 新工单通知客服（角色维度）
- 客服回复/状态变更通知用户
- 通知跳转链接（`link` 指向工单详情路由）

**实现步骤**
1. 上传接口 `POST /api/cs/upload-image`（`auth:sanctum` + `account.active` + `throttle`）：
   - 复用 `App\Services\Common\FileUploadService::uploadImage($file, 'cs')`
   - 校验 `image|max:5120`（与既有上传一致）；返回 `{ url }`（走 `ApiResponse::success()`）
   - 单条消息图片 ≤ 9 张（在 CS-105/106 已校验，本任务保证上传侧提示一致）
2. 通知服务 `app/Services/Cs/CsNotificationService.php`（封装在既有 `NotificationService` 之上，不重复造通道）：
   - `notifyNewTicket(CsTicket $t)`：优先 `NotificationService::sendToRole('operator', 'cs.ticket.new', '新服务工单', "{$t->ticket_no} {$t->title}", "/cs/tickets/{$t->id}")`
   - `notifyUserReply(CsTicket $t, CsTicketMessage $m)`：`NotificationService::send($t->user_id, 'cs.ticket.reply', ...)`（**内部备注不通知用户**）
   - `notifyStatusChanged(CsTicket $t, string $to)`：通知用户状态变更文案
   - **决策 D7**：若现有 `NotificationService` 仅覆盖买家端（`Notification::RECEIVER_CUSTOMER`），本任务需扩展管理员接收端分支（`receiver_type` + 后台未读接口）；若扩展成本超 0.5 人天，降级为「工作台待处理计数红点」，站内信后置。
3. 钩子接入 `CsTicketService`：建单后、客服消息后、状态变更后调用（**内部备注不触发用户通知**）。
4. 通知内容脱敏：不包含用户手机号全号（如需展示，按既有脱敏规则处理）。
5. 失败不阻断主流程：通知异常捕获并记录日志，不得让建单/回复失败。

**测试要求**
- 单元测试（≥3 条）：内部备注不产生用户通知；通知文案与跳转链接拼接正确；通知失败不影响主流程（异常被吞并记录）。
- 集成测试（≥6 条）：
  1. 上传接口返回 `{url}` 且文件落盘到 `cs` 目录；
  2. 非图片文件被拒（422）；超过 5MB 被拒（422）；
  3. 未登录上传 401；
  4. 建单后 `operator` 角色收到 1 条站内信；
  5. 客服普通回复后用户收到 1 条站内信，内部备注后**用户收不到**；
  6. 状态变更（completed/closed）后用户收到站内信。
- 回归测试：**R3 通知**（既有通知用例全绿，确认新增类型不破坏既有未读计数）、**R6 上传**（既有上传用例全绿）。

**验收标准（Acceptance Criteria）**
- AC-107.1 图片上传复用 `FileUploadService`，返回结构与既有上传接口一致；非法类型/超限被拒。
- AC-107.2 新工单、客服回复、状态变更三个动作均产生站内信，接收方与内容正确。
- AC-107.3 **内部备注绝不触发用户通知**（关键隐私项，用例硬断言）。
- AC-107.4 通知异常不影响主流程（模拟通知服务抛错，建单仍成功）。
- AC-107.5 既有通知与上传用例无新增失败。

**验收证据**：`pest-CS-107.txt`、`curl-CS-107.md`（上传 + 通知触发验证）、`sql-CS-107.md`（notifications 表记录核对）、`commit-CS-107.txt`

---

## 批次 C：后台能力（CS-108 ~ CS-110）

### CS-108 [BE] 后台工单列表与详情（多条件筛选）
| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 服务闭环 |
| 对应功能 | §4.1 工单工作台 |
| 依赖 | CS-103、CS-105 |
| 关联 | CS-109（处理动作）、CS-114（前端工作台）、CS-212（二期数据权限扩展本接口） |
| 预估 | 1.5 人天 |

**目标**：客服在后台可按状态、类型、时间、订单号/工单号/手机号、处理人、优先级多条件筛选工单，并查看详情（含用户与订单信息）。

**实现的功能**
- 工单列表（6 类筛选 + 分页 + 排序）
- 工单详情（基本信息 + 消息流含内部备注 + 用户摘要 + 关联订单摘要）
- 列表统计条（待处理数量，供工作台红点）

**实现步骤**
1. 路由（`Route::prefix('admin')` 内，逐条挂 `permission` 中间件）：
   - `GET /api/admin/cs/tickets` → `middleware('permission:cs.ticket.view')`
   - `GET /api/admin/cs/tickets/{id}` → `middleware('permission:cs.ticket.view')`
2. `Admin\CsTicketController@index` 筛选参数：
   - `status`（pending/processing/waiting_user/completed/closed）
   - `type_id`
   - `created_start` / `created_end`（时间范围，校验起止顺序）
   - `keyword`（匹配 `ticket_no` 精确 / `order_no` / 用户手机号，三选一自动识别）
   - `assignee_id`（处理人）
   - `priority`
   - 排序：默认 `last_message_at` 倒序；支持 `created_at`/`priority` 排序
   - 分页：默认 20，最大 100
3. `show` 返回：工单 + 类型 + 用户摘要（昵称/手机尾号/注册时间/历史工单数）+ 关联订单摘要（订单号/金额/状态/下单时间/商品首图）+ 消息流（**含内部备注**，标注 `is_internal`）+ 可用操作列表（`can_reply`/`can_complete`/`can_close`）。
4. 性能：`with(['type','user','order','assignee'])` 预加载，避免 N+1；消息流单独分页或限制最近 50 条（避免长工单拖慢）。
5. 统计条：`GET /api/admin/cs/tickets` 响应 `meta` 中附 `pending_count`（待处理数），供侧栏红点。
6. 写操作与查询均记 `sys_operation_log`（查询不记，仅写操作记）。

**测试要求**
- 单元测试（≥3 条）：`keyword` 自动识别（工单号/订单号/手机号）逻辑；时间范围参数校验（起>止 被拒）；`can_*` 操作矩阵。
- 集成测试（≥9 条，`tests/Feature/AdminCsTicketApiTest.php`）：
  1. 无 `cs.ticket.view` 权限 → 403；
  2. 按 5 个状态分别筛选结果准确；
  3. 按类型/处理人/优先级筛选准确；
  4. 时间范围筛选（含边界日期）；
  5. 关键词分别命中工单号、订单号、手机号；
  6. 详情含内部备注且 `is_internal` 标记正确；
  7. 详情含用户与订单摘要；
  8. `meta.pending_count` 与数据库统计一致；
  9. 分页总数正确、排序符合预期；
  10. 无 N+1（以查询日志条数断言，DB 查询次数 ≤ 阈值）。
- 回归测试：**R1 订单**（读取订单摘要）、**R4 权限**（新增后台路由的 403 分支）、**R5 报表**（无耦合，确认全绿）。

**验收标准（Acceptance Criteria）**
- AC-108.1 六类筛选条件单独与组合使用均返回正确结果（用例覆盖单条件 + 一次三条件组合）。
- AC-108.2 详情对客服展示内部备注，且明确标注；用户端接口不受影响（与 AC-106.2 对照验证）。
- AC-108.3 无权限访问 403，有权限 200（超管绕过）。
- AC-108.4 列表查询无 N+1（查询日志 ≤ 5 条主查询）。
- AC-108.5 `meta.pending_count` 与 SQL `count(*)` 一致。

**验收证据**：`pest-CS-108.txt`、`curl-CS-108.md`（筛选组合请求/响应）、`sql-CS-108.md`（筛选结果与统计核对）、`commit-CS-108.txt`

---

### CS-109 [BE] 后台工单处理（回复 / 内部备注 / 状态变更 / 转交 / 优先级）
| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 服务闭环（核心） |
| 对应功能 | §4.2 工单处理详情、§5.2 客服处理流程 |
| 依赖 | CS-108 |
| 关联 | CS-114（前端操作区）、CS-107（回复触发通知）、CS-204（二期快捷回复插入同一入口） |
| 预估 | 2.0 人天 |

**目标**：客服可在工单详情完成全部处理动作：回复用户、写内部备注、变更状态、转交其他客服、标记优先级。

**实现的功能**
- 客服回复（富文本/图片，触发通知 + 自动流转）
- 内部备注（仅客服可见，不通知用户）
- 状态变更（走 `transitionTo`，支持等待用户回复/完成/关闭）
- 转交客服（`assignee_id`）
- 优先级标记（普通/紧急）
- 批量分配（MVP 最小版：批量转交，批量关闭后置 CS-212）

**实现步骤**
1. 路由（均挂 `permission:cs.ticket.handle`）：
   - `POST /api/admin/cs/tickets/{id}/messages`（`content` 或 `images` 至少其一；`is_internal` 布尔）
   - `PUT /api/admin/cs/tickets/{id}/status`（`status` + 可选 `remark`）
   - `PUT /api/admin/cs/tickets/{id}/assign`（`assignee_id`）
   - `PUT /api/admin/cs/tickets/{id}/priority`（`priority` 0/1）
   - `POST /api/admin/cs/tickets/batch-assign`（`ids[]` + `assignee_id`，MVP 版）
2. 控制器 `Admin\CsTicketController`：**所有状态写入一律调用 `CsTicketService::transitionTo()`**，禁止直接 `update(['status'=>...])`。
3. 权限与归属：
   - `cs.ticket.handle` 才能写；仅查看权限账号调用写接口 → 403
   - 一期不限制「只能处理自己的工单」（CS-212 扩展），但记录 `assignee_id`
4. 副作用与日志：
   - 客服回复 → 通知用户 + 自动流转（见 CS-105 规则）+ 首次回复写 `first_replied_at`
   - 转交 → 写 `system` 消息「工单已转交给 X」+ 通知新处理人
   - 优先级变更 → 写 `system` 消息（紧急时高亮）
   - 所有写操作记 `sys_operation_log`（before/after）
5. 幂等：重复提交相同状态变更不重复写系统消息（当前状态已等于目标状态时返回成功且不写消息）。
6. 并发：状态变更走 `lockForUpdate`，两个客服同时操作只有一个生效，另一个收到 40009。

**测试要求**
- 单元测试（≥4 条）：写操作权限矩阵（`view` 不可写、`handle` 可写）；幂等判定（当前状态 == 目标状态）；转交消息文案与接收人；优先级变更消息生成。
- 集成测试（≥12 条）：
  1. 仅查看权限调用回复接口 → 403；
  2. 客服回复成功，用户收到通知，状态 `pending → processing`；
  3. 内部备注写入后用户在用户端详情看不到（跨接口验证）；
  4. 内部备注不触发用户通知（与 AC-107.3 呼应）；
  5. 状态变更为 `waiting_user` / `completed` / `closed` 分别成功；
  6. 非法状态变更（`closed → processing`）→ 40009；
  7. 转交后 `assignee_id` 更新且产生系统消息；
  8. 优先级改为紧急后列表可按优先级筛选；
  9. 批量转交 3 条工单全部更新；
  10. 重复提交相同状态 → 幂等成功且消息数不增长；
  11. 并发状态变更只有一个成功（第二个 40009）；
  12. 写操作日志落 `sys_operation_log`。
- 回归测试：**R4 权限**、**R3 通知**（回复触发）、**R2 退款**（转交/状态变更不触碰退款数据）。

**验收标准（Acceptance Criteria）**
- AC-109.1 五个处理接口全部生效，且状态写入点唯一（grep 无控制器直写 `status`）。
- AC-109.2 内部备注对用户不可见且不通知用户（双重断言）。
- AC-109.3 非法流转与并发冲突均返回 40009，且不产生脏数据。
- AC-109.4 回复自动流转规则与 CS-105 定义完全一致（pending→processing、waiting_user→processing）。
- AC-109.5 转交/优先级均产生系统消息与操作日志，可追溯。
- AC-109.6 批量转交原子性：部分失败则整批回滚。

**验收证据**：`pest-CS-109.txt`、`pest-pgsql-CS-109.txt`、`curl-CS-109.md`（五接口 + 并发冲突演示）、`sql-CS-109.md`（消息流与操作日志核对）、`grep-CS-109.txt`、`commit-CS-109.txt`

---

### CS-110 [BE] 后台 FAQ 分类与文章管理接口
| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 内容运营 |
| 对应功能 | §4.3 FAQ / 帮助中心管理、§5.4 FAQ 内容维护流程 |
| 依赖 | CS-102、CS-103 |
| 关联 | CS-115（前端管理页）、CS-104（用户端消费同一批数据） |
| 预估 | 1.5 人天 |

**目标**：运营可在后台维护 FAQ 分类与文章（增删改、排序、发布/下架），并查看浏览与「有帮助」统计，内容发布后用户端即时可见。

**实现的功能**
- 分类 CRUD + 排序 + 启停
- 文章 CRUD（标题/摘要/正文/分类/排序/是否热门/状态）+ 预览
- 发布/下架状态流转（草稿 → 发布 → 下架）
- 浏览量与「有帮助」统计查看
- 发布后清理用户端分类缓存（与 CS-104 联动）

**实现步骤**
1. 路由（均挂 `permission:cs.faq.manage`）：
   - `GET/POST/PUT/DELETE /api/admin/cs/faq/categories`，`POST /api/admin/cs/faq/categories/sort`（批量排序）
   - `GET/POST/PUT/DELETE /api/admin/cs/faq/articles`
   - `POST /api/admin/cs/faq/articles/{id}/publish`、`/offline`
   - `GET /api/admin/cs/faq/articles/{id}/preview`
2. 校验规则：
   - 分类：`name` 必填 ≤ 64 且不重复；`sort` 整数 0~9999
   - 文章：`title` 必填 ≤ 191；`category_id` 必须存在且激活；`content` 必填；`status` ∈ `draft/published/offline`
3. 删除约束：**有已发布文章的分类不可删除**（返回 40009，提示先下架或迁移文章）；文章删除为**软下线**（置 `status=offline`）还是物理删除待评审 —— 建议 MVP 采用物理删除 + 二次确认，避免唯一索引与统计口径复杂化（与决策 D4 一致）。
4. 发布/下架：置 `status` 与 `published_at`；发布后清理分类缓存（CS-104 若启用缓存）。
5. 统计：`GET /api/admin/cs/faq/articles` 响应含 `view_count`、`helpful_count`、`unhelpful_count`；提供「有帮助率」计算字段 `helpful_rate = helpful/(helpful+unhelpful)`（分母为 0 时返回 `null`）。
6. 写操作记 `sys_operation_log`。

**测试要求**
- 单元测试（≥3 条）：有帮助率计算（分母 0 → null）；删除约束判定（有已发布文章的分类不可删）；排序参数归一化（重复 sort 值处理）。
- 集成测试（≥10 条，`tests/Feature/AdminCsFaqApiTest.php`）：
  1. 无 `cs.faq.manage` → 403；
  2. 分类创建/编辑/删除成功；
  3. 分类名重复 → 40009；
  4. 有已发布文章的分类删除被拒；
  5. 批量排序生效（列表顺序变化）；
  6. 文章创建默认 `draft`，用户端查不到；
  7. 发布后用户端列表与详情可见（跨接口联动验证）；
  8. 下架后用户端不可见（404）；
  9. 编辑已发布文章后立即生效；
  10. 统计字段与数据库一致；
  11. 非法 `category_id` → 422；
  12. 写操作日志落库。
- 回归测试：**R4 权限**、**R3 通知**（无耦合）、**R7 菜单**（新增菜单项权限过滤）。

**验收标准（Acceptance Criteria）**
- AC-110.1 分类与文章 CRUD 全部可用，权限控制生效（403 分支覆盖）。
- AC-110.2 草稿文章用户端不可见；发布后**无需重启/清缓存**立即可见；下架后立即 404。
- AC-110.3 有已发布文章的分类不可删除（40009）。
- AC-110.4 统计字段（浏览/有帮助/无帮助/有帮助率）与数据库一致，分母为 0 时返回 null 不报错。
- AC-110.5 所有写操作留痕（`sys_operation_log`）。

**验收证据**：`pest-CS-110.txt`、`curl-CS-110.md`（CRUD + 发布/下架联动）、`sql-CS-110.md`（统计核对）、`commit-CS-110.txt`

---

## 批次 D：前端（CS-111 ~ CS-115）

### CS-111 [WEB] 服务中心首页
| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 前端 |
| 对应功能 | §3.5 服务中心首页结构 |
| 依赖 | CS-104、CS-106 |
| 关联 | CS-112/113（跳转）、CS-213（二期增加公告与工单搜索） |
| 预估 | 1.5 人天 |

**目标**：用户端「客户服务」统一入口页，聚合搜索、快捷工具、热门问题、帮助分类、最近工单。

**实现的功能**
- 搜索框（进入帮助中心搜索，二期扩展搜工单）
- 快捷工具：查物流 / 申请退换货 / 联系客服
- 热门 FAQ（置顶）
- 帮助分类入口
- 我的工单最近 3 条 + 查看全部
- 个人中心「客户服务」入口挂载

**实现步骤**
1. 新建 `web/src/views/ServiceCenterView.vue`，路由 `/service-center`（`meta.requiresAuth: true`，与 `AccountCenterView.vue` 同风格）。
2. 布局按设计文档 §3.5 骨架：搜索区 → 快捷工具 → 热门问题 → 分类入口 → 最近工单 → 公告位（一期公告位占位不显示，二期 CS-213 接入）。
3. 数据：`GET /api/cs/faq/categories`、`GET /api/cs/faq/articles?is_hot=1&per_page=5`（若接口无 `is_hot` 参数，由 CS-104 补充）、`GET /api/cs/tickets?per_page=3`。
4. 快捷工具跳转：查物流 → `/orders`（订单列表）；申请退换货 → 可售后订单列表（复用 `RefundApplyView` 入口逻辑）；联系客服 → `/service-center/tickets/new`。
5. 个人中心入口：`AccountCenterView.vue` 增加「客户服务」菜单项。
6. 空态与加载态：无工单时展示引导文案；加载中骨架屏。
7. 移动端适配（H5）：单列布局，快捷工具 3 列宫格。

**测试要求**
- 前端单元测试（`web/tests/service-center.test.ts`，≥4 条）：
  1. 分类与热门问题渲染条数正确；
  2. 最近工单最多渲染 3 条且「查看全部」跳转正确；
  3. 无工单时展示空态；
  4. 快捷工具点击路由跳转正确（mock router）。
- 集成测试：Mock 接口返回，验证页面首屏渲染与错误态（接口 500 时展示重试）。
- 回归测试：个人中心既有用例全绿（`web/tests/account-center.test.ts`）；**R7** 仅后台侧栏相关，本任务不涉及。

**验收标准（Acceptance Criteria）**
- AC-111.1 `/service-center` 在登录态可访问，未登录跳登录页。
- AC-111.2 六个区块按设计顺序渲染，接口失败时区块降级不白屏。
- AC-111.3 最近工单最多 3 条，「查看全部」进入工单列表。
- AC-111.4 三个快捷工具跳转目标正确。
- AC-111.5 H5 视口（375px）下单列布局无横向滚动、无元素溢出。

**验收证据**：`vitest-CS-111.txt`、`ui-CS-111-desktop.png`、`ui-CS-111-mobile.png`、`commit-CS-111.txt`

---

### CS-112 [WEB] 帮助中心（分类 / 列表 / 搜索 / 详情 / 反馈）
| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 前端 |
| 对应功能 | §3.1 帮助中心（FAQ） |
| 依赖 | CS-104 |
| 关联 | CS-111（入口）、CS-113（详情页「联系客服」跳提单） |
| 预估 | 1.5 人天 |

**目标**：用户可浏览分类与文章列表、关键词搜索（高亮）、查看详情并反馈「是否有帮助」，详情页底部推荐相关问题。

**实现的功能**
- 分类页（一级分类 + 每类文章数）
- 文章列表（分类内分页）
- 搜索（关键词高亮 + 空结果引导）
- 文章详情（富文本渲染 + 浏览 + 反馈 + 相关推荐）
- 详情页「没解决？联系客服」入口（带入当前文章上下文）

**实现步骤**
1. 页面：`FaqCategoryView.vue`（分类）、`FaqListView.vue`（列表/搜索结果）、`FaqDetailView.vue`（详情）；路由 `/service-center/faq`、`/service-center/faq/list`、`/service-center/faq/{id}`。
2. 搜索：输入防抖 300ms；结果中关键词高亮（仅对 `title`/`summary` 做安全高亮，**正文内容不拼接 HTML**，避免 XSS；富文本正文用 `v-html` 渲染前需经后端/白名单清洗）。
3. 详情：渲染 `content`（富文本，`v-html` + 图片可点击预览）+ 底部「是否有帮助」二选一（提交后本地置灰，防重复提交）+ 同分类推荐（≤5）。
4. 无结果态：展示「没找到答案？」+ 「联系客服」按钮（跳转提单页并预填标题为搜索词）。
5. 面包屑与返回：分类 → 列表 → 详情三级可返回。
6. 移动端适配：分类横滑标签、详情正文字号与图片自适应（`max-width:100%`）。

**测试要求**
- 前端单元测试（`web/tests/faq.test.ts`，≥5 条）：
  1. 分类列表渲染与计数；
  2. 搜索防抖后只发出一次请求（mock timer）；
  3. 关键词高亮渲染（`<mark>` 标签数量正确）；
  4. 「是否有帮助」提交后置灰且只提交一次；
  5. 相关推荐不含当前文章；
  6. 空结果展示引导与「联系客服」按钮。
- 集成测试：mock 接口分页加载更多，验证列表累加正确。
- 回归测试：搜索与既有商品搜索无耦合；个人中心/订单用例确认全绿。

**验收标准（Acceptance Criteria）**
- AC-112.1 分类 → 列表 → 详情三级跳转与返回可用，面包屑正确。
- AC-112.2 搜索防抖生效（连续输入只触发一次请求），关键词高亮正确。
- AC-112.3 反馈提交一次成功后不可重复提交（UI 置灰 + 后端幂等）。
- AC-112.4 富文本正文渲染图片自适应，且不产生 XSS（以含 `<script>` 的样例内容验证被转义/过滤）。
- AC-112.5 H5 视口下无横向滚动。

**验收证据**：`vitest-CS-112.txt`、`ui-CS-112-list.png`、`ui-CS-112-detail.png`、`curl-CS-112.md`（搜索/详情接口联调）、`commit-CS-112.txt`

---

### CS-113 [WEB] 提交工单 / 我的工单 / 工单详情 + 订单详情入口
| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 前端（**用户侧闭环终点**） |
| 对应功能 | §3.3 提交服务工单、§3.4 我的工单 |
| 依赖 | CS-106、CS-107 |
| 关联 | CS-111/112（入口）、CS-206（二期评价 UI）、CS-215（二期自助工具） |
| 预估 | 2.5 人天 |

**目标**：用户可提交工单（选类型、关联订单、传凭证）、在列表按状态跟踪、在详情页对话式沟通并主动关闭。

**实现的功能**
- 提单表单（类型联动是否必选订单、凭证上传 ≤9 张、联系方式默认带出）
- 我的工单列表（状态 Tab + 分页）
- 工单详情（对话流、追加回复、补充凭证、关闭工单）
- 订单详情页「常见问题 / 联系客服」入口（带入订单上下文）

**实现步骤**
1. 页面：`TicketCreateView.vue`、`TicketListView.vue`、`TicketDetailView.vue`；路由 `/service-center/tickets/new`、`/service-center/tickets`、`/service-center/tickets/{id}`。
2. 提单表单：
   - 类型下拉来自 `GET /api/cs/ticket-types`；类型 `require_order=true` 时订单选择为必填（前端 + 后端双重校验）
   - 订单选择：拉取当前用户可售后/近期订单（复用既有订单列表接口），展示订单号与商品首图
   - 凭证上传：调用 `POST /api/cs/upload-image`，≤9 张，支持删除与预览；上传失败提示可重试
   - 联系方式默认取账号手机（可改）
   - 提交后跳转工单详情并提示工单号
3. 列表：状态 Tab（全部/待处理/处理中/等待回复/已完成/已关闭）+ 下拉加载更多；每条展示工单号、类型、标题、状态标签、最后更新时间。
4. 详情：
   - 头部：工单号、状态、类型、创建时间、关联订单卡片（点击跳订单详情）
   - 消息流：气泡式区分「我 / 客服 / 系统」；图片可点击预览；**内部备注不展示**（后端已过滤，前端再兜底过滤一次）
   - 底部输入区：文本 + 图片，发送后滚动到底部；已关闭工单隐藏输入区并提示「工单已关闭」
   - 操作：关闭工单（二次确认）
5. 轮询或手动刷新：MVP 采用「进入页面拉取 + 发送后拉取 + 手动下拉刷新」，实时推送后置三期。
6. 订单详情页入口：`OrderDetailView.vue` 增加「常见问题」「联系客服」按钮，联系客服带入 `order_id` 预填。
7. H5 适配：底部输入区固定（sticky），消息流可滚动，输入框聚焦不被键盘遮挡。

**测试要求**
- 前端单元测试（`web/tests/cs-ticket.test.ts`，≥6 条）：
  1. 类型为必填订单类时，未选订单提交被拦截并提示；
  2. 凭证选择第 10 张时被拦截（≤9）；
  3. 上传失败提示且不阻塞提交流程；
  4. 消息流渲染区分三种发送方样式；
  5. 已关闭工单隐藏输入区与关闭按钮；
  6. 状态 Tab 切换请求参数正确；
  7. 内部备注数据即使返回也不渲染（前端兜底）。
- 集成测试：mock 提交 → 详情跳转；mock 追加回复 → 消息流追加一条并滚动到底。
- 回归测试：**R1 订单**（订单详情页新增按钮不得破坏既有交互用例 `web/tests/order-center.test.ts`）。

**验收标准（Acceptance Criteria）**
- AC-113.1 提单表单校验与后端一致（类型联动必填订单、图片 ≤9、字数上限）。
- AC-113.2 提交成功后跳转详情且展示工单号；列表可立即查到该工单。
- AC-113.3 详情页消息流按时间正序，图片可预览，内部备注不可见（前端兜底 + 后端过滤）。
- AC-113.4 关闭工单二次确认后状态更新为已关闭且输入区消失。
- AC-113.5 订单详情页「联系客服」带入订单并预填成功。
- AC-113.6 H5 视口（375px）下输入区固定可用、消息流滚动正常。

**验收证据**：`vitest-CS-113.txt`、`ui-CS-113-create.png`、`ui-CS-113-detail.png`、`ui-CS-113-mobile.png`、`curl-CS-113.md`、`commit-CS-113.txt`

---

### CS-114 [ADMIN] 客服工单工作台 + 侧栏菜单入口
| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 前端（后台主界面） |
| 对应功能 | §4.1 工单工作台、§4.2 工单处理详情 |
| 依赖 | CS-108、CS-109 |
| 关联 | CS-115（同模块）、CS-204/212（二期扩展本页） |
| 预估 | 3.0 人天 |

**目标**：客服在后台三栏工作台完成全部处理动作：左侧筛选列表，中间工单详情与消息流，右侧操作区（状态、转交、优先级、备注）。

**实现的功能**
- 工单列表（六类筛选 + 分页 + 待处理数量红点）
- 工单详情三栏布局（用户/订单摘要 + 消息流 + 操作区）
- 回复（文本 + 图片，内部备注开关）
- 状态变更 / 转交 / 优先级
- 侧栏「客户服务」菜单（按 `cs.ticket.view` 显隐）

**实现步骤**
1. 页面：`admin/src/views/cs/CsTicketView.vue`（列表 + 详情，详情用抽屉或独立路由 `/cs/tickets/:id`）；建议列表页 + 右侧抽屉（客服高频切换工单，抽屉更顺手）。
2. 侧栏菜单：`AdminLayout.vue` 的 `menuGroups` 新增分组或并入「运营管理」：
   - `{ path: '/cs/tickets', title: '服务工单', icon: 'LifeBuoy', permission: 'cs.ticket.view' }`
   - `{ path: '/cs/faq', title: '帮助中心', icon: 'BookOpen', permission: 'cs.faq.manage' }`
   - **图标必须双注册**（lucide import + `icons` 映射表），否则静默无图标（项目已知坑，见 `admin/tests/sidebar-icon.test.ts`）
3. 列表：筛选表单（状态/类型/时间范围/关键词/处理人/优先级）+ 重置；表格字段：工单号、类型、标题、用户、关联订单、状态、创建时间、最后回复、处理人、操作。
4. 详情三栏：
   - 左：用户摘要（昵称/手机尾号/注册时间/历史工单数）+ 关联订单卡片（可跳订单详情）
   - 中：消息流（区分用户/客服/系统，内部备注以醒目底色标注「仅客服可见」）+ 回复框（文本 + 图片 + 「内部备注」开关）
   - 右：操作区（状态变更按钮组、转交下拉、优先级切换）—— MVP 不接快捷回复（二期 CS-204）
5. 实时性：MVP 用「打开详情拉取 + 发送后刷新 + 列表手动刷新」；待处理红点用 `meta.pending_count`。
6. 权限：无 `cs.ticket.handle` 时隐藏回复框与操作按钮（只读视图）。
7. 滚动容器遵循既有约定：页面滚动由 `AdminLayout` 的 `<main>` 承担，页内 `sticky` 需 `-bottom-4` 偏移且底色不透明。

**测试要求**
- 前端单元测试（`admin/tests/cs-ticket.test.ts`，≥6 条）：
  1. 无 `cs.ticket.view` 时侧栏不渲染客服菜单；
  2. 无 `cs.ticket.handle` 时隐藏回复框与操作按钮；
  3. 筛选条件变化触发列表重新请求且参数正确；
  4. 消息流渲染区分用户/客服/系统，内部备注带「仅客服可见」标记；
  5. 状态变更按钮在当前状态下可选目标正确（依据状态矩阵）；
  6. 转交后处理人列更新。
- 集成测试：mock 列表 + 详情接口，验证抽屉打开/切换/关闭流程。
- 回归测试：**R7 菜单**（`admin/tests/sidebar-icon.test.ts` 必须全绿，新增菜单 icon 已双注册）。

**验收标准（Acceptance Criteria）**
- AC-114.1 侧栏「客户服务」菜单按权限显隐，图标正常显示（无 undefined 图标）。
- AC-114.2 列表六类筛选生效，分页与总数正确，待处理红点数字与接口一致。
- AC-114.3 详情三栏信息完整（用户摘要、订单卡片、消息流、操作区）。
- AC-114.4 回复（含图片）成功追加到消息流；内部备注开关生效且视觉可区分。
- AC-114.5 状态变更、转交、优先级三个动作生效并即时反映在列表/详情。
- AC-114.6 只读权限账号看不到任何写操作入口。
- AC-114.7 `npx vue-tsc -b`（或项目既有类型检查命令）零新增错误。

**验收证据**：`vitest-CS-114.txt`、`ui-CS-114-list.png`、`ui-CS-114-detail.png`、`curl-CS-114.md`、`commit-CS-114.txt`

---

### CS-115 [ADMIN] FAQ 分类与文章管理页
| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 前端（后台内容运营） |
| 对应功能 | §4.3 FAQ / 帮助中心管理、§5.4 维护流程 |
| 依赖 | CS-110 |
| 关联 | CS-114（同模块菜单）、CS-104（用户端即时可见） |
| 预估 | 1.5 人天 |

**目标**：运营可在后台维护分类与文章（增删改、排序、发布/下架、预览），并查看浏览与「有帮助」统计。

**实现的功能**
- 分类管理（增删改、拖拽/输入排序、启停）
- 文章列表（筛选分类/状态/关键词 + 统计列）
- 文章编辑（标题/摘要/正文/分类/排序/热门/状态）
- 发布 / 下架 / 预览
- 统计查看（浏览量、有帮助、无帮助、有帮助率）

**实现步骤**
1. 页面：`admin/src/views/cs/CsFaqView.vue`（分类 + 文章双 Tab 或左右分栏）；路由 `/cs/faq`。
2. 分类管理：列表 + 新增/编辑弹窗 + 排序输入（调用 `/categories/sort`）+ 启停开关 + 删除（有已发布文章时后端拒绝，前端提示原因）。
3. 文章管理：
   - 列表：筛选（分类/状态/关键词）+ 字段（标题、分类、状态、浏览、有帮助率、更新时间、操作）
   - 编辑：抽屉或独立路由页，字段与 CS-110 一致
   - **正文编辑器**：MVP 优先复用项目既有富文本方案；若集成成本 > 0.5 人天，降级为 Markdown 文本域 + 图片上传（见 README 风险表）
   - 操作：保存草稿、发布、下架、预览（新窗口打开用户端详情或预览接口）
4. 发布确认：发布前提示「发布后用户端立即可见」；下架二次确认。
5. 权限：无 `cs.faq.manage` 时页面只读且不显示新增/编辑/发布按钮。
6. 写操作后刷新列表并提示结果。

**测试要求**
- 前端单元测试（`admin/tests/cs-faq.test.ts`，≥5 条）：
  1. 无 `cs.faq.manage` 时隐藏新增/编辑/发布按钮；
  2. 分类删除被拒时展示后端返回的冲突原因；
  3. 文章状态标签（草稿/已发布/已下架）渲染正确；
  4. 有帮助率在分母为 0 时展示「—」而非 NaN；
  5. 发布/下架操作触发正确接口与二次确认。
- 集成测试：mock 保存 → 列表刷新 → 状态列更新。
- 回归测试：**R7 菜单**（确保帮助中心菜单 icon 双注册）。

**验收标准（Acceptance Criteria）**
- AC-115.1 分类增删改排序启停全部可用，删除被拒时展示明确原因。
- AC-115.2 文章创建后默认草稿，用户端不可见；发布后用户端立即可见（与 CS-104 联动验证）。
- AC-115.3 下架后用户端详情 404，列表不出现。
- AC-115.4 统计列数值与接口一致，有帮助率为 0/0 时展示「—」。
- AC-115.5 只读权限无任何写操作入口。
- AC-115.6 预览功能可查看排版效果。

**验收证据**：`vitest-CS-115.txt`、`ui-CS-115-category.png`、`ui-CS-115-article-edit.png`、`curl-CS-115.md`（发布/下架联动验证）、`commit-CS-115.txt`

---

## 批次 E：质量与验收（CS-116 ~ CS-117）

### CS-116 [QA] 一期专项测试（状态机 / 权限 / 并发）
| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 质量 |
| 对应功能 | 全模块 |
| 依赖 | CS-101 ~ CS-110（后端全部完成） |
| 关联 | CS-117（阶段验收前置） |
| 预估 | 1.5 人天 |

**目标**：对一期后端做三项专项验证 —— 状态机全覆盖、权限矩阵全覆盖、并发与幂等，确保核心逻辑无可观测缺陷。

**实现的功能**
- 状态机 20 条流转路径全覆盖用例（合法 + 非法）
- 权限矩阵用例（4 类账号 × 11 个接口）
- 并发场景：建单、状态变更、重复提交
- 数据一致性核对脚本

**实现步骤**
1. 状态机专项 `tests/Feature/CsTicketStateMachineApiTest.php`：
   - 5 个状态 × 目标状态笛卡尔积 25 条路径，逐条断言（合法 → 成功；非法 → 40009 且无副作用）
2. 权限矩阵专项 `tests/Feature/CsPermissionMatrixTest.php`：
   - 账号四类：未登录、普通买家、有 `cs.ticket.view` 的运营、有 `cs.ticket.handle` 的客服、超管
   - 接口 11 个（用户端 6 + 后台 5），逐条断言 401/403/200/404
3. 并发专项（脚本化，写入 `loadtest-CS-116.md`）：
   - 50 并发建单 → 工单号唯一、无重号、无 500
   - 10 并发改同一工单状态 → 只有 1 个成功，其余 40009
   - 重复提交同一回复（网络重试模拟）→ 消息不重复
4. 数据一致性 SQL 核对：
   - `cs_ticket` 状态分布与消息数；`first_replied_at` 只在首条客服消息后写入；孤儿消息（无工单）为 0
5. 双库执行：SQLite + PG 各跑一遍，输出两份报告。

**测试要求**
- 单元测试：状态机 25 条路径（含非法路径断言）。
- 集成测试：权限矩阵 4×11 组合；通知与消息副作用的因果验证。
- 回归测试：**R1~R7 全量执行**（本任务是一期唯一的全回归点，仍需限定在 README §8.2 的 7 项，不做全项目测试）。

**验收标准（Acceptance Criteria）**
- AC-116.1 状态机 25 条路径断言 100% 通过，非法路径无副作用。
- AC-116.2 权限矩阵无越权（四类账号 11 接口组合全部符合预期）。
- AC-116.3 并发建单 50 次无重号；并发状态变更仅一次成功；重复提交幂等。
- AC-116.4 数据一致性核对无孤儿消息、无状态与时间锚点矛盾（如 `completed` 无 `completed_at`）。
- AC-116.5 SQLite 与 PG 双库用例结果一致。
- AC-116.6 R1~R7 回归全绿（无既有用例失败）。

**验收证据**：`pest-CS-116.txt`、`pest-pgsql-CS-116.txt`、`loadtest-CS-116.md`、`sql-CS-116.md`（一致性核对）、`commit-CS-116.txt`

---

### CS-117 [QA] 一期端到端冒烟与阶段验收
| 项 | 内容 |
|----|------|
| 阶段/主线 | 一期 / 阶段出口（Gate G1） |
| 对应功能 | 全模块（端到端） |
| 依赖 | CS-111 ~ CS-115（前端全部完成） |
| 关联 | 二期 CS-201（G1 通过后方可开工） |
| 预估 | 1.0 人天 |

**目标**：以真实浏览器 + 真实接口跑通「用户提单 → 客服处理 → 用户跟进 → 完成关闭」全链路，确认一期可用并归档验收证据。

**实现的功能**
- 端到端 10 步冒烟脚本（接口 + 浏览器双通道）
- 移动端 H5 三页可用性检查
- 阶段验收报告与证据归档

**实现步骤**
1. 端到端冒烟 10 步（脚本化，写入 `smoke-CS-117.md`）：
   1. 买家登录 → 进入服务中心首页
   2. 帮助中心搜索关键词 → 命中文章 → 打开详情 → 提交「有帮助」
   3. 返回服务中心 → 联系客服 → 选择「物流问题」→ 关联订单 → 上传 2 张凭证 → 提交
   4. 断言：生成工单号、状态 `pending`、我的工单列表可见
   5. 客服登录后台 → 工作台待处理数 +1 → 打开该工单
   6. 客服写一条内部备注 → 断言用户端不可见、用户未收到通知
   7. 客服回复用户 → 断言状态 `pending → processing`、用户收到站内信、`first_replied_at` 已写入
   8. 客服置「等待用户回复」→ 用户端追加回复 → 断言状态回 `processing`
   9. 客服标记「已完成」→ 用户端可见完成状态与系统消息
   10. 用户关闭工单 → 断言 `closed`、输入区消失、再次关闭返回 40009
2. 接口通道：用 curl 串起同一链路（作为无浏览器环境下的可重复验证）。
3. 浏览器通道：`agent-browser` 或手工，账号 `admin` / `Admin@123`（后台）+ 测试买家账号（用户端），截图 6 张。
4. H5 检查：375×812 视口下服务中心首页、工单列表、工单详情三页可用（无横向滚动、输入区可用）。
5. 归档：把 CS-101~CS-116 的证据目录汇总，编写 `acceptance-CS-117.md` 阶段验收报告（含未解决问题清单与二期交接项）。

**测试要求**
- 单元测试：本任务不新增单元用例（复用 CS-116）。
- 集成测试：端到端链路以接口用例固化（可重复执行）。
- 回归测试：**R1~R7**（执行第二次，确认阶段出口状态）。

**验收标准（Acceptance Criteria，Gate G1）**
- AC-117.1 端到端 10 步全过（接口通道 + 浏览器通道各一次）。
- AC-117.2 内部备注在用户端不可见、不通知用户（第 6 步硬断言）。
- AC-117.3 全链路状态流转与 CS-102 定义矩阵完全一致。
- AC-117.4 H5 三页（首页/列表/详情）在 375px 视口可用。
- AC-117.5 R1~R7 回归全绿。
- AC-117.6 证据齐全：CS-101~CS-116 每个任务目录下至少 3 个证据文件，阶段验收报告已产出。
- AC-117.7 设计文档 §10 成功标准逐条对照：① 1 分钟内找到常见问题答案 ✅；② 可提交工单并查看进度与回复 ✅；③ 客服可处理并关联订单 ✅（CS-202 二期深化）；④ 状态清晰、记录可追溯 ✅；⑤ FAQ 后台维护即时生效 ✅；⑥ 基础服务数据可查 ❌（二期 CS-210）。

**验收证据**：`smoke-CS-117.md`（10 步脚本 + 结果）、`curl-CS-117.md`、`ui-CS-117-e2e-*.png`（6 张）、`ui-CS-117-mobile-*.png`（3 张）、`acceptance-CS-117.md`（阶段验收报告）、`pest-CS-117.txt`（回归输出）、`commit-CS-117.txt`

---

**—— 一期任务清单结束 ——**
