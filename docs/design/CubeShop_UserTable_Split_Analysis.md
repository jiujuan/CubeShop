# 用户表拆分方案分析（sys_user 保留管理员 + 新建 users 承载买家）

- 日期：2026-09-16
- 状态：**已定方向，待实施**
- 决策：`sys_user` **保持不动**，继续承载后台管理员；新建买家表拆分前台注册用户

---

## 一、现状盘点（基于代码实测）

### 1.1 表与模型

| 项目 | 现状 |
|---|---|
| 表名 | `sys_user`（单数，注意不是 `sys_users`） |
| 模型 | `App\Models\SysUser`（`HasApiTokens` + `HasRoles` + `SoftDeletes`） |
| 字段 | username / email / phone / password / nickname / avatar / status / last_login_at / last_login_ip |
| 身份区分 | spatie 角色：`customer`（买家）/ `operator`（运营）/ `super_admin`（超管） |
| 认证 | 单 guard `web`，provider → `SysUser`；Sanctum 个人令牌 |
| 登录入口 | **前后台共用** `POST /auth/login`（username 或 phone + 密码 + 验证码），登录后靠角色决定能力 |

### 1.2 耦合面实测数据

| 维度 | 数量 |
|---|---|
| `SysUser` 引用行数（app/routes/config/tests） | 105 处 |
| 引用 `SysUser` 的后端文件（app/routes/config） | 25 个 |
| 引用 `SysUser` 的测试文件 | 18 个 |
| 含 `user_id` 的业务表迁移 | 10 个 |
| 真正带外键约束 `on('sys_user')` 的表 | 3 个（user_addresses / cart_items / orders） |
| 买家侧控制器 | 13 个 |
| 管理侧控制器（Admin/） | 22 个 |
| 前端 web 认证相关文件 | 5 个 |
| 前端 admin 认证相关文件 | 4 个 |

### 1.3 关键隐性成本：同一个 `user_id` 列，语义并不统一

| 表 | `user_id` 实际语义 |
|---|---|
| orders / cart_items / user_addresses | 买家 |
| payments / refunds | 买家 |
| reviews / notifications / favorites / browse_histories | 买家 |
| user_balances / user_balance_logs / balance_recharges | 买家（`balance_logs.created_by` 例外，指管理员） |
| **sys_operation_log.user_id** | **混合**——`AuthController::login` 对买家和后台账号都会写日志，`changePassword` 同样 |
| **user_balance_logs.created_by** | 管理员 |

`sys_operation_log` 在拆分后需要**同时保留两个身份列**（或加 `actor_type` 区分），否则后台审计会丢失买家侧记录，或买家 ID 与管理员 ID 撞号产生错误归属。

---

## 二、目标形态与表名选型

### 2.1 目标形态

```
sys_user   （管理员，不动）   super_admin / operator
users      （买家，新建）     注册买家
```

`sys_user` 不做重命名，这是本次决策带来的最大收益：**22 个后台控制器、`SysUser` 模型、`Admin\AccountController`、`Admin\RoleController` 全部零改动**。

### 2.2 买家表名选型：推荐 `users`

| 候选 | 评价 |
|---|---|
| **`users`** ✅ | **推荐**，理由见下 |
| `user_customer` | user 与 customer 语义重复，两个词都不是必需的 |
| `customers` | 语义精准，但偏离 Laravel 默认约定，且与现有 `user_id` 外键列名不呼应 |
| `members` | 项目当前没有「会员」概念（会员等级是未落地的规划），提前占用该词会造成混淆 |
| `front_users` / `app_users` | 冗余前缀，且 front/app 的边界会随业务变化 |

**推荐 `users` + `App\Models\User` 的四条理由：**

1. **与现有外键命名天然一致。** 项目已有 10 张业务表的外键全部叫 `user_id`，且全部指向买家。买家表叫 `users` 后，`orders.user_id → users.id`、`user_addresses.user_id → users.id` **无需改任何列名，也无需额外解释**。若改叫 `customers`，则 `orders.user_id` 指向 `customers` 表，每次阅读都要在脑子里做一次映射。

2. **完全贴合 Laravel 默认约定。** `config/auth.php` 中 provider 的框架默认写法就是 `App\Models\User`；`php artisan make:model User`、Sanctum、spatie/permission 文档、各类测试脚手架默认都认 `User` / `users`。选择它意味着零配置摩擦、零解释成本，也避免后续接入第三方包时反复声明模型。

3. **与项目现有命名风格自洽。** 实测现有表名规律：`sys_` 前缀表用单数（`sys_user`、`sys_operation_log`），业务表用复数（`orders`、`products`、`cart_items`、`user_balances`）。管理员表 `sys_user` 属前者、买家表 `users` 属后者，**两者恰好各自符合所在系列的风格**，不产生违和。

4. **与项目现有语言习惯一致。** 后台的 `Admin\UserController` 管理的正是买家，其注释写明「管理对象：前台购买商品注册的买家账号」；前台 API 语境中「用户」= 买家、「账号」= 管理员。用 `users` 承载买家贴合既有语感，团队无需建立新词汇。

