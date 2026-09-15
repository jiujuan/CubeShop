# CubeShop V1.1 一期阶段出口验收报告（T-030）

| 项 | 内容 |
|----|------|
| 版本 | V1.1-P1（一期） |
| 验收日期 | 2026-09-16 |
| 验收范围 | T-001 ~ T-030（一期全部任务） |
| 数据库 | PostgreSQL（开发库 `cubeshop`）+ SQLite（`phpunit.xml`）+ PG 测试库（`cubeshop_test`） |
| 验收人 | 研发自验（自动化为主，人工冒烟为辅） |
| 结论 | **✅ 可进入二期** |

---

## 1. 结论摘要

| 维度 | 结果 |
|------|------|
| 后端用例（SQLite） | **315 passed / 937 assertions** |
| 后端用例（PostgreSQL） | **315 passed / 937 assertions** |
| 用户端用例（Vitest，web） | **71 passed / 8 files** |
| 后台用例（Vitest，admin） | **44 passed / 6 files** |
| 冒烟（端到端，真实 HTTP + 真实 PG 库） | **25 / 25 通过** |
| 类型检查 | web / admin `vue-tsc -b` 均 0 错误 |
| 生产构建 | web / admin `vite build` 均成功 |
| P0/P1 遗留缺陷 | **0** |
| 一期任务完成度 | **30 / 30** |

**结论：一期范围内自动化用例双库全绿、端到端冒烟全通过、无 P0/P1 遗留，验收通过，具备进入二期（V1.1-P2 营销与履约）的条件。**

---

## 2. 用例盘点（逐任务）

| 任务 | 内容 | 用例数 | 证据 |
|------|------|--------|------|
| T-001 | order_logs 表与订单状态变更落库 | 后端 23（SQLite+PG） | `T-001/pest-T-001.txt`、`pest-pgsql-T-001.txt` |
| T-002 | 用户确认收货接口 | 后端 18（SQLite+PG） | `T-002/pest-T-002.txt`、`pest-pgsql-T-002.txt` |
| T-003 | 自动确认收货 Command 与配置项 | 后端 18（SQLite+PG） | `T-003/pest-T-003.txt`、`pest-pgsql-T-003.txt` |
| T-004 | 再次购买接口与订单列表查询扩展 | 后端 11（SQLite+PG） | `T-004/pest-T-004.txt`、`pest-pgsql-T-004.txt` |
| T-005 | 订单详情状态时间轴与确认收货按钮 | web 12 | `T-005/vitest-T-005.txt` |
| T-006 | 订单列表分组、检索与再次购买 | web 12 | `T-006/vitest-T-006.txt` |
| T-007 | 品牌/属性库/模板五表与模型 | 后端 36 / 30（PG） | `T-007/pest-T-007.txt`、`pest-pgsql-T-007.txt` |
| T-008 | 品牌、属性库、分类模板管理接口 | 后端 36 / 30（PG） | `T-008/pest-T-008.txt`、`pest-pgsql-T-008.txt` |
| T-009 | SKU 笛卡尔积生成与差异合并 | 后端 36 / 30（PG） | `T-009/pest-T-009.txt`、`pest-pgsql-T-009.txt` |
| T-010 | 商品编辑表单重构 | admin 9 | `T-010/vitest-T-010.txt` |
| T-011 | 品牌/属性库/分类模板管理页 | admin 9 | `T-011/vitest-T-011.txt` |
| T-012 | 存量 specs 迁移脚本与校对清单 | 后端 6 | `T-012/pest-T-012.txt` |
| T-013 | 详情页规格置灰联动与参数表 | web 8 | `T-013/vitest-T-013.txt` |
| T-014 | 列表/搜索页按属性筛选 | 后端 21 + web 8 | `T-014/pest-T-014.txt`、`vitest-T-014.txt` |
| T-015 | 评价表与提交/修改/列表接口、评分汇总 | 后端 32（SQLite+PG） | `T-015/pest-T-015.txt`、`pest-pgsql-T-015.txt` |
| T-016 | 评价提交入口与详情页评价区 | web 13 | `T-016/vitest-T-016.txt` |
| T-017 | 评价管理页（审核/回复/删除） | 后端 24 + admin 8 | `T-017/pest-T-017.txt`、`vitest-T-017.txt` |
| T-018 | 通知服务、事件挂接与邮件队列 | 后端 15（SQLite+PG） | `T-018/pest-T-018.txt`、`pest-pgsql-T-018.txt` |
| T-019 | 顶栏通知铃铛与通知中心页 | web 11 | `T-019/vitest-T-019.txt` |
| T-020 | 报表聚合接口 | 后端 13（SQLite+PG） | `T-020/pest-T-020.txt`、`pest-pgsql-T-020.txt` |
| T-021 | 仪表盘改版与报表中心页 | admin 9 | `T-021/vitest-T-021.txt` |
| T-022 | 管理员账号与角色管理接口、权限码种子 | 后端 25（SQLite+PG） | `T-022/pest-T-022.txt`、`pest-pgsql-T-022.txt` |
| T-023 | 账号管理页与角色权限配置页 | admin 8 | `T-023/vitest-T-023.txt` |
| T-024 | 收藏与浏览足迹表、接口与去重 | 后端 14（SQLite+PG） | `T-024/pest-T-024.txt`、`pest-pgsql-T-024.txt` |
| T-025 | 收藏页、足迹页与详情页收藏按钮 | web 8 | `T-025/vitest-T-025.txt` |
| T-026 | 个人中心页与全局入口改造 | web 7 | `T-026/vitest-T-026.txt` |
| T-027 | 修改密码强化（旧密码+强度+撤销其他 Token） | 后端 8（SQLite+PG）+ web 7 | `T-027/pest-T-027.txt`、`pest-pgsql-T-027.txt`、`vitest-T-027.txt` |
| T-028 | 地址增强（标签/行政区划/智能解析/常用度） | 后端 12（SQLite+PG） | `T-028/pest-T-028.txt`、`pest-pgsql-T-028.txt` |
| T-029 | 地址页升级与结算页内联新增 | web 7 | `T-029/vitest-T-029.txt` |
| T-030 | 阶段出口验收 | 全量 + 冒烟 | `T-030/`（本目录） |

