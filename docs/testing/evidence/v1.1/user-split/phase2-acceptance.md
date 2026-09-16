# 用户表拆分 · 阶段 2 验收报告（代码切换到 users）

- 日期：2026-09-16
- 方案文档：`docs/design/CubeShop_UserTable_Split_Analysis.md`
- 阶段目标：应用代码从 `sys_user` 统管切换到「买家 `users` / 管理员 `sys_user`」双表模型

---

## 一、交付物

### 1.1 新增

| 类型 | 文件 |
|---|---|
| 模型 | `app/Models/User.php`（买家；`HasApiTokens` + `SoftDeletes`，**不含** `HasRoles`） |
| 迁移 | `2026_09_16_000024_repoint_user_foreign_keys_to_users_table.php`（3 个外键重指向） |
| 迁移 | `2026_09_16_000025_add_actor_type_to_sys_operation_log_table.php`（操作日志身份归属） |
| 迁移 | `2026_09_16_000026_migrate_buyer_tokens_to_user_model.php`（存量买家令牌改写） |
| 迁移 | `2026_09_16_000027_add_receiver_type_to_notifications_table.php`（通知收件人身份归属） |
| 测试 | `tests/Feature/ProfileApiTest.php`（5 例） |

### 1.2 修改（后端）

| 模块 | 文件 | 变更 |
|---|---|---|
| 配置 | `config/auth.php` | 双 provider（`users`→User / `admins`→SysUser）+ 双 guard（`web`/`customer`） |
| 认证 | `AuthController` | 注册写 `users` 且不再 `assignRole`；登录按「管理员优先、买家兜底」分流；`formatUser` 兼容双模型；注册禁止占用管理员标识 |
| 模型 | `Order` / `CartItem` / `UserAddress` / `Payment` / `Review` / `UserBalance` / `UserBalanceLog` / `BalanceRecharge` | `user()` 关系改指向 `User` |
| 模型 | `SysOperationLog` | 新增 `actor_type` + `admin()` / `customer()` 关系 + `scopeActor` |
| 模型 | `Notification` | 新增 `receiver_type` 常量与 `scopeReceiver` |
| 服务 | `OperationLogService` | `record()` 新增 `$actorType` 参数 |
| 服务 | `NotificationService` | `send()`/`unreadCount()`/`markRead()` 增加 `receiverType`；`sendToRole` 标记为 admin |
| 服务 | `ReportService` | 用户增长统计改查 `users`，去掉 `customer` 角色过滤 |
| 后台 | `Admin\UserController` | **改为买家专表**，移除 `role=customer\|admin\|all` 混合视图与超管保护分支 |
| 后台 | `Admin\AddressController` | 代改地址的对象改查 `users` |
| 后台 | `Admin\OrderLogController` | 按 `operator_type` 分流解析操作人（user→users / admin→sys_user） |
| 后台 | `Admin\OperationLogController` | 按 `actor_type` 解析操作人，新增 `actor_type` 筛选 |
| 后台 | `Admin\BalanceRechargeController` | 移除未使用的 `SysUser` 引用 |
| 中间件 | `EnsureAccountActive` | 去掉 `instanceof SysUser` 限定，同时覆盖买家与管理员 |
| 队列 | `Jobs\SendNotificationMail` | 按 `receiverType` 选择账号表 |
| 工厂 | `database/factories/UserFactory.php` | 按 `users` 表结构重写 |

### 1.3 修改（前端 admin）

| 文件 | 变更 |
|---|---|
| `admin/src/views/user/UserListView.vue` | 移除角色筛选下拉（买家/后台账号/全部角色），用户管理仅呈现买家 |
| `admin/src/api/user.ts` | `UserQuery` 移除 `role` 参数 |

### 1.4 修改（测试）

- `tests/Helpers.php`：`createTestUser()` 改为创建 `App\Models\User`
- 12 个测试文件的买家引用由 `SysUser` 改为 `User`（`OrderLogAdminApiTest` / `PaymentAdminApiTest` / `PaymentReliabilityTest` / `RechargeApiTest` / `UserAddressAdminTest` / `ReportApiTest` / `AuthApiTest` / `PasswordChangeTest` / `CashierApiTest` / `PaymentServiceCloseTest` / `ReviewServiceTest` / `UserApiTest`）
- `UserApiTest` 重写 6 例并新增 2 例（注册守卫、登录分流）
- `AccountRoleApiTest`：角色引用保护改用 `SysUser`；买家列表断言适配
- `NotificationApiTest` 新增 1 例（运营通知与买家撞号隔离）

---

## 二、关键实现决策与偏差说明