**副作用与应对：** 项目内「用户」一词此后明确指买家，管理员统一称「账号」。需在 `docs/design/` 与代码注释中同步这一措辞约定，避免新成员混淆。

---

## 三、拆分方案的优点

### 3.1 语义与边界

- 两类账号字段需求已分化：买家要「手机号 / 微信 openid / 会员等级 / 积分 / 实名」，管理员要「用户名 / MFA / IP 白名单」。同表继续演进会堆积大量 `nullable` 字段，表注释与校验规则互相干扰。
- `sys_user` 收敛为管理员专表后，「用户管理」与「账号管理」不再共用一张表，后台菜单与接口语义一一对应。

### 3.2 安全与合规

- 注册接口在物理上不可能污染管理员表，消除「注册提权」类风险的攻击面。
- 前后台登录可拆为两个端点，避免「管理员用户名与买家重名」时跨表查询的歧义与撞库风险。
- 可对后台登录独立施加 IP 白名单、MFA、更短的会话有效期，买家侧不受影响。
- 特权账号独立成表，符合审计对「管理员账号与业务账号分离」的要求。

### 3.3 性能（数据量大后体现）

- **当前最真实的膨胀点**：注册时执行 `assignRole('customer')`，每个买家都会在 spatie 的 `model_has_roles` 表留下**一条记录**。买家到百万级时这张表会被买家淹没，而它同时服务于后台权限判断。拆表后买家不再参与 spatie，问题根除。
- 管理员列表与权限查询不再需要从包含全部买家的表里过滤。

### 3.4 可演进性

- 买家表可自由加第三方登录、会员体系、风控字段；管理员表可加 MFA、角色委派，两者迁移互不阻塞。
- 数据合规操作（买家注销、个人信息导出/脱敏）范围收敛到一张表，不会误伤管理账号。

---

## 四、拆分方案的缺点与成本

### 4.1 后台侧成本大幅低于原方案

由于 `sys_user` 不重命名，22 个后台控制器中仅 **3～4 个**因直接操作买家数据需要调整（用户管理、地址代改、充值单管理），其余（账号管理、角色管理、商品、订单、退款等）保持原样。

### 4.2 认证逻辑可能分裂为两套

注册、登录、验证码、令牌签发、改密、重置密码、`/auth/me` 目前是**一份代码服务两端**。拆表后如果不做抽象，容易演变成两份高度相似却各自维护的代码。应抽公共 trait/service（如 `Concerns\IssuesApiTokens`），而非复制。

### 4.3 Sanctum 令牌与 guard 配置

- `personal_access_tokens.tokenable_type` 目前统一为 `App\Models\SysUser`。买家令牌记录需改写为 `App\Models\User`，否则拆表后**所有买家登录态失效**（或选择接受一次性重新登录）。
- `config/auth.php` 需要拆 provider（`users` → 买家、`admins` → 管理员）与 guard；`config/sanctum.php` 的 `guard` 列表需要同时容纳两端。
- **省事技巧**：spatie 的 guard 继续沿用 `web`，避免 `permissions` / `roles` / `model_has_roles` 三张表的 `guard_name` 数据迁移。只要 `auth.defaults.guard` 保持 `web`，`SysUser` 的 `HasRoles` 就仍按 `web` 查询。

### 4.4 数据迁移风险

需保证：
1. 买家搬迁**保持原 ID 不变**，从而规避更新 10 张业务表的 `user_id`；
2. 搬迁后修正 `users` 表自增序列，避免新注册买家与既有 ID 冲突；
3. 3 个真实外键约束需先 drop 再重建指向 `users`（PostgreSQL 下注意顺序）；
4. 迁移幂等、可回滚，前后校验各表 `user_id` 无孤儿记录。

### 4.5 与当前进度冲突

收银台已推进到 P5（支付渠道、余额、充值单），工作区尚有未提交改动，后续二期还有优惠券、物流、积分要在买家表上落地。**应先收尾并提交当前工作，再执行本方案**。

---

## 五、改动范围量化（sys_user 不动后的修正值）

### 5.1 后端（约 45～50 个文件）

| 模块 | 文件 | 性质 |
|---|---|---|
| 新增模型 | `App\Models\User`（`HasApiTokens` + `SoftDeletes`，不引入 `HasRoles`） | 新增 1 个 |
| 配置 | `config/auth.php`（双 provider + 双 guard）、`config/sanctum.php` | 改 2 个 |
| 迁移 | 新建 `users` 表、买家数据搬迁、3 个外键重建、（可选）`sys_operation_log.actor_type` | 新增 3～4 个 |
| 认证 | `AuthController`（买家走 `users`；后台登录拆到独立端点），建议抽公共 trait | 改 1 + 新增 1 |
| 买家侧控制器 | Order / Cart / Address / Favorite / Notification / Review / Balance / Payment / Profile / Product(Storefront) / Attribute(Storefront) 等 13 个 | 改 |
| 管理侧控制器 | `Admin\UserController`、`Admin\AddressController`、`Admin\BalanceRechargeController`（`Admin\OrderLogController` 视实现） | 改 3～4 个 |
| 买家相关模型关系 | `Order`、`CartItem`、`UserAddress`、`Payment`、`Review`、`UserBalance`、`UserBalanceLog`、`BalanceRecharge` | 改 8 个 |
| 服务 | `Notification\NotificationService`、`Payment\BalanceService`、`Payment\BalanceRechargeService`、`Report\ReportService`、`Common\NoGeneratorService`、`Common\OperationLogService` | 改 6 个 |
| 中间件/Job | `EnsureAccountActive`、`Jobs\SendNotificationMail` | 改 2 个 |
| 测试 | 18 个（SysUser 工厂、token 生成、角色断言） | 改 |
| **零改动** | `SysUser`、`SysOperationLog`、`Admin\AccountController`、`Admin\RoleController`、其余后台模块 | — |