> 说明：同一测试文件服务多个任务时（如 `OrderLifecycleApiTest` 覆盖 T-002/T-003、`ProductAttributeServiceTest`+`AttributeApiTest` 覆盖 T-007~T-009），各任务证据目录保留同一份运行输出的副本，便于逐任务追溯。

---

## 3. 全量执行结果

### 3.1 后端（Pest 4.7）

| 环境 | 命令 | 结果 |
|------|------|------|
| SQLite | `php vendor/bin/pest` | `315 passed (937 assertions)`，29.14s |
| PostgreSQL | `php vendor/bin/pest --configuration=phpunit.pgsql.xml` | `315 passed (937 assertions)`，84.41s |

证据：`pest-T-030.txt`、`pest-pgsql-T-030.txt`。

### 3.2 前端

| 工程 | 命令 | 结果 |
|------|------|------|
| web（用户端） | `npx vitest run` | `8 files / 71 tests passed` |
| admin（后台） | `npx vitest run` | `6 files / 44 tests passed` |
| web 类型检查 | `npx vue-tsc -b` | 0 error |
| admin 类型检查 | `npx vue-tsc -b` | 0 error |
| web 生产构建 | `npx vite build` | 成功（built in ~3.7s） |
| admin 生产构建 | `npx vite build` | 成功 |

证据：`vitest-web-T-030.txt`、`vitest-admin-T-030.txt`。

### 3.3 冒烟（真实 HTTP + 真实 PG 开发库）

`bash docs/testing/smoke_test.sh` → **PASS 25 / FAIL 0**。证据：`smoke-T-030.txt`。

覆盖链路（在 TestPlan v1.0 基线 15 项上新增一期 10 项）：

```
健康检查 → 注册 → 登录 → 商品列表/详情
→ 管理员登录 → 建属性化商品 → 新增地址 → 加购 → 下单 → 发起支付 → 沙箱支付 → 订单 paid
→ 后台发货
→ [V1.1] 用户确认收货 → 订单 completed
→ [V1.1] 商品评价提交
→ [V1.1] 收藏商品 / 收藏列表 / 足迹上报
→ [V1.1] 站内通知未读数
→ [V1.1] 修改密码 → 新密码可登录（旧 Token 失效）
→ [V1.1] 头像上传（multipart）
→ 退出登录
```

> 运行注意：`/auth/captcha` 与登录接口有 IP 级限流（10/min、5/min）。连续多次跑冒烟会命中限流，需先 `php artisan cache:clear` 并间隔约 5s 再执行。已在脚本使用说明中记录。

---

## 4. 手工回归必测项核对

| 必测项 | 覆盖方式 | 结果 |
|--------|----------|------|
| 存量商品：浏览 → 加购 → 下单 → 支付 → 发货 → 确认收货 → 评价 | 冒烟脚本第 3~5c 步（真实 PG 库） | ✅ |
| 属性化新商品同样走一遍 | 冒烟脚本第 4 步用 SKU 结构创建新商品并全链路 | ✅ |
| 个人中心：改密 → 其他设备 Token 失效 → 通知未读数 | 冒烟 5e/5f（改密后新登录成功 = 旧 Token 已失效）+ T-027 接口用例断言旧 Token 401、当前 Token 可用 | ✅ |
| 后台：账号新增/禁用、角色权限生效、评价审核与回复、报表数字 | T-022/T-023 接口与页面用例 + T-017/T-020 用例（含 SQL 核对断言） | ✅ |

