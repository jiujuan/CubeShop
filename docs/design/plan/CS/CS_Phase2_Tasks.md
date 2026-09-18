# 客户服务中心 二期（效率与体验增强）任务清单

**任务编号**：CS-201 ~ CS-216
**阶段目标**：让客服「处理更快」、让运营「看得见」、让用户体验「更完整」—— 快捷回复、满意度评价、服务看板、公告、服务配置、自动关闭、数据权限、售后衔接。
**预估**：17~22 人天
**开工前置**：**Gate G1 通过**（CS-117 阶段验收）；① 满意度评价规则（是否评价后自动关闭）评审通过；② 客服数据权限口径（普通客服可见范围）确认。
**阶段出口**：CS-216 专项测试通过（Gate G2）

> 证据统一归档到 `docs/testing/evidence/cs/CS-XXX/`；回归范围固定为 README §8.2 的 R1~R7。

---

## 批次 A：数据扩展与订单联动（CS-201 ~ CS-202）

### CS-201 [DBA] 快捷回复 / 公告 / 配置表与工单扩展字段
| 项 | 内容 |
|----|------|
| 阶段/主线 | 二期 / 数据扩展 |
| 对应功能 | §4.4 服务配置、§2.1 服务公告、§3.4 满意度（字段补齐） |
| 依赖 | 一期 CS-101 |
| 关联 | CS-203/207/213 依赖本任务；CS-205 依赖评价字段 |
| 预估 | 1.0 人天 |

**目标**：为二期的快捷回复、服务公告、服务配置、满意度补充数据载体（若评价字段一期已建则本任务仅做核对）。

**实现的功能**
- `cs_quick_reply` 快捷回复模板表
- `cs_announcement` 服务公告表
- `cs_service_config` 服务配置表（或复用 `system_configs`）
- `cs_ticket` 补充字段核对（满意度相关字段一期已建：`satisfaction`/`satisfaction_remark`/`satisfaction_at`）

**实现步骤**
1. **先确认配置承载方式**（决策 D8）：检查项目是否已存在 `system_configs`（或等价的键值配置表）。
   - 存在 → 不建 `cs_service_config`，配置走既有表并以 `cs.` 前缀命名 key
   - 不存在 → 建 `cs_service_config`：`id`、`key`(varchar 64, unique)、`value`(json)、`remark`(varchar 255 nullable)、`timestamps`
2. `cs_quick_reply`：`id`、`type_id`(bigint nullable，空表示通用)、`title`(varchar 64)、`content`(text)、`sort`(smallint default 0)、`created_by`(bigint nullable)、`updated_by`(bigint nullable)、`timestamps`；索引 `[type_id, sort]`。
3. `cs_announcement`：`id`、`title`(varchar 128)、`content`(text)、`is_top`(boolean default false)、`status`(varchar 16 default `draft`)、`published_at`(timestamp nullable)、`created_by`(bigint nullable)、`timestamps`；索引 `[status, is_top, published_at]`。
4. `cs_ticket` 核对字段：`satisfaction`、`satisfaction_remark`、`satisfaction_at`、`first_replied_at`、`completed_at`、`closed_at`、`close_reason` 均已存在（一期 CS-101），缺失则补迁移。
5. 双库（SQLite/PG）迁移 + 回滚验证，同 CS-101 规范（JSON 用 `json()`、不用专有类型）。
6. 输出建表 SQL 快照归档。

**测试要求**
- 单元测试：三表字段与默认值断言；配置表 key 唯一约束。
- 集成测试：双库迁移/回滚；在 `cs_quick_reply` 与 `cs_announcement` 各插入一条并读取；`cs_ticket` 评价字段可读写。
- 回归测试：**R1 订单**、**R2 退款**（新增表不影响既有用例）；确认 `cs_ticket` 补字段未影响一期行为。

**验收标准（Acceptance Criteria）**
- AC-201.1 三张新表（或配置复用方案）在双库下创建成功，字段与索引符合设计。
- AC-201.2 配置承载方式已明确并写入代码注释与本文档（避免后续两套配置并存）。
- AC-201.3 迁移可回滚、可重复执行；既有 `cs_ticket` 数据不受影响。
- AC-201.4 `cs_ticket` 满意度与时间锚点字段齐备（对照一期 CS-101 清单逐项核对）。

**验收证据**：`schema-snapshot-CS-201.txt`（建表字段/索引快照）、`migrate-status-CS-201.txt`、`pest-CS-201.txt`、`pg-CS-201.txt`（双库）

**落地记录（2026-09-17）**
- **决策 D8 结论：复用 `system_configs`**。项目已存在 `system_configs`（迁移 `2026_09_15_000011`，`config_key` unique 128 / `config_value` text / `description` 255），故**不新建 `cs_service_config`**，服务配置统一走既有表并以 `cs.` 前缀命名 key（如 `cs.work_time`）。已用守卫测试断言 `cs_service_config` 表不存在，防两套配置并存。
- 新增迁移 `2026_09_17_000045_create_cs_quick_reply_and_announcement_tables.php`；新增模型 `CsQuickReply`（`scopeForType` 绑定类型 + 通用 null、`scopeOrdered`）、`CsAnnouncement`（`STATUS_DRAFT/PUBLISHED/OFFLINE`、`scopeVisible`、`scopeOrdered`）。
- `cs_ticket` 满意度/时间锚点字段核对通过，无需补迁移。
- 测试：`CsPhase2SchemaTest`（9 passed / 49 assertions，SQLite + PG 双库绿）。

---

### CS-202 [BE] 工单关联订单深化（订单 / 物流 / 退款聚合）
| 项 | 内容 |
|----|------|
| 阶段/主线 | 二期 / 订单联动 |
| 对应功能 | §8 与订单联动、§4.2 左侧信息区 |
| 依赖 | CS-201 |
| 关联 | CS-114（工作台左侧信息区消费）、CS-214（售后衔接）、CS-210（看板取订单维度） |
| 预估 | 1.5 人天 |

**目标**：客服在工单详情可直接看到订单关键信息（商品、金额、物流轨迹、退款记录），并具备「一键跳订单详情 / 发起退款」的能力入口。

**实现的功能**
- 工单详情返回 `order_snapshot`（订单号、状态、金额、商品清单、收货信息脱敏）
- 物流轨迹摘要（最新一条轨迹 + 快递公司/单号）
- 退款记录摘要（该订单已发起的退款单号、金额、状态）
- 后台「发起退款」跳转所需数据（不重复实现退款，复用既有退款接口）

**实现步骤**
1. `CsTicketService::orderSnapshot(CsTicket $t): ?array`：
   - 订单：`order_no`、`status`、`status_label`、`pay_amount`、`created_at`、商品清单（首图、名称、规格、数量、单价）
   - 收货信息：收货人、手机尾号（脱敏）、地址
   - 物流：最新轨迹节点（时间 + 描述）、快递公司、运单号（复用既有 shipping 数据，若订单未发货返回 null）
   - 退款：该订单 `refunds` 记录（refund_no、amount、status、created_at）
