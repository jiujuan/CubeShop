# CubeShop 电商系统

## 测试计划任务文档（Test Plan Document）

| 项 | 内容 |
|---|---|
| 版本 | v1.0 |
| 日期 | 2026-09-15 |
| 测试对象 | backend（Laravel 13）/ admin（Vue3 管理端）/ web（Vue3 用户端） |
| 测试工具 | 后端 **Pest 3**（基于 PHPUnit 12）；前端 **Vitest**（@vue/test-utils + jsdom） |
| 目标 | 确保已完成功能不出现 **P0 / P1 级重大缺陷** |
| 依据 | `CubeShop_TestCases_v1.0.md`（25 P0 + 14 P1 用例）、`CubeShop_API_v1.0.md`、`CubeShop_Database_Design_v1.0.md` |

---

## 1. 目的与背景

此前开发阶段以手工 curl 验证与一次 P0 回归为主，缺少系统化自动化测试。本计划以 Pest / Vitest 建立可持续执行的自动化测试体系，覆盖三个层次：

1. **单元测试**：服务层（业务规则）与前端 store / 工具函数
2. **集成测试**：后端 HTTP API 全链路（数据库真实读写、事务回滚）
3. **冒烟测试**：核心链路一次性通过 + 前端生产构建

---

## 2. 缺陷定级标准

| 级别 | 定义 | 判定示例 |
|---|---|---|
| **P0** | 阻断核心流程、资损、数据错乱、安全漏洞 | 下单扣错库存/超卖；支付状态机错乱；越权访问他人订单；密码明文；核心接口 500 |
| **P1** | 主要功能不可用但有绕行方案、明显逻辑错误 | 优惠券金额算错；搜索排序错；删除地址后默认地址悬挂；错误提示文案严重误导 |
| P2/P3 | 体验/文案/样式问题 | 不在本轮测试目标内，记录不阻断 |

**通过标准**：单元/集成/冒烟全部执行完毕，**P0 = 0、P1 = 0**（发现即修复并回归验证）。

---

## 3. 单元测试计划

### 3.1 后端（Pest，`tests/Unit`，不触库或用 SQLite 内存库）

| 测试对象 | 用例要点 | 数量 |
|---|---|---|
| `InventoryService` | 锁定/释放/扣减的加减速正确性；锁定不足时抛业务异常；流水记录 change_qty 符号约定 | ~8 |
| `OrderService` | 订单号生成唯一性；金额快照计算（商品额+运费）；状态机非法流转拒绝；超时取消判定 | ~8 |
| `PaymentService` | 回调验签；重复回调幂等；金额不一致拒绝入账 | ~6 |
| `RefundService` | 退款金额≤实付；重复申请拒绝；审核状态流转 | ~5 |
| `NoGeneratorService` | 各类单号格式与唯一性 | ~3 |
| `ConfigService` | 数据库配置覆盖默认值；类型转换 | ~3 |
| `ApiResponse` | 统一响应结构 code/message/data | ~3 |
| `CaptchaService` | 生成/校验/一次性消费/过期 | ~4 |

### 3.2 前端（Vitest，`admin/tests` 与 `web/tests`）

| 测试对象 | 用例要点 | 数量 |
|---|---|---|
| `stores/auth.ts`（两端） | 登录态写入/清除；Token 持久化与恢复；登出清理 | ~6 |
| `lib/request`（admin）/ `api` 封装（web） | 统一错误处理；401 触发登出跳转 | ~4 |
| `directives/permission.ts`（admin） | 有权限渲染 / 无权限移除节点 | ~3 |
| 金额/日期格式化工具 | 千分位、状态文案映射 | ~4 |

---

## 4. 集成测试计划

### 4.1 后端（Pest，`tests/Feature`，SQLite 内存库 + `RefreshDatabase` + 种子数据）

按 TestCases 文档用例编号映射（HTTP 层全链路：路由→中间件→控制器→服务→库）：

| 模块 | 覆盖用例 | 关键断言 |
|---|---|---|
| 认证 | USER-001/002/003/004/005/006/007/008 | code=0；重复注册 40x；验证码一次性；未登录 401 |
| 商品 | PROD-001/002/003/004/005 | 分页/搜索/筛选；下架与零库存不可购买 |
| 购物车 | CART-001~005 | 增删改查；超库存拒绝；按 sku 定位删除 |
| 订单 | ORDER-001~010（含超时取消、取消释放库存） | 快照金额；状态机；并发语义前置校验 |
| 支付/退款 | （ORDER-004/005 路径）+ 回调幂等 | 沙箱支付回调入账；重复回调只入账一次 |
| 后台 | ADMIN-001~008 | 权限拦截（无权限 403）；发货状态流转；导出响应 |
| 系统 | SYS-001/002/003 | 操作日志落库；限流 429；健康检查 |