### 5.2 前端

| 端 | 文件 | 说明 |
|---|---|---|
| web | **0 个** | 买家接口路径不变，无需改动 |
| admin | 4 个（`api/auth.ts`、`api/request.ts`、`stores/auth.ts`、`router/index.ts`） | 仅当后台登录拆为独立端点时需要 |

前端测试：web 9 个不受影响；admin 8 个中涉及登录 mock 的部分需同步。

### 5.3 规模小结

| 方案 | 后端文件 | 前端文件 | 风险 |
|---|---|---|---|
| 保持 sys_user 不动的拆分 | 约 45～50 | 0～4 | 中，可控 |
| 原方案（含 sys_user 重命名为 sys_admin） | 约 50～60 | 0～4 | 中高，额外承担 22 个后台控制器改名 |

---

## 六、推荐执行方案（4 个阶段，各自可验证与回滚）

### 阶段 0：前置收尾（与拆表无关）

提交当前未完成的余额充值工作，确保工作区干净、测试全绿。这是避免重构与并行开发互相阻塞的前提。

### 阶段 1：建表与数据搬迁（代码不动）

1. 迁移：新建 `users` 表（结构对齐买家实际需要，去掉管理侧专用字段）。
2. 迁移：把 `sys_user` 中持有 `customer` 角色的记录**按原 ID** 复制到 `users`，幂等可重跑。
3. 修正 `users` 表自增序列至当前最大 ID。
4. 校验：记录数一致、ID 一致、10 张业务表 `user_id` 无孤儿。

此阶段结束时**线上行为完全不变**（代码仍读 `sys_user`），风险最低，可先行落地并观察。

### 阶段 2：代码切换

1. 新增 `App\Models\User` 模型。
2. `config/auth.php` 拆 provider 与 guard；`config/sanctum.php` 补充 guard。
3. 买家侧 13 个控制器 + 8 个模型关系 + 6 个服务改用 `User`。
4. `Admin\UserController` 等 3～4 个后台模块切换到 `User`。
5. 买家注册 / 登录 / 改密 / 重置改走 `users`；后台登录拆为独立端点。
6. 3 个外键约束 drop 后重建指向 `users`。
7. 改写存量买家令牌的 `tokenable_type`（或明确接受一次重新登录）。
8. 全量回归：后端双库 + 前端两端 + 冒烟。

### 阶段 3：清理与收口

1. 删除 `sys_user` 中的买家记录（此时已是僵尸数据）。
2. 清理 `model_has_roles` 中的买家角色记录；注册逻辑不再 `assignRole('customer')`。
3. 评估 `customer` 角色的去留（保留仅作历史数据标识，或彻底移除）。
4. 更新 `docs/design/` 措辞约定：「用户」= 买家、「账号」= 管理员。

---

## 七、决策建议

| 事项 | 结论 |
|---|---|
| 目标形态：`sys_user`（管理员）+ `users`（买家） | **采纳** |
| `sys_user` 是否重命名为 `sys_admin` | **否**，保持不动，省下 22 个后台控制器的改动 |
| 买家表名 | **`users`**，模型 `App\Models\User` |
| 执行时机 | 阶段 0 收尾提交后，从阶段 1 起分阶段落地 |

---

## 附：影响面检查清单（执行时逐项核对）

- [ ] `config/auth.php` 双 guard 后，`current_password:sanctum` 改密校验是否仍正确
- [ ] `config/sanctum.php` 的 `guard` 列表是否覆盖后台端
- [ ] spatie `guard_name` 是否保持 `web`（保持则无需迁移权限表）
- [ ] 存量买家令牌 `tokenable_type` 的处理策略（改写或接受重新登录）
- [ ] `AppServiceProvider::Gate::before` 的超管兜底是否仍只作用于 `SysUser`
- [ ] `sys_operation_log` 的历史数据是否需要补 `actor_type`
- [ ] `ReportService` 复购率口径是否仍按买家统计
- [ ] 后台用户管理列表的「买家 / 后台账号」筛选逻辑（改为查 `users` 表）
- [ ] 3 个外键约束在 PostgreSQL 下的重建顺序
- [ ] `users` 表自增序列是否已对齐，避免新注册 ID 冲突
- [ ] 买家注销（软删除）与管理员软删除的相互隔离
- [ ] `user_balances` 等余额表在搬迁后的完整性校验
- [ ] `admin` 前端 401 跳转与 token 存储键是否需按端区分