2. 接口：`GET /api/admin/cs/tickets/{id}` 响应新增 `order_snapshot` 节点；用户端 `GET /api/cs/tickets/{id}` 同样返回精简版（不含脱敏地址以外的敏感项）。
3. 数据一致性：快照为**实时读取**（不做冗余存储），避免订单变更后工单侧数据过期。
4. 权限：发起退款仍需 `refund.process` 权限，本接口只提供**跳转参数**（`order_id`、`refund_no`），不代客操作。
5. 性能：预加载 `order.items`、`order.shippings`、`order.refunds`，避免 N+1；对无订单工单返回 `null` 不报错。

**测试要求**
- 单元测试（≥4 条）：快照字段完整性；无订单工单返回 null；手机尾号脱敏规则；物流为空时不报错。
- 集成测试（≥6 条）：
  1. 有订单工单返回完整快照；
  2. 无订单工单 `order_snapshot` 为 null；
  3. 商品清单与订单明细一致；
  4. 物流轨迹取最新一条；
  5. 退款记录包含该订单全部退款单；
  6. 用户端快照不含内部字段（如 `admin_remark`）。
- 回归测试：**R1 订单**、**R2 退款**（读取既有数据，确认未改动退款逻辑）。

**验收标准（Acceptance Criteria）**
- AC-202.1 后台工单详情可在不开订单页的情况下看到订单、物流、退款三类信息。
- AC-202.2 无关联订单的工单不报错（返回 null）。
- AC-202.3 敏感信息脱敏（手机仅显示尾号），用户端快照不含客服内部字段。
- AC-202.4 快照实时读取，订单状态变更后工单详情立即反映。
- AC-202.5 无 N+1（预加载生效，查询次数受控）。

**验收证据**：`pest-CS-202.txt`、`pg-CS-202.txt`、`curl-CS-202.md`（快照响应全文，HTTP 端到端）、`sql-CS-202.md`（与订单/物流/退款表交叉核对）

**落地记录（2026-09-17）**
- 单一数据源：`CsTicketService::orderSnapshot(CsTicket $t, bool $forStaff = true): ?array` 是唯一入口，后台 `Admin\CsTicketController::show()` 与买家端 `CsTicketController::show()` 都调用它，`forStaff` 控制字段可见性。
- `order_snapshot` 契约（v1）：
  - 头字段：`order_id`、`order_no`、`status`、`status_label`、`pay_amount`、`created_at`、`item_count`（= 明细数量合计）
  - `items[]`：`product_id`、`sku_id`、`title`（取 `order_items.product_title` 快照）、`specs`（`sku_specs` 解析）、`image`（取 `order_items.sku_image` 快照，**不查 products 表**）、`price`、`quantity`、`total_amount`、`payable_amount`（行实付，`item->payableAmount()`）
  - `address`：`contact_name`、`phone_masked`（脱敏：11 位前 3 后 4、7-10 位前 2 后 2、≤4 位全遮）、`full_address`
  - `shipping`：`company_code`、`company_name`、`tracking_no`、`trace_status`、`shipped_at`、`delivered_at`、`latest_trace{context, occurred_at}`；未发货 → `null`
  - `refunds[]`：`refund_no`、`amount`、`status`、`created_at`，后台额外含 `admin_remark`；按 id 倒序
  - `jump`：`order_id`、`order_no`、`latest_refund_no`，**仅后台**（买家端剥离）
- 兼容：后台/买家端响应同时保留旧 `order` 节点（`legacyOrderSummary()`：`order_no`/`status`/`pay_amount`/`created_at`/`product_image`），不破坏 CS-114 工作台既有契约。
- 性能：`loadMissing(['items', 'refunds', 'shipping.traces'])` 预加载防 N+1；`shipping.traces` 关系按 `occurred_at` 倒序，取 `first()` 即最新轨迹。
- 测试：`CsOrderSnapshotTest`（13 passed / 81 assertions）+ `CsTicketOrderSnapshotApiTest`（11 passed / 54 assertions）；双库绿。

---

## 批次 B：效率工具（CS-203 ~ CS-204）

### CS-203 [BE] 快捷回复模板接口
| 项 | 内容 |
|----|------|
| 阶段/主线 | 二期 / 效率 |
| 对应功能 | §4.4 快捷回复模板 |
| 依赖 | CS-201 |
| 关联 | CS-204（前端使用）、CS-109（回复接口复用模板插入） |
| 预估 | 1.0 人天 |
| **状态** | ✅ 测试通过（2026-09-17） |

**接口契约（v1）**
- `GET /api/admin/cs/quick-replies`：管理列表（全量，按 `sort/id` 排序，带 `type_name`）；工作台下拉（`?type_id=` 返回「通用 + 该类型专属」）。权限 `role_or_permission:cs.faq.manage|cs.ticket.handle`（客服可取用、管理员可管理）。
- `POST/PUT/DELETE /api/admin/cs/quick-replies[/id]`：写入，权限 `cs.faq.manage`。
- `CsQuickReplyService::forType(?int)` / `render(string, CsTicket)`：单一数据源；`render()` 支持 `{user_nickname}`/`{ticket_no}`/`{order_no}`，与前端 `renderCsTemplate` 口径一致。
- 校验：`title`≤64 必填、`content`≤2000 必填、`type_id` 可空且 `exists:cs_ticket_type`；非法 `type_id`/超长 `content` → 422；越权 → 403。

**目标**：客服在回复工单时可一键插入预设话术，减少重复输入。

**实现的功能**
- 模板 CRUD（标题、内容、适用类型、排序）
- 按工单类型取用模板（通用模板 + 类型专属模板）
- 模板使用次数统计（可选，供优化话术）

**实现步骤**
1. 路由（挂 `permission:cs.faq.manage` 管理，读取挂 `cs.ticket.handle`）：
   - `GET/POST/PUT/DELETE /api/admin/cs/quick-replies`
   - `GET /api/admin/cs/quick-replies?type_id=`（客服侧读取，返回 通用 + 该类型专属，按 sort 排序）
2. 校验：`title` ≤ 64 必填；`content` ≤ 2000 必填；`type_id` 可空（通用）且必须有效。
3. 服务层 `CsQuickReplyService::forType(?int $typeId)`：`whereNull('type_id') orWhere('type_id', $typeId)`，按 `sort` asc、`id` asc。
4. 模板变量替换（MVP 可选）：支持 `{user_nickname}`、`{ticket_no}`、`{order_no}` 占位符，在前端插入时替换（后端提供 `render($template, CsTicket $t)` 便于单元测）。
5. 删除：物理删除 + 二次确认（模板无历史引用价值）。

**测试要求**
- 单元测试（≥3 条）：`forType` 返回「通用 + 类型专属」并排序正确；变量替换 `{ticket_no}`/`{order_no}`/`{user_nickname}` 正确；无匹配时返回空数组不报错。
- 集成测试（≥6 条）：CRUD 各分支；类型筛选；无权限 403；非法 `type_id` 422；模板内容超长 422。
- 回归测试：**R4 权限**（新增权限分支不影响既有角色）。