### 4.2 前端（Vitest + @vue/test-utils，mock API 层）

- 组件级集成：登录表单提交→store 更新→路由跳转；购物车数量增减→汇总联动
- 路由守卫：未认证访问受保护页面重定向登录

---

## 5. 冒烟测试计划

一次性脚本 `docs/testing/smoke_test.sh`（依赖服务运行）：

1. `GET /api/health` 返回 code=0
2. 注册新用户 → 登录 → 获取 Token
3. 商品列表/详情可访问
4. 加购 → 下单 → 沙箱支付 → 订单状态 paid
5. 管理员登录 → 发货成功
6. admin 执行 `npm run build`、web 执行 `npm run build` 零错误

---

## 6. 环境与执行方式

| 项 | 约定 |
|---|---|
| 后端测试库（日常） | SQLite 内存（phpunit.xml 已配置），`RefreshDatabase` 隔离 |
| 后端测试库（PG 回归） | 独立 PostgreSQL 库 `cubeshop_test`（127.0.0.1:5432，phpunit.pgsql.xml），与开发库 `cubeshop` 隔离 |
| 后端执行（日常） | `cd backend && ./vendor/bin/pest` 或 `composer test:sqlite` |
| 后端执行（PG 回归） | `cd backend && composer test:pgsql`（首次前需创建测试库，脚本见 §6.1） |
| 前端执行 | `cd admin && npm test` / `cd web && npm test`（vitest run） |
| 冒烟前置 | `artisan serve` 运行于 127.0.0.1:8000，数据库已 seed |
| CI 可重复 | 全部测试不依赖外部服务状态（缓存用 array 驱动） |

### 6.1 PostgreSQL 兼容性回归

SQLite 与 PostgreSQL 在序列行为、锁语义、约束报错格式上存在差异，集成测试需定期在 PG 下全量回归：

```bash
# 一次性创建独立测试库（已存在则重建为空库）
php -r "$pdo=new PDO('pgsql:host=127.0.0.1;port=5432;dbname=postgres','postgres','admin123'); \
  $pdo->exec('DROP DATABASE IF EXISTS cubeshop_test'); \
  $pdo->exec('CREATE DATABASE cubeshop_test ENCODING \'UTF8\'');"

# 全量回归（111 用例，RefreshDatabase 自动 migrate:fresh 到 cubeshop_test）
cd backend && composer test:pgsql
```

> **跨库差异教训（2026-09-15 首轮 PG 回归）**：PG 的序列不随事务回滚，而 SQLite rowid 回滚后重用——测试若硬编码自增 id（如 `category_id => 1`）在 PG 全量跑时会因序列已推进而校验失败。规范：测试数据一律通过工厂函数（`createTestCategory()` / `createTestSku()` 等）动态取 id，禁止硬编码。

---

## 7. 任务清单

- [x] 后端安装配置 Pest（兼容 PHPUnit 12，Pest 4.7 + pest-plugin-laravel 4.1）
- [x] 后端单元测试（§3.1）编写并全绿 —— tests/Unit：59 用例
- [x] 后端集成测试（§4.1）编写并全绿 —— tests/Feature：52 用例
- [x] admin 安装配置 Vitest；单元/组件测试（§3.2/§4.2）全绿 —— 10 用例
- [x] web 安装配置 Vitest；单元/组件测试（§3.2/§4.2）全绿 —— 5 用例
- [x] 冒烟测试脚本（§5）执行通过（含两端构建）—— docs/testing/smoke_test.sh
- [x] 缺陷汇总与 P0/P1 修复 + 回归（见 §8，共 5 项）
- [x] 测试报告 + 路线图勾选 + git 提交

## 8. 缺陷记录表

| 编号 | 级别 | 所属层 | 描述 | 修复 | 回归结果 |
|---|---|---|---|---|---|
| BUG-T-001 | **P0** | 后端支付 | 回调不校验金额：用合法签名传 0.01 也能将整单入账（资损风险） | handleCallback 增加 bccomp 金额一致性校验 | 已修复，专项测试通过 |
| BUG-T-002 | P1 | 后端公共 | 单号生成器碰撞率高（500 个生成仅 311 个唯一；Windows 微秒精度不足），订单唯一索引兜底但无重试 → 高频下单会 500 | 新增 biz_no_sequences 序列表，行锁原子自增「日期+6 位序列」，跨进程唯一 | 已修复，500 连发零重复，PG/SQLite 验证通过 |
| BUG-T-003 | P1 | 后端商品 | Storefront 商品详情对不存在商品用 fail() 返回 HTTP 200+code 40004，违背全局 404 映射约定 | 改为抛 BusinessException::notFound（HTTP 404） | 已修复 |
| BUG-T-004 | P2 | 后端公共 | 幂等支付回调（渠道重发）不写审计日志，重发通知无痕迹 | 幂等分支补 PaymentLog 记录 | 已修复 |
| BUG-T-005 | P2 | 后端后台 | SKU 编码唯一约束冲突处理仅识别 PostgreSQL 报错格式，SQLite 下仍返回 50000 | 兼容 SQLite「UNIQUE constraint failed」格式 | 已修复 |