---

## 5. 缺陷清单（一期开发过程中发现并已修复）

| # | 等级 | 任务 | 缺陷 | 修复 |
|---|------|------|------|------|
| 1 | P0 | T-001~T-003 | 订单状态机允许 `shipped→completed`，但**无确认收货接口、无自动确认任务**，订单永久停在「已发货」，直接阻塞评价功能 | 新增 `order_logs` 落库 + `POST /orders/{id}/confirm` + `orders:auto-complete` 定时任务 |
| 2 | P1 | T-009 | `ProductAttributeService` 使用 MySQL `FIELD()` 排序属性值，SQLite/PG 不支持 | 改为内存按传入顺序排序，双库一致 |
| 3 | P1 | T-022 | 操作日志按日期筛选时，`end` 传「当天」（date-only）会排除当天所有记录 | 日期型边界归一化为当日 23:59:59 |
| 4 | P1 | T-028 | 地址智能解析省份后，城市循环误加 `break`，导致「省 + 市 + 区」只识别出市 | 修正循环，直辖市/特区特殊处理 |
| 5 | P2 | T-025 | 收藏页批量取消后 `load()` 立即清空 `tip`，用户看不到操作结果提示 | 调整为「先刷新、后提示」 |
| 6 | P2 | T-004 | 订单关键词检索按 JSON 列模糊匹配，PG（jsonb）与 SQLite（TEXT）语法不同导致报错 | 按驱动分支：PG 用 `::text ILIKE`，SQLite 用 `LIKE` |
| 7 | P2 | T-009/T-010 | 后台商品保存 SKU 编码唯一冲突时 PG/SQLite 返回 500 | 捕获唯一约束异常，转业务错误「SKU 编码已存在」 |
| 8 | P3 | — | 前端测试基础设施：`@testing-library/vue` 未安装；路由断言因初始导航覆盖而失败；价格跨节点断言失败 | 安装依赖；改为渲染后 `router.push`+`waitFor`；改用 `textContent` 包含断言 |

> 等级口径：P0=阻断核心链路；P1=功能不可用/数据错误；P2=体验/边界；P3=测试基建。**无 P0/P1 遗留。**

---

## 6. 遗留项与风险

| # | 项 | 等级 | 处理阶段 |
|---|----|------|----------|
| 1 | 优惠券、物流轨迹、首页装修尚未开发 | — | 二期（T-031~T-054） |
| 2 | 物流发货接口当前仅接受 `remark`（冒烟中的 `company`/`tracking_no` 被忽略）；轨迹查询需申请第三方账号 | P2 | 二期 T-045/T-046 |
| 3 | 积分、搜索增强、批量导入 | — | 三期（T-055~T-069） |
| 4 | 商品属性存量数据迁移（T-012）已提供脚本与校对清单，**上线前需人工执行并校对一次** | P2 | T-070/T-074 迁移演练 |
| 5 | 冒烟脚本受接口限流影响，需清缓存后执行（已记录） | P3 | 运维注意事项 |
| 6 | `admin` 端图表为无依赖 SVG 实现（未引入 ECharts），如需更丰富交互可后续替换 | P3 | 按需 |

---

## 7. 出口评审（对照路线图 §3.1 交付物与验收标准）

| 验收标准 | 结论 |
|----------|------|
| 一期 30 个任务全部完成，接口与页面可用 | ✅ 30/30 |
| 单元 + 接口/组件用例全绿 | ✅ 双库 315 + web 71 + admin 44 |
| SQLite / PostgreSQL 双库通过 | ✅ 一致 |
| V1.0 既有交易闭环未被破坏（回归全绿） | ✅ 冒烟 25/25 + 全量用例覆盖下单/支付/退款/发货 |
| 无 P0/P1 遗留缺陷 | ✅ |
| 证据目录完整可追溯 | ✅ `docs/testing/evidence/v1.1/T-001~T-030/` |
| 类型检查与生产构建通过 | ✅ |

---

## 8. 证据索引

| 文件 | 说明 |
|------|------|
| `pest-T-030.txt` | 后端全量（SQLite） |
| `pest-pgsql-T-030.txt` | 后端全量（PostgreSQL 测试库） |
| `vitest-web-T-030.txt` | 用户端全量（Vitest） |
| `vitest-admin-T-030.txt` | 后台全量（Vitest） |
| `smoke-T-030.txt` | 端到端冒烟（真实 HTTP + PG） |
| `docs/testing/smoke_test.sh` | 冒烟脚本（已扩展一期增量用例） |
| `docs/design/plan/README.md` §8 | 进度看板（一期 30/30） |

---

**验收结论：一期（V1.1-P1）验收通过，可进入二期。**