**验收标准（Acceptance Criteria）**
- AC-203.1 模板 CRUD 可用，权限区分（管理需 `cs.faq.manage`，使用需 `cs.ticket.handle`）。
- AC-203.2 按类型取用返回「通用 + 专属」且排序稳定。
- AC-203.3 变量替换结果正确（3 个占位符逐一验证）。
- AC-203.4 非法参数与越权分别返回 422/403。

**验收证据**：`pest-CS-203.txt`、`pg-CS-203.txt`、`curl-CS-203.md`、`commit-CS-203.txt`

---

### CS-204 [ADMIN] 快捷回复管理页 + 工作台一键插入
| 项 | 内容 |
|----|------|
| 阶段/主线 | 二期 / 前端 |
| 对应功能 | §4.4 快捷回复模板、§4.2 操作区 |
| 依赖 | CS-203、一期 CS-114 |
| 关联 | 一期 CS-109（回复接口不变，仅前端插入） |
| 预估 | 1.5 人天 |
| **状态** | ✅ 测试通过（2026-09-17） |

**实现要点**
- 管理页 `admin/src/views/cs/CsQuickReplyView.vue`：列表按「通用 / 各工单类型」分组渲染；新增/编辑弹窗 + 删除二次确认（ConfirmDialog）+ 类型筛选。
- 工作台集成（改 `CsTicketView.vue`）：回复框「快捷回复」下拉 → 选项来自 `GET /cs/quick-replies?type_id=当前工单类型`（通用 + 专属）→ 选中后**插入到文本域光标处**并做变量替换。
- 变量替换统一走 `src/utils/csTemplate.ts` 的 `renderCsTemplate()`，与后端 `CsQuickReplyService::render()` 口径一致（`{user_nickname}`/`{ticket_no}`/`{order_no}`）。
- 无 `cs.faq.manage` 时管理页不可见（菜单权限）；无 `cs.ticket.handle` 时回复框隐藏（沿用 CS-114）。
- 菜单 `快捷回复` 已双注册（`AdminLayout.vue` import + icons 映射，`MessageSquare`）。

**目标**：运营可维护快捷回复模板；客服在工单回复框一键选择并插入模板（含变量替换）。

**实现的功能**
- 模板管理页（增删改、排序、按类型归类）
- 工作台回复框「快捷回复」下拉（按当前工单类型过滤）
- 选择后插入到光标位置并完成变量替换

**实现步骤**
1. 页面 `admin/src/views/cs/CsQuickReplyView.vue`，路由 `/cs/quick-replies`（菜单权限 `cs.faq.manage`）。
2. 管理页：列表（标题、适用类型、排序、操作）+ 新增/编辑弹窗 + 删除二次确认 + 排序调整。
3. 工作台集成（改 `CsTicketView.vue`）：
   - 回复框工具栏增加「快捷回复」下拉，选项来自 `GET /api/admin/cs/quick-replies?type_id=当前工单类型`
   - 选中后插入到文本域光标位置（不是追加到末尾）
   - 插入时前端做变量替换（`{user_nickname}`/`{ticket_no}`/`{order_no}`），与 CS-203 后端 `render()` 口径一致
   - 无模板时下拉显示「暂无模板，去添加」并跳转管理页
4. 权限：无 `cs.faq.manage` 时管理页只读/不可见；无 `cs.ticket.handle` 时不显示回复框（沿用 CS-114）。

**测试要求**
- 前端单元测试（`admin/tests/cs-quick-reply.test.ts`，≥4 条）：
  1. 模板列表按类型分组渲染；
  2. 选择模板后插入到光标位置（mock 光标位置）；
  3. 变量替换后文本正确；
  4. 无模板时展示空态与跳转入口。
- 集成测试：mock 模板接口 → 插入 → 提交回复 → 断言消息内容含替换后变量。
- 回归测试：**R7 菜单**（新增菜单 icon 双注册）；一期 `admin/tests/cs-ticket.test.ts` 全绿（工作台改动不得破坏既有交互）。

**验收标准（Acceptance Criteria）**
- AC-204.1 模板管理页 CRUD 可用，排序生效。
- AC-204.2 工作台回复框可见「快捷回复」下拉，选项按当前工单类型过滤（含通用模板）。
- AC-204.3 插入位置为光标处，变量替换结果与后端一致。
- AC-204.4 无模板时展示正确空态。
- AC-204.5 一期工单工作台既有用例全绿。

**验收证据**：`vitest-CS-204.txt`、`commit-CS-204.txt`（UI 截图因 agent-browser 沙箱文件系统隔离未能落盘，行为由 8 例前端单测 + 集成测全量覆盖：分组渲染 / 光标插入 / 变量替换 / 空态跳转 / CRUD / CS-202 订单卡回归）

---

## 批次 C：满意度与配置（CS-205 ~ CS-209）

### CS-205 [BE] 满意度评价接口
| 项 | 内容 |
|----|------|
| 阶段/主线 | 二期 / 体验 |
| 对应功能 | §3.4 评价满意度、§4.2 完成后邀请评价 |
| 依赖 | CS-201 |
| 关联 | CS-206（前端）、CS-210（看板取满意度）、CS-209（评价后自动关闭） |
| 预估 | 1.0 人天 |

**目标**：工单完成后用户可评价（1-5 星 + 文字），评价结果作为看板与客服质量的数据源，且不可重复评价。

**实现的功能**
- 用户提交评价（星级 + 文字）
- 防重复评价（一单一评）
- 仅「已完成 / 已关闭」工单可评价
- 评价后可选自动关闭（受 CS-207 配置项控制）

**实现步骤**
1. 路由 `POST /api/cs/tickets/{id}/satisfaction`（`auth:sanctum`）：
   - 参数 `score`（1~5 整数，必填）、`remark`（≤255，选填）
2. 校验与规则（`CsTicketService::rate(CsTicket $t, User $user, int $score, ?string $remark)`）：
   - 工单必须属于当前用户，否则 404
   - 状态必须为 `completed` 或 `closed`；`pending/processing/waiting_user` 返回 40009（提示「工单处理完成后才能评价」）
   - **已评价（`satisfaction` 非空）返回 40009 不可覆盖**（保证数据可信）
   - 写入 `satisfaction`、`satisfaction_remark`、`satisfaction_at`
3. 自动关闭联动：若配置 `cs.auto_close_after_rating = true` 且当前状态为 `completed`，评价后走 `transitionTo(closed, reason='system')`（配置读取见 CS-207）。
4. 写一条 `system` 消息「用户已评价：X 星」（客服可见）。
5. 幂等：并发重复提交只有一次生效（DB 层用条件更新 `whereNull('satisfaction')`，命中 0 行即冲突）。