> 说明：测试过程中发现的「多用户 token 互相串号」现象经真实 HTTP 服务验证为**测试进程伪象**（长生命周期进程内 RequestGuard 绑定陈旧请求），已在 tests/TestCase::call 统一 forgetGuards 规避，非应用缺陷。

## 9. 执行结果汇总（2026-09-15）

| 层次 | 工具 | 结果 |
|---|---|---|
| 后端单元（服务层） | Pest 4.7 | 59 passed / 0 failed（158 断言） |
| 后端集成（API 链路） | Pest 4.7 | 52 passed / 0 failed（120 断言） |
| admin 单元/组件 | Vitest 3 | 10 passed / 0 failed |
| web 单元 | Vitest 3 | 5 passed / 0 failed |
| 冒烟（端到端） | smoke_test.sh | 15/15 通过 |
| 生产构建 | vite build | admin ✓ / web ✓ 零错误 |

**结论：P0 = 0、P1 = 0（5 项缺陷均已修复并回归），达到上线测试通过标准。**
执行方式：后端 `cd backend && ./vendor/bin/pest`；前端 `cd admin|web && npm test`；冒烟 `bash docs/testing/smoke_test.sh`。

## 10. PostgreSQL 兼容性回归记录

| 日期 | 结果 | 备注 |
|---|---|---|
| 2026-09-15（首轮） | 111/111 通过（278 断言，33s） | 首轮 3 失败均为测试硬编码 `category_id=1`（PG 序列不回滚所致），改用工厂函数后全绿；未发现应用级跨库缺陷 |
| 2026-09-16 | 428/428 通过（1407 断言，116s） | 全量回归零失败；用例数随 V1.1/V1.2 功能迭代自 111 扩充至 428，PG 侧无新增缺陷，P0 = 0、P1 = 0 |
| 2026-09-17 | 726/726 通过（3044 断言，176s） | 全量回归零失败；用例数进一步扩充至 726（新增 users 拆表迁移/清理、物流轨迹拉取、地址代改等用例），PG 序列对齐、软删唯一索引等场景均通过，P0 = 0、P1 = 0 |
| 2026-09-18 | 909/909 通过（4185 断言，359s） | 首轮 1 失败（TC-ADMIN-005b）：测试辅助 `makeStuckPaidOrder()` 把 API 返回的 `order_id`（P2-11 后为 ULID public_id）直接传入 `Order::whereKey()`，SQLite 弱类型静默匹配 0 行、PG 严格类型抛 22P02；属测试数据假设问题，改用 `oid()` 解析为内部 int 主键后全绿。应用级跨库缺陷 0，P0 = 0、P1 = 0 |
| 2026-09-19 | 1138/1138 通过（5364 断言，267s） | 全量回归零失败；用例数自 909 扩至 1138。首轮因 CLI 默认 128M 内存限制在 zipstream（导出）处中断，改用 `php -d memory_limit=1G` 重跑后全绿，非跨库缺陷。应用级跨库缺陷 0，P0 = 0、P1 = 0 |
| 2026-09-20 | 1326/1326 通过（6334 断言，563s） | 首轮 3 失败：P4U-06（`ReturnInboundOrderServiceTest`）硬编码 `sku_id=1` 触发收货回传「未知货品」分支——PG 共享库序列不回退（自增 id 恒定递增），而 SQLite 内存库每用例重建、id 恒为 1，故该用例 09-19 提交（P4）后首次 PG 回归即暴露；定性为测试数据假设问题，改用真实 `$sku->id` 后修复。另 `CsTicketStateMachineTest` 分类计数 2 例首轮失败（7≠5），单文件与全套件干净重跑均不复现，加探针转储证实无跨用例数据残留，判定首轮瞬态会话异常，非应用缺陷。用例数自 1138 扩至 1326（CMS 一期）。应用级跨库缺陷 0，P0 = 0、P1 = 0 |
| 2026-09-21 | 1446/1446 通过（7108 断言，338s） | 全量回归零失败；用例数自 1326 扩至 1446（CMS 二期及后续迭代）。`php -d memory_limit=1G` 起跑一次通过，PG 序列、ULID 出口、软删唯一索引、WMS 回调幂等等既有敏感场景均未复现问题。应用级跨库缺陷 0，P0 = 0、P1 = 0 |
| 2026-09-24 | 1749 通过 + 1 跳过 / 1750（8067 断言，775s） | 首轮 11 失败全部集中在搜索模块，定性两类：**1 例应用级跨库缺陷（P1，已修复）**——`FallbackLikeEngine` 裸 `LIKE` 在 PG 上大小写敏感（SQLite 对 ASCII 天然不敏感），英文关键词（如「iphone」）在降级链路静默少召回，修复为 LIKE 两侧显式包 `lower()`，跨驱动行为收敛为大小写不敏感；**4 例测试驱动假设**——S1-04-011/012（可用性判定）与 S1-05-023/025（引擎绑定解析）硬编码「跑在 SQLite」前提，改为跟随当前连接驱动断言（PG 库上应判可用/解析出 PG 引擎），S1-06-002 硬编码 `engine='like'`（PG 下正确值为 `postgres`），S1-02-010 超长 title 直接落库触发 PG varchar(255) 严格检查（22001，SQLite 静默放行），改为内存内构造不落库。另 `SearchSynonymTest` 3 例（词频 result_count、to_words 422、更新响应结构）首轮失败但单文件通过、全量干净重跑未复现，同 09-20 判定为瞬态会话异常。用例数自 1446 扩至 1750（S1-10 同义词表与联想限流）。P0 = 0、P1 = 1（已修复并复验） |
| 2026-09-25 | 1848 通过 + 1 跳过 / 1849（8459 断言，728s） | 首两轮失败均由在途 A7 支付对账工作流（未入库文件）实时编辑所致，逐一定性后第三轮干净全量一次通过：①A7S Unit 测试给 `ChannelTransaction::$paidAt`（`?Carbon`）传字符串致 TypeError（8 例）——SQLite 下同样会炸，非跨库问题，随后随工作流自行修复；②A7K-01 命令测试漏算差异类型：本地 success 支付单不在渠道账单必然产生 MISSING_CHANNEL（与 Unit 侧 A7S-05 语义一致），期望 1 条实际 2 条，SQLite/PG 行为完全一致，定性测试数据假设问题，已按 A7S-05 语义修正期望为 2 条差异并双侧复验；③A7C 控制器测试（12 例）在第二轮开跑瞬间新建、对应控制器尚未落地（TDD 红灯快照报 null），控制器落地后 SQLite/PG 双侧均 13/13 通过。用例数自 1750 扩至 1849（A7 支付渠道对账）。应用级跨库缺陷 0，P0 = 0、P1 = 0 |
| 2026-09-26 | 1856 通过 + 1 跳过 / 1857（8518 断言，769s） | 全量回归零失败，一次通过；用例数自 1849 扩至 1857（WMS UAT 前向全链路、计划任务注册等迭代）。PG 序列、ULID 出口、软删唯一索引、`FallbackLikeEngine` lower() 降级、WMS 回调幂等等既往敏感场景均未复现问题。应用级跨库缺陷 0，P0 = 0、P1 = 0 |