| 项 | 决策 | 原因 |
|---|---|---|
| **后台登录端点** | **保留单一 `/auth/login`**，管理员优先、买家兜底；注册时禁止占用管理员的用户名/手机号/邮箱 | 方案文档原建议「后台登录拆独立端点」，但两端前端均调用同一端点。管理员优先探测已消除遮蔽风险，配合注册守卫即可避免歧义，**可省下 admin 前端 4 个文件与 8 个前端测试的改动**，风险更低。若后续需要独立端点，改动面仍很小 |
| **`Admin\UserController` 语义** | **只服务买家**，下线 `role=admin|all` | 两张表 ID 各自从 1 开始，混列会导致列表 key 重复、`PUT /admin/users/{id}` 歧义。后台管理员已由「账号管理」（`Admin\AccountController`）负责 |
| **`defaults.guard`** | 保持 `web` 不变 | spatie/permission 以它为默认 `guard_name`；一旦改动，`roles`/`permissions`/`model_has_roles` 三张表都要做 `guard_name` 数据迁移 |
| **`config/sanctum.php`** | **未改动**（`guard => ['web']` 保持） | 项目接口统一走 `auth:sanctum` Bearer Token，Sanctum 通过 `tokenable_type` 直接解析模型，**不依赖 guard 的 provider**；该配置仅影响 SPA 会话式认证，本项目未使用 |
| **`sys_operation_log`** | 新增 `actor_type`（admin/customer） | `user_id` 是混合语义列；拆分后两表 ID 会撞号，不区分会造成后台审计归属错误。历史数据按「ID 出现在 `users` 表」回填为 customer |
| **`notifications`** | **额外**新增 `receiver_type`（customer/admin） | 与 `sys_operation_log` 同一类隐患：库存预警（`sendToRole('operator')`）把管理员 ID 写进了买家读取的通知表，撞号后买家会读到发给运营的预警。属方案外补充，一并修复 |
| **存量令牌** | 批量改写 `tokenable_type` 为 `App\Models\User` | 买家 ID 保持不变，改写后**存量买家登录态不失效**（开发库 43 条买家令牌成功改写，28 条管理员令牌未受影响） |
| **外键重建** | `dropForeign(['user_id'])` 后重加 | SQLite 不支持按约束名删外键，必须传列名数组；Laravel 13 对 SQLite 通过表重建实现，已实测子表（order_items/payments/refunds/order_logs）外键不受影响 |

---

## 三、开发库（PostgreSQL `cubeshop`）实测

### 3.1 外键指向

| 表 | 指向 |
|---|---|
| orders.user_id | users ✅ |
| cart_items.user_id | users ✅ |
| user_addresses.user_id | users ✅ |

（全库已无指向 `sys_user` 的业务外键）

### 3.2 数据一致性

| 校验项 | 结果 |
|---|---|
| 3 张外键表孤儿记录 | 0 / 0 / 0 ✅ |
| `actor_type` 回填分布 | customer 71 / admin 103 ✅ |
| 买家令牌改写 | 43 条改为 `App\Models\User`，0 条漏改，0 条误改 ✅ |
| 存量 SysUser 令牌归属 | 仅 admin(1)、operator(2) ✅ |
| `receiver_type` 回填分布 | customer 56 / admin 0（当前无库存预警记录）✅ |

### 3.3 端到端 HTTP 实测

| 场景 | 结果 |
|---|---|
| 管理员登录 | 200，`roles=['super_admin']` |
| 管理员访问 `/admin/reports/trend`（原 403 缺陷） | **200** ✅ |
| `/admin/accounts` 列表 | 仅 `operator`、`admin`（后台账号 2 条）✅ |
| `/admin/users` 列表 | 36 条**全部为买家**，无后台账号 ✅ |
| 买家注册 | 200，`roles=[]`；落库到 `users`，**未写入 `sys_user`** ✅ |
| 买家 `/user/profile` 读取 | 200，`roles=[]`、`permissions=[]` ✅ |
| 买家 `/user/profile` 更新 | 200，昵称更新成功 ✅ |
| 买家 `/orders`、`/me/notifications/unread-count` | 200 ✅ |
| 占用管理员用户名注册 | **40000 拒绝** ✅ |

---

## 四、回归测试

| 套件 | 变更前 | 变更后 |
|---|---|---|
| 后端 SQLite（`php artisan test`） | 421 | **429 passed**（1413 assertions） |
| 后端 PostgreSQL（`-c phpunit.pgsql.xml`） | 421 | **429 passed**（1413 assertions） |
| 前端 web（Vitest） | 83 | **83 passed** |
| 前端 admin（Vitest） | 59 | **59 passed** |
| admin 类型检查（`vue-tsc --noEmit`） | 0 错 | **0 错** |
| 冒烟脚本（`docs/testing/smoke_test.sh`） | 32 | **PASS 32 / FAIL 0** |

新增 8 例测试：`ProfileApiTest` 5 例（资料读写 / 唯一性按 users 表 / 操作日志身份）+ `UserApiTest` 2 例（注册守卫 / 登录分流）+ `NotificationApiTest` 1 例（撞号隔离）。

---

## 五、遗留与后续

1. **`sys_user` 中的买家僵尸数据**（36 条）与 `model_has_roles` 中的 36 条 `customer` 角色记录尚未清理 —— 属**阶段 3**。
   两表 ID 当前存在交集（3~38），阶段 3 清理后 `sys_user` 将只保留管理员。
2. **`customer` 角色**目前仍定义在 `RolePermissionSeeder` 中，但已无账号使用（注册不再分配），阶段 3 决定去留。
3. `Admin\UserController` 的 `role` 参数已不再接受，前端已同步；若有外部调用方需一并告知。

---

## 六、结论

阶段 2 完成：买家与后台管理员账号体系已物理隔离，外键、令牌、审计日志、通知归属全部对齐；双库回归 429/429、前端 142 例、冒烟 32/32 全绿，开发库端到端实测通过。**具备进入阶段 3（清理与收口）的条件。**