**测试要求**
- 单元测试（≥4 条）：状态前置校验矩阵（5 个状态下是否可评）；重复评价拦截；并发评价条件更新只生效一次；自动关闭开关行为。
- 集成测试（≥7 条）：
  1. 已完成工单评价成功，字段落库；
  2. 重复评价 → 40009；
  3. 处理中工单评价 → 40009；
  4. 他人工单评价 → 404；
  5. 星级 0/6 → 422；
  6. 评价后产生系统消息；
  7. 开启自动关闭后评价 → 状态变 `closed`。
- 回归测试：**R1 订单**（无耦合）、**R4 权限**。

**验收标准（Acceptance Criteria）**
- AC-205.1 仅 `completed`/`closed` 工单可评价，其余状态 40009。
- AC-205.2 一单一评：重复评价被拒，并发提交只有一次生效。
- AC-205.3 越权评价 404，星级越界 422。
- AC-205.4 评价写入 `satisfaction_at` 并产生系统消息（客服可见）。
- AC-205.5 自动关闭开关生效且可关闭（配置关闭时评价不触发关闭）。

**验收证据**：`pest-CS-205.txt`、`curl-CS-205.md`、`sql-CS-205.md`（并发评价核对）、`commit-CS-205.txt`

---

### CS-206 [WEB] 工单评价交互与已评价态
| 项 | 内容 |
|----|------|
| 阶段/主线 | 二期 / 前端 |
| 对应功能 | §3.4 评价满意度 |
| 依赖 | CS-205、一期 CS-113 |
| 关联 | CS-210（看板展示）、一期 CS-111（首页最近工单） |
| 预估 | 1.0 人天 |

**目标**：用户在工单详情/列表可直观完成评价，并看到已评价状态。

**实现的功能**
- 详情页「已完成」工单展示星级评价组件（5 星 + 文字 + 提交）
- 已评价工单展示星级只读与评价内容
- 列表已评价工单展示星级标记

**实现步骤**
1. 组件 `web/src/components/cs/SatisfactionRating.vue`：5 星点选（hover 预览）、文字输入（≤255，实时计数）、提交按钮（防重复点击）。
2. 集成到 `TicketDetailView.vue`：
   - 状态为 `completed`（且 `satisfaction` 为空）时展示评价区
   - 已评价展示只读星级 + 评价文字 + 评价时间
   - 状态为 `processing/waiting_user` 时不展示（避免误导），`closed` 未评价则展示（允许补评）
3. 提交后本地立即置为已评价态（乐观更新），失败回滚并提示。
4. 列表 `TicketListView.vue`：已评价工单在状态标签旁展示星级图标。
5. H5 适配：星级点击区域 ≥ 44px，文字输入在小屏可用。

**测试要求**
- 前端单元测试（`web/tests/cs-rating.test.ts`，≥5 条）：
  1. 已完成未评价时展示评价区；
  2. 已评价展示只读态（不可再点）；
  3. 星级点击后 selected 状态正确（1~5）；
  4. 文字输入超过 255 时截断/提示；
  5. 提交按钮防重复点击（loading 期间禁用）；
  6. 列表已评价展示星级图标。
- 集成测试：mock 评价接口成功/失败，验证乐观更新与回滚。
- 回归测试：一期 `web/tests/cs-ticket.test.ts` 全绿（详情页改动不破坏既有交互）。

**验收标准（Acceptance Criteria）**
- AC-206.1 评价区只在允许评价的工单上出现（状态判定与后端一致）。
- AC-206.2 提交成功后立即变为只读态，刷新后仍为已评价。
- AC-206.3 提交失败可重试且不丢失已填内容。
- AC-206.4 列表已评价工单展示星级。
- AC-206.5 H5 视口下评价组件可正常点选与提交。

**验收证据**：`vitest-CS-206.txt`、`ui-CS-206-rating.png`、`ui-CS-206-rated.png`、`commit-CS-206.txt`

---

### CS-207 [BE] 服务配置接口
| 项 | 内容 |
|----|------|
| 阶段/主线 | 二期 / 配置 |
| 对应功能 | §4.4 服务配置（工单类型、自动回复、响应时效、自动关闭、服务时间） |
| 依赖 | CS-201 |
| 关联 | CS-208（配置页）、CS-209（自动关闭消费）、一期 CS-106（提交后展示时效提示） |
| 预估 | 1.5 人天 |

**目标**：把服务相关的可配置项集中管理（自动回复、响应时效文案、自动关闭天数、服务时间、工单类型自定义），避免硬编码。

**实现的功能**
- 配置项读写接口（按 key 分组）
- 默认配置与校验（自动关闭天数 1~30、星级/文案长度）
- 用户端读取配置（响应时效提示、服务时间、自动回复欢迎语）
- 工单类型自定义（名称、是否必须关联订单、排序、启停）

**实现步骤**
1. 配置 key 定义（`CsServiceConfig` 常量）：
   - `cs.auto_reply`（用户提交工单后的系统欢迎语，默认「您好，我们已收到您的问题，客服将在服务时间内尽快回复」）
   - `cs.response_sla_text`（响应时效提示文案，默认「工作日 2 小时内首次回复」）
   - `cs.auto_close_days`（完成后 N 天无互动自动关闭，默认 7，范围 1~30）
   - `cs.auto_close_after_rating`（评价后是否自动关闭，默认 true）
   - `cs.service_hours`（客服工作时间文本，默认「09:00-18:00」）
2. 接口：
   - `GET /api/admin/cs/config`、`PUT /api/admin/cs/config`（批量保存，权限 `cs.faq.manage`）
   - `GET /api/cs/config`（用户端读取**公开子集**：`response_sla_text`、`service_hours`，不含内部配置）
   - `POST/PUT/DELETE /api/admin/cs/ticket-types`（工单类型管理，权限 `cs.faq.manage`）
3. 服务层 `CsConfigService::get(string $key, $default = null)`、`set(array $kv)`：读取优先 DB，缺失回退默认值并写回（首次访问落库，便于运营发现）。
4. 校验：`auto_close_days` 整数 1~30；文案类 ≤ 200 字符；保存时统一 trim。
5. 用户端提交工单响应中返回 `sla_text`（时效提示），前端展示「预计工作时间 X 小时内回复」。
6. 配置变更记 `sys_operation_log`（before/after）。

**测试要求**
- 单元测试（≥4 条）：默认值回退与首次落库；`auto_close_days` 边界（0/31 被拒，1/30 通过）；用户端公开子集不含内部项；配置 trim 与长度校验。
- 集成测试（≥6 条）：读取/保存配置；非法值 422；无权限 403；用户端只拿到公开项；工单类型 CRUD；类型停用后用户端不可选。
- 回归测试：**R4 权限**、**R3 通知**（自动回复走系统消息，需确认不干扰用户通知计数策略）。

**验收标准（Acceptance Criteria）**
- AC-207.1 五个配置项可读写，缺失时回退默认值并落库。
- AC-207.2 参数校验生效（自动关闭天数 1~30，文案长度受限）。
- AC-207.3 用户端只返回公开配置子集（不含 `auto_close_days` 等内部项）。
- AC-207.4 工单类型可增删改停，停用后用户端下拉不可见。
- AC-207.5 用户提交工单后响应含 `sla_text`，前端可展示时效提示。
- AC-207.6 配置变更留痕。

