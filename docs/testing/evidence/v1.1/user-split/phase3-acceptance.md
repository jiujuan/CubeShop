# 用户表拆分 · 阶段 3 验收报告（清理与收口）

- 日期：2026-09-16
- 方案文档：`docs/design/CubeShop_UserTable_Split_Analysis.md`
- 阶段目标：清除拆分后遗留在 `sys_user` 的买家僵尸数据与已无持有者的 `customer` 角色，完成账套收口

---

## 一、交付物

### 1.1 新增

| 类型 | 文件 | 说明 |
|---|---|---|
| 迁移 | `database/migrations/2026_09_16_000028_cleanup_user_split_legacy_data.php` | 清理买家角色关联 → 删除 `sys_user` 僵尸买家 → 移除 `customer` 角色 |
| 测试 | `tests/Feature/UserSplitCleanupTest.php` | 6 例（含幂等与「用户名不一致不误删」安全网） |

### 1.2 修改

| 模块 | 文件 | 变更 |
|---|---|---|
| 种子 | `database/seeders/RolePermissionSeeder.php` | 角色清单移除 `customer`（仅保留 `super_admin` / `operator`） |
| 后台 | `app/Http/Controllers/Admin/RoleController.php` | `BUILTIN_ROLES` 与 `ROLE_LABELS` 移除 `customer` |
| 前端 admin | `admin/src/api/account.ts` | `ROLE_LABELS` 移除 `customer`（与后端对齐） |
| 测试 | `tests/Feature/AccountRoleApiTest.php` | TC-ACC-006 非法角色样本由 `customer` 改为 `not_a_role` |
| 测试 | `tests/Feature/UserTableSplitTest.php` | 阶段 1 测试自带 `legacyCustomerRole()` 构造遗留角色，不再依赖 seeder |

---

## 二、清理迁移设计（`000028`）

三步、幂等、可安全重跑：

1. **清理买家角色关联** —— 删除 `model_has_roles` 中 `model_type=SysUser` 且 `model_id ∈ users` 的非后台角色记录。
2. **删除僵尸买家** —— 仅当 `users` 与 `sys_user` **ID 与 username 同时一致**时认定为同一账号并删除（源迁移按原 ID 搬迁，故 ID 必然一致；username 作为二次安全网）。用 `join` 取候选 ID 再分批删除，规避相关子查询删除在 SQLite 下的兼容问题。
3. **移除 `customer` 角色** —— 先删其 `role_has_permissions` / `model_has_roles` 关联，再删角色本身。

安全网：
- 仍持有 `super_admin` / `operator` 的账号一律保留；
- 卖家数据始终完整保留在 `users` 表（本迁移只删重复的僵尸行）；
- `down()` 为**有意的空操作**——如需回退，可重跑阶段 1 迁移反向补种，`customer` 角色可由 seeder 重建。

---

## 三、开发库（PostgreSQL `cubeshop`）实测

迁移前 / 迁移后对比：

| 校验项 | 迁移前 | 迁移后 |
|---|---|---|
| `sys_user` 僵尸买家（与 users 同 ID+用户名） | 36 | **0** ✅ |
| `sys_user` 总数 | 38 | **2**（仅 `operator`、`admin`）✅ |
| `users` 总数 | 38 | **38**（未丢失）✅ |
| `customer` 角色 | 存在 | **0** ✅ |
| `super_admin` / `operator` 角色 | 2 | **2**（保留）✅ |
| 买家角色关联残留 | 36 | **0** ✅ |

冒烟测试后复测：`users` 39（新增 1 位冒烟注册买家）、`sys_user` **恒为 2**、僵尸 0、`customer` 0、`orders`/`cart_items`/`user_addresses`/`user_balances` 外键孤儿 **0/0/0/0**。

---

## 四、回归测试

| 套件 | 阶段 2 | 阶段 3 |
|---|---|---|
| 后端 SQLite（`php artisan test`） | 429 | **435 passed**（1431 assertions） |
| 后端 PostgreSQL（`-c phpunit.pgsql.xml`） | 429 | **435 passed**（1431 assertions） |
| 前端 web（Vitest） | 83 | **83 passed** |
| 前端 admin（Vitest） | 59 | **59 passed** |
| 冒烟脚本（`docs/testing/smoke_test.sh`） | 32 | **PASS 32 / FAIL 0** |

新增 6 例：`UserSplitCleanupTest`（僵尸买家删除 / 角色关联清理 / `customer` 角色移除 / 管理员不受影响 / 幂等 / 用户名不一致不误删）。

> 调试记录：初次实现用「相关子查询删除」，SQLite 下未删除任何行；改为 `join` 取 ID 再删。另发现测试辅助 `User::create(['id' => ...])` 因 `id` 不在 `$fillable` 被静默丢弃，导致两表 ID 对不上——改用 `forceCreate` 显式保留原 ID 后全部通过。

---

## 五、术语与措辞收口

- 「用户」= 买家（`users` / `App\Models\User`）；「账号」= 后台管理员（`sys_user` / `App\Models\SysUser`），已在方案文档《七·术语约定》与 V1.1 总览中固化。
- 买家身份识别方式为「无后台角色」（不再有 `customer` 角色）。

---

## 六、结论

阶段 3 完成：遗留僵尸数据与 `customer` 角色已彻底清除，`sys_user` 仅保留后台管理员账号，账套物理隔离收口。双库回归 435/435、前端 142 例、冒烟 32/32 全绿。**用户表拆分三阶段（拆分 → 代码切换 → 清理收口）全部交付。**