| 2026-10-03 | 1992 通过 + 1 跳过 / 1993（9140 断言，887s） | 首轮 9 失败全部集中在退款纠纷模块（#4 当周新落库后首次 PG 回归），定性两类：**1 例应用级跨库缺陷（P1，已修复）**——迁移 000130/000131 把 `public_id` 定义为 varchar(24)，而 ULID 为 26 字符，SQLite 不强制 varchar 长度故本地全绿，PG 严格校验抛 22001，纠纷创建在 PG 上 100% 失败；按项目 ULID 列规范改 char(26)，并同步修正运行库 cubeshop（两表为空，ALTER COLUMN 安全）。**1 例测试数据假设**——纠纷指派用例硬编码 `admin_id=1` 并断言 assignee.id=1，RefreshDatabase 在 PG 上为事务回滚而 PG 序列不回退，非首个用例的 admin id 递增（SQLite 每用例重建恒为 1），改查 `SysUser::where(username,admin)` 真实 id（同 09-20 sku_id=1 模式）。修复后定向 + SQLite 双侧复验，全量干净重跑一次通过。用例数自 1857 扩至 1993（退款纠纷/申诉等迭代）。P0 = 0、P1 = 1（已修复并复验） |
后续定期回归：`cd backend && composer test:pgsql`，结果回填本表。