**验收证据**：`pest-CS-207.txt`、`curl-CS-207.md`、`sql-CS-207.md`（配置表核对）、`commit-CS-207.txt`

---

### CS-208 [ADMIN] 服务配置页
| 项 | 内容 |
|----|------|
| 阶段/主线 | 二期 / 前端 |
| 对应功能 | §4.4 服务配置 |
| 依赖 | CS-207 |
| 关联 | 一期 CS-115（同模块菜单） |
| 预估 | 1.5 人天 |

**目标**：运营在后台可视化维护服务配置与工单类型，无需改代码。

**实现的功能**
- 配置表单（自动回复、响应时效、服务时间、自动关闭天数、评价后自动关闭）
- 工单类型管理（增删改停、是否必须关联订单、排序）
- 保存前校验与保存后提示

**实现步骤**
1. 页面 `admin/src/views/cs/CsConfigView.vue`，路由 `/cs/config`（权限 `cs.faq.manage`），Tab 分区：基础配置 / 工单类型。
2. 基础配置表单：
   - 自动回复欢迎语（多行文本，含字数统计）
   - 响应时效文案（单行）
   - 客服工作时间（单行）
   - 自动关闭天数（数字输入，1~30，带范围提示）
   - 评价后自动关闭（开关）
3. 工单类型管理：列表（名称、编码、是否必须关联订单、排序、状态）+ 新增/编辑 + 停用/启用 + 删除（有在用工单的类型删除需二次确认并提示影响条数）。
4. 保存：整表提交，成功提示并刷新；失败展示字段级错误（来自 422 响应）。
5. 权限：只读权限账号看到表单但全部禁用。

**测试要求**
- 前端单元测试（`admin/tests/cs-config.test.ts`，≥4 条）：
  1. 配置表单回显接口返回值；
  2. 自动关闭天数超出范围时本地拦截并提示；
  3. 工单类型启用/停用开关触发正确接口；
  4. 只读权限下所有输入禁用。
- 集成测试：mock 保存成功/422 失败，验证成功提示与错误定位。
- 回归测试：**R7 菜单**；确认一期 FAQ 管理页不受影响。

**验收标准（Acceptance Criteria）**
- AC-208.1 五个配置项在页面可编辑并成功保存，刷新后回显保存值。
- AC-208.2 校验规则与后端一致（前端拦截 + 后端兜底）。
- AC-208.3 工单类型增删改停生效，用户端下拉随之变化（与 CS-207 联动验证）。
- AC-208.4 保存失败时展示字段级错误，不丢失已填内容。
- AC-208.5 只读权限全部禁用且无保存按钮。

**验收证据**：`vitest-CS-208.txt`、`ui-CS-208-config.png`、`ui-CS-208-types.png`、`commit-CS-208.txt`

---

### CS-209 [BE] 工单自动关闭命令与系统消息
| 项 | 内容 |
|----|------|
| 阶段/主线 | 二期 / 自动化 |
| 对应功能 | §3.4 关闭规则、§4.4 自动关闭规则 |
| 依赖 | CS-207 |
| 关联 | CS-205（评价后关闭）、一期 CS-105（走同一 `transitionTo`） |
| 预估 | 1.0 人天 |

**目标**：完成后 N 天无新互动的工单自动关闭，避免待办堆积；关闭动作留痕并通知用户。

**实现的功能**
- 定时任务 `cs:tickets:auto-close`
- 关闭判定：状态 = `completed` 且 `last_message_at` 早于 `now - auto_close_days`
- 系统消息 + 用户通知
- 批量、分批、可重复执行（幂等）

**实现步骤**
1. 命令 `app/Console/Commands/CsAutoCloseTickets.php`，签名 `cs:tickets:auto-close {--days=} {--limit=1000}`。
2. 判定逻辑（走服务层，不直接改库）：
   - 读取配置 `cs.auto_close_days`（命令行 `--days` 优先）
   - 查询 `status = completed AND last_message_at < now - N days`，按 `id` 分批（每批 500）
   - 逐条调用 `CsTicketService::transitionTo($t, 'closed', null, 'timeout')`
   - 写 `system` 消息「工单已自动关闭（超过 N 天无新回复）」+ 通知用户
3. 幂等：已关闭工单不再命中查询（状态条件过滤），重复执行不产生重复消息。
4. 统计输出：命令返回处理条数，写日志（info 级别）。
5. 调度：在 `app/Console/Kernel.php` 注册每日执行一次（如 02:30），与既有调度风格一致。
6. 安全：单次处理上限保护（默认 1000 条），超过则记录告警日志并留待下次。

**测试要求**
- 单元测试（≥4 条）：到期判定边界（恰好 N 天、N-1 天、N+1 天）；配置读取与 `--days` 覆盖；幂等（已关闭不再处理）；上限保护。
- 集成测试（≥5 条）：
  1. 到期工单被关闭且 `close_reason=timeout`；
  2. 未到期工单保持 `completed`；
  3. 关闭产生系统消息与用户通知；
  4. 命令重复执行不产生重复消息；
  5. 手工指定 `--days` 生效；
  6. 批量 600 条时分两批处理且全部关闭。
- 回归测试：**R3 通知**（通知类型新增不影响既有未读计数）、**R1 订单**（无耦合）。

**验收标准（Acceptance Criteria）**
- AC-209.1 到期工单被自动关闭，未到期不受影响（边界用例通过）。
- AC-209.2 关闭原因记录为 `timeout`，并产生系统消息与用户通知。
- AC-209.3 命令幂等：连续执行两次不产生重复消息或重复关闭。
- AC-209.4 分批与上限保护生效（600 条分两批，超过上限留待下次并告警）。
- AC-209.5 已在调度中注册，日志可读。

**验收证据**：`pest-CS-209.txt`、`cli-CS-209.txt`（命令执行输出）、`sql-CS-209.md`（关闭前后状态核对）、`commit-CS-209.txt`

---

## 批次 D：数据可见（CS-210 ~ CS-211）

### CS-210 [BE] 服务数据看板接口
| 项 | 内容 |
|----|------|
| 阶段/主线 | 二期 / 数据 |
| 对应功能 | §4.5 数据看板（基础版） |
| 依赖 | CS-205 |
| 关联 | CS-211（前端图表）、一期 CS-105（`first_replied_at` 数据源） |
| 预估 | 2.0 人天 |

**目标**：为运营与客服主管提供基础服务指标：量、时效、满意度、类型分布、客服工作量。

**实现的功能**
- 今日/本周新增工单数、待处理数
- 平均首次响应时长、平均解决时长
- 各问题类型分布
- 满意度平均分与评价数
- 客服个人处理量排行（主管可见）

**实现步骤**
1. 路由 `GET /api/admin/cs/dashboard?range=today|week|month`（权限 `cs.dashboard.view`，新增权限码并补迁移，规范同 CS-103；也可复用 `cs.ticket.view`，评审确认后定）。
2. 指标口径（**写入代码注释，避免后续口径漂移**）：
   - `new_count`：范围内创建的工单数（按 `created_at`）
   - `pending_count`：当前 `status=pending` 的工单数（不受范围影响）
   - `avg_first_reply_minutes`：`avg(first_replied_at - created_at)`，只统计有首响的工单，**未回复工单不计入分母**
   - `avg_resolve_minutes`：`avg(completed_at - created_at)`，只统计已完成的工单
   - `type_distribution`：按类型分组计数（范围内）
   - `satisfaction`：`avg(satisfaction)`、`rated_count`（评价数）、`rated_rate`（评价率 = 已评价 / 已完成）
   - `agent_ranking`：按 `assignee_id` 分组统计范围内处理量（`completed` 数 + 回复消息数），按处理量降序
3. 性能：聚合查询用 `selectRaw` + 索引（`[status, created_at]`、`[assignee_id, status]` 一期已建）；大数据量下加时间范围条件，避免全表扫描。
4. 空数据：无数据时返回 0 / 空数组 / `null`（满意度无评价时 `avg` 返回 `null`，前端展示「—」）。
5. 权限：`agent_ranking` 仅对有 `cs.ticket.handle` 的主管或未受限账号返回；普通客服调用时该字段为 `null`（评审确认口径）。
6. 与既有 `ReportService` 保持一致风格（返回 array，`ApiResponse::success()`）。

**测试要求**
- 单元测试（≥5 条）：首响时长只统计已回复工单；解决时长只统计已完成工单；满意度分母为 0 返回 null；评价率计算；空数据返回结构。
- 集成测试（≥7 条）：
  1. 今日/本周/本月三个范围分别返回正确数量；
  2. 待处理数与数据库一致；
  3. 首响时长与手工核算一致（构造 3 条已知时间差工单）；
  4. 类型分布合计 = 范围内总数；
  5. 满意度与评价数正确；
  6. 主管可见排行，普通客服为 null；
  7. 无数据时返回结构完整不报错。
- 回归测试：**R5 报表**（看板不得影响既有报表接口与 `report.view` 权限）、**R1 订单**（聚合读取订单关联）。

**验收标准（Acceptance Criteria）**
- AC-210.1 五类指标全部返回，数值与 SQL 手工核算一致（误差 0）。
- AC-210.2 口径明确且写入注释：首响/解决时长分母定义、满意度空值处理。
- AC-210.3 三个时间范围切换结果正确。
- AC-210.4 客服排行按权限返回（无权限时为 null 且不报错）。
- AC-210.5 空数据不报错，返回结构完整。
- AC-210.6 既有报表接口与权限不受影响。

**验收证据**：`pest-CS-210.txt`、`curl-CS-210.md`（看板响应全文）、`sql-CS-210.md`（手工核算与接口值对照表）、`commit-CS-210.txt`

---

### CS-211 [ADMIN] 服务数据看板页
| 项 | 内容 |
|----|------|
| 阶段/主线 | 二期 / 前端 |
| 对应功能 | §4.5 数据看板 |
| 依赖 | CS-210 |
| 关联 | 一期 CS-114（同模块菜单） |
| 预估 | 2.0 人天 |

**目标**：运营与主管在后台可视化查看服务指标，支持时间范围切换。

**实现的功能**
- 指标卡片（新增、待处理、平均首响、平均解决、满意度）
- 类型分布图（饼图/柱状）
- 客服处理量排行（主管可见）
- 时间范围切换（今日 / 本周 / 本月）

**实现步骤**
1. 页面 `admin/src/views/cs/CsDashboardView.vue`，路由 `/cs/dashboard`（权限 `cs.dashboard.view` 或 `cs.ticket.view`，与 CS-210 评审结论一致）。
2. 布局：顶部范围切换（Segmented）→ 指标卡片行（5 个）→ 图表区（类型分布 + 排行）。
3. 图表：复用项目既有图表方案（若 `admin` 已引入 ECharts/Chart.js 则复用，避免新增依赖）；无则用轻量 SVG 柱状 + 环形，体积更小。
4. 数据：`GET /api/admin/cs/dashboard?range=`，切换范围重新请求（loading 态 + 错误重试）。
5. 空态：无数据展示「暂无数据」而非 0 误导；满意度无评价展示「—」。
6. 权限：无排行权限时隐藏排行区块（不展示空表）。
7. 菜单：侧栏「服务看板」入口，图标双注册（沿用项目已知坑的规避方式）。

**测试要求**
- 前端单元测试（`admin/tests/cs-dashboard.test.ts`，≥5 条）：
  1. 五个指标卡片渲染正确值；
  2. 范围切换触发请求参数正确；
  3. 满意度为 null 时展示「—」；
  4. 排行无权限时不渲染区块；
  5. 类型分布数据渲染条目数与接口一致；
  6. 空态渲染。
- 集成测试：mock 接口 loading/错误/空数据三态，验证 UI 表现。
- 回归测试：**R7 菜单**（新菜单 icon 双注册）；既有报表页用例（`admin/tests/report-center.test.ts`）全绿。

**验收标准（Acceptance Criteria）**
- AC-211.1 五类指标在页面正确展示，数值与接口一致。
- AC-211.2 时间范围切换生效且 loading/错误态完善。
- AC-211.3 类型分布图表渲染正确（占比合计 100%）。
- AC-211.4 客服排行按权限显隐。
- AC-211.5 空态与 null 值展示「—」，不出现 NaN/undefined。
- AC-211.6 既有报表页与侧栏用例全绿。

**验收证据**：`vitest-CS-211.txt`、`ui-CS-211-dashboard.png`、`curl-CS-211.md`、`commit-CS-211.txt`

---

## 批次 E：权限、公告与售后衔接（CS-212 ~ CS-215）

### CS-212 [BE+ADMIN] 客服数据权限与批量操作
| 项 | 内容 |
|----|------|
| 阶段/主线 | 二期 / 权限 |
| 对应功能 | §8 权限（客服只看自己的工单）、§4.1 批量操作 |
| 依赖 | 一期 CS-109 |
| 关联 | 一期 CS-103（权限码）、CS-307（三期负载均衡依赖分配口径） |
| 预估 | 1.5 人天 |

**目标**：普通客服只能看见/处理分配给自己的工单，主管可见全部；支持批量分配与批量关闭。

**实现的功能**
- 数据范围：普通客服 = 分配给自己的 + 未分配（可选）；主管 = 全部
- 批量分配（转交）
- 批量关闭（需 `cs.ticket.handle`）
- 列表「只看我的」快捷筛选

**实现步骤**
1. 数据范围服务 `CsTicketService::scopeForStaff($staff)`：
   - 有 `cs.ticket.view` 且具「主管」标记（如权限 `cs.ticket.manage_all` 或角色为 `operator`/超管）→ 全部
   - 普通客服 → `assignee_id = 自己 OR assignee_id IS NULL`（未分配工单可被认领，评审确认是否包含）
   - 作用域仅在**查询层**统一施加，控制器不得各自拼接条件
2. 列表接口增加 `only_mine=1` 参数（前端快捷筛选），与数据范围叠加生效。
3. 批量接口：
   - `POST /api/admin/cs/tickets/batch-assign`（`ids[]` ≤ 100、`assignee_id`）
   - `POST /api/admin/cs/tickets/batch-close`（`ids[]` ≤ 100、`reason`）
   - 事务 + 逐条走 `transitionTo()`，部分失败整批回滚
4. 权限：批量关闭需 `cs.ticket.handle`；转交给他人需 `cs.ticket.handle`。
5. 前端（ADMIN）：列表增加「只看我的」开关、批量选择列、批量操作条（选中 N 条 → 转交/关闭）。
6. 越权访问单个工单（非自己的且无主管权限）→ 404。

**测试要求**
- 单元测试（≥4 条）：数据范围判定（普通/主管/未分配）；`only_mine` 与范围叠加；批量上限校验；部分失败的回滚判定。
- 集成测试（≥8 条）：
  1. 普通客服列表只含自己 + 未分配工单；
  2. 主管列表含全部；
  3. 普通客服访问他人工单详情 404；
  4. 批量转交 3 条全部更新；
  5. 批量关闭 3 条全部关闭且原因一致；
  6. 批量含 1 条非法状态 → 整批回滚（无部分成功）；
  7. 超过 100 条 → 422；
  8. 无 `cs.ticket.handle` 调批量 → 403。
- 回归测试：**R4 权限**（新增权限码与范围逻辑不得影响既有角色）、一期 `admin/tests/cs-ticket.test.ts`。

**验收标准（Acceptance Criteria）**
- AC-212.1 数据范围在查询层统一施加，两类账号（`普通客服`/`主管`）结果符合预期。
- AC-212.2 越权访问单条工单 404，不泄露存在性。
- AC-212.3 批量操作原子性：部分失败整批回滚。
- AC-212.4 批量上限与权限校验生效（100 条上限、403）。
- AC-212.5 前端「只看我的」与批量操作条可用，选中计数正确。

**验收证据**：`pest-CS-212.txt`、`vitest-CS-212.txt`、`curl-CS-212.md`、`ui-CS-212-batch.png`、`commit-CS-212.txt`

---

### CS-213 [WEB] 服务中心首页升级（服务公告 + 工单搜索）
| 项 | 内容 |
|----|------|
| 阶段/主线 | 二期 / 前端 |
| 对应功能 | §2.1 服务公告、§3.5 首页搜索（搜工单） |
| 依赖 | CS-201、一期 CS-111 |
| 关联 | CS-215（自助工具）、二期公告管理接口（本任务内新增） |
| 预估 | 1.5 人天 |

**目标**：首页补齐服务公告位，并让搜索框同时支持搜帮助文章与搜自己的工单。

**实现的功能**
- 服务公告（置顶公告 + 列表，后台可维护）
- 统一搜索（文章结果 + 工单结果分区展示）
- 公告详情/跳转

**实现步骤**
1. 后端补充（本任务内）：`GET /api/cs/announcements`（用户端，返回已发布公告，置顶优先）、`GET /api/admin/cs/announcements` CRUD（权限 `cs.faq.manage`）。
2. 首页公告区：置顶公告横幅（可关闭本次会话）+ 「更多公告」入口；无公告时隐藏区块（不占位）。
3. 统一搜索：搜索框输入后展示结果面板，分「帮助文章」「我的工单」两区；工单搜索走 `GET /api/cs/tickets?keyword=`（CS-106 已支持工单号/订单号，本任务扩展标题/描述匹配）。
4. 搜索无结果：展示「联系客服」引导（复用一期逻辑）。
5. 公告详情：`AnnouncementView.vue`（或弹层），展示标题、正文、发布时间。
6. H5 适配：公告横幅可横向滚动/换行，搜索面板全屏展示。

**测试要求**
- 单元测试（公告接口 ≥3 条）：只返回已发布且按置顶 + 时间排序；草稿不可见；空公告返回空数组。
- 前端单元测试（`web/tests/cs-home-upgrade.test.ts`，≥4 条）：
  1. 有公告时渲染横幅，无公告时隐藏区块；
  2. 搜索结果分两区渲染；
  3. 工单搜索命中标题/描述；
  4. 无结果展示引导。
- 回归测试：一期 `web/tests/service-center.test.ts` 全绿（首页结构改动不破坏既有区块）。

**验收标准（Acceptance Criteria）**
- AC-213.1 公告只对已发布内容可见，置顶优先，草稿不可见。
- AC-213.2 无公告时首页不出现空区域。
- AC-213.3 统一搜索同时返回文章与工单，分区展示，点击可跳转详情。
- AC-213.4 搜索无结果展示「联系客服」引导。
- AC-213.5 一期首页既有用例全绿。

**验收证据**：`pest-CS-213.txt`、`vitest-CS-213.txt`、`curl-CS-213.md`、`ui-CS-213-announcement.png`、`commit-CS-213.txt`

---

### CS-214 [BE] 退换货与服务工单衔接
| 项 | 内容 |
|----|------|
| 阶段/主线 | 二期 / 售后联动 |
| 对应功能 | §5.3 退换货与服务工单的衔接 |
| 依赖 | CS-202 |
| 关联 | 一期 CS-106（提单可选订单）、既有 `RefundService` |
| 预估 | 1.5 人天 |

**目标**：用户在服务中心选择「退换货」时可直达售后申请；工单可关联售后单号，售后状态变更回写工单进度。

**实现的功能**
- 工单关联售后单号（`refund_no`）
- 服务中心「申请退换货」直达可售后订单
- 售后状态变更 → 工单系统消息回写（可选开关）
- 工单详情展示售后单进度

**实现步骤**
1. 数据：`cs_ticket` 增加 `refund_id`(bigint nullable) 与 `refund_no`(varchar 64 nullable) + 索引 `[refund_no]`（本任务内补迁移，规范同 CS-101）。
2. 提单：`POST /api/cs/tickets` 支持传 `refund_id`（校验归属当前用户且存在），落库时冗余 `refund_no`。
3. 服务中心入口：类型=换货/退货类工单，前端引导「直接申请售后」（跳 `RefundApplyView` 并带 `order_id`），也可选择「先咨询客服」建工单（同一入口二选一，避免重复流程）。
4. 状态回写：监听既有 `RefundResult` 事件（或退款处理服务回调），在工单下写 `system` 消息「售后单 X 已通过/已完成/已拒绝」；回写开关由配置 `cs.refund_sync_enabled` 控制（默认 true）。
5. 工单详情（用户端 + 后台）：`order_snapshot` 中展示售后单进度（沿用 CS-202 的快照逻辑，补 `refund_no`/`status`）。
6. 权限与边界：售后单不属于该用户 → 40000；工单已关闭仍可接收回写（只写消息不改状态）。

**测试要求**
- 单元测试（≥4 条）：`refund_id` 归属校验；冗余 `refund_no` 一致性；回写开关关闭时不写消息；已关闭工单回写不复活。
- 集成测试（≥7 条）：
  1. 提单关联售后单成功；
  2. 关联他人售后单 → 40000；
  3. 售后审核通过 → 工单产生系统消息；
  4. 回写开关关闭 → 无消息；
  5. 已关闭工单收到回写只加消息不变状态；
  6. 工单详情展示售后进度；
  7. 用户端与后台快照一致（不含内部字段）。
- 回归测试：**R2 退款**（**重点**：不得改动 `RefundService` 既有行为与退款用例）、**R1 订单**。

**验收标准（Acceptance Criteria）**
- AC-214.1 工单可关联售后单，归属校验严格（他人单 40000）。
- AC-214.2 售后状态变更回写工单消息（开关可控），且**不修改工单状态**。
- AC-214.3 已关闭工单接收回写后仍为关闭态。
- AC-214.4 工单详情（两端）展示售后进度。
- AC-214.5 **既有退款接口与用例零改动、全绿**（关键回归项）。

**验收证据**：`pest-CS-214.txt`、`curl-CS-214.md`、`sql-CS-214.md`（关联与回写核对）、`commit-CS-214.txt`

---

### CS-215 [WEB] 自助服务工具入口落地
| 项 | 内容 |
|----|------|
| 阶段/主线 | 二期 / 前端 |
| 对应功能 | §3.2 自助服务工具 |
| 依赖 | 一期 CS-111 |
| 关联 | CS-214（退换货入口）、既有订单与地址模块 |
| 预估 | 1.0 人天 |

**目标**：把设计文档 §3.2 的自助工具表落地为真实可点的入口，减少无谓人工工单。

**实现的功能**
- 查物流（订单列表 / 输入订单号查轨迹）
- 申请退换货（可售后订单列表）
- 修改地址（未发货订单，若订单模块支持）
- 发票申请（提交开票工单，走「其他」类型 + 备注）
- 联系客服（提单 / 在线客服入口占位）

**实现步骤**
1. 首页快捷工具从 3 个扩展为 5 个：查物流、申请退换货、修改地址、发票申请、联系客服。
2. 查物流：跳 `/orders`（列表）并提供「输入订单号查轨迹」弹窗（复用既有物流查询接口/页面；若接口不支持按单号直接查，则退化为跳订单列表）。
3. 申请退换货：跳可售后订单列表（复用 `RefundApplyView` 的订单选择逻辑），与 CS-214 衔接。
4. 修改地址：仅对未发货订单（`pending_payment`/`paid`/`pending_ship`）展示入口，跳地址编辑页并带 `order_id`；**若订单模块不支持改地址则隐藏该入口**（不展示不可用功能）。
5. 发票申请：创建类型=其他、标题「发票申请」的工单，预填描述模板（订单号 + 开票信息提示）。
6. 联系客服：一期跳提单页；三期 CS-303 上线后改为在线会话入口（预留开关）。
7. 埋点（可选）：记录各入口点击次数，供三期 CS-308 分析自助率。

**测试要求**
- 前端单元测试（`web/tests/cs-selfservice.test.ts`，≥5 条）：
  1. 五个入口按功能可用性渲染（不支持的功能隐藏）；
  2. 修改地址入口只对未发货订单出现；
  3. 发票申请创建工单时预填内容正确；
  4. 查物流跳转与单号查询弹窗；
  5. 联系客服按要求跳转。
- 集成测试：mock 订单状态，验证入口显隐矩阵。
- 回归测试：既有订单/退款前端用例全绿（`web/tests/order-center.test.ts`）。

**验收标准（Acceptance Criteria）**
- AC-215.1 五个自助入口全部可点且跳转目标正确。
- AC-215.2 不可用的能力（如未支持改地址）自动隐藏，不展示死链。
- AC-215.3 修改地址入口只对未发货订单出现。
- AC-215.4 发票申请生成的工单内容完整（含订单号与开票提示）。
- AC-215.5 既有订单/退款前端用例全绿。

**验收证据**：`vitest-CS-215.txt`、`ui-CS-215-tools.png`、`commit-CS-215.txt`

---

## 批次 F：二期质量与验收（CS-216）

### CS-216 [QA] 二期专项测试与回归
| 项 | 内容 |
|----|------|
| 阶段/主线 | 二期 / 阶段出口（Gate G2） |
| 对应功能 | 全模块 |
| 依赖 | CS-201 ~ CS-215 |
| 关联 | 三期 CS-301（G2 通过后方可开工） |
| 预估 | 1.5 人天 |

**目标**：对二期新增能力做专项验证（评价、配置、自动关闭、看板口径、数据权限、售后联动），并确认一二期整体回归。

**实现的功能**
- 二期专项用例集（6 个专题）
- 看板数据手工核算对照
- 一二期整体回归报告
- 阶段验收报告

**实现步骤**
1. 专项一 满意度：状态前置、重复评价、并发评价、自动关闭联动。
2. 专项二 配置与自动关闭：默认值回退、边界值、命令幂等与分批上限、调度注册。
3. 专项三 看板口径：构造 20 条已知时间差与状态的工单，逐项手工核算平均首响、平均解决、类型分布、满意度、排行，与接口值对照（误差 0）。
4. 专项四 数据权限：普通客服/主管/超管 × 列表/详情/批量操作矩阵。
5. 专项五 售后联动：关联校验、回写开关、已关闭工单行为、**退款既有用例零改动**。
6. 专项六 前端：评价、配置、看板、批量、自助工具五个页面的交互用例。
7. 整体回归：R1~R7 全量执行（限定范围，不做全项目测试）。
8. 编写 `acceptance-CS-216.md`：结论、遗留问题、三期交接项（如一期 AC-117.7 中「基础服务数据可查」在本期闭环）。

**测试要求**
- 单元测试：六个专题的服务层纯逻辑用例（复用各任务单元用例，本任务汇总执行）。
- 集成测试：跨专题链路（提单 → 关联售后 → 完成 → 评价 → 自动关闭）一条龙用例。
- 回归测试：**R1~R7 全量** + 一期全部客服模块用例（CS-101~CS-117 产生的用例）重跑。

**验收标准（Acceptance Criteria，Gate G2）**
- AC-216.1 六个专项用例全部通过，无阻塞缺陷。
- AC-216.2 看板五类指标与手工核算完全一致（对照表零误差）。
- AC-216.3 数据权限矩阵无越权，批量操作原子性成立。
- AC-216.4 售后联动不改动既有退款行为，退款相关用例全绿。
- AC-216.5 R1~R7 回归全绿，一期客服模块用例无回退。
- AC-216.6 设计文档 §10 成功标准全部闭环（含「基础服务数据可查」）。
- AC-216.7 阶段验收报告产出，遗留问题已登记并明确归属期次。

**验收证据**：`pest-CS-216.txt`、`pest-pgsql-CS-216.txt`、`vitest-CS-216.txt`、`sql-CS-216.md`（看板核算对照表）、`acceptance-CS-216.md`、`commit-CS-216.txt`

---

**—— 二期任务清单结束 ——**
