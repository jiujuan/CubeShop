# 用户表拆分 · 阶段 1 验收报告（建表与数据搬迁）

- 日期：2026-09-16
- 方案文档：`docs/design/CubeShop_UserTable_Split_Analysis.md`
- 阶段目标：新建 `users` 表并把存量买家按**原 ID** 搬迁，**应用代码完全不动**，线上行为不变

---

## 一、交付物

| 类型 | 文件 | 说明 |
|---|---|---|
| 迁移 | `database/migrations/2026_09_16_000022_create_users_table.php` | 新建买家表 `users` |
| 迁移 | `database/migrations/2026_09_16_000023_migrate_customers_to_users_table.php` | 买家按原 ID 搬迁 + 幂等 + 序列对齐 + 可回滚 |
| 测试 | `tests/Feature/UserTableSplitTest.php` | 5 例集成测试（TC-SPLIT-001~005） |

### `users` 表结构

| 列 | 类型 | 说明 |
|---|---|---|
| id | bigIncrements | 与 `sys_user` 原 ID 保持一致 |
| username | string(64) unique | 登录名 |
| email | string(128) nullable unique | 邮箱 |
| phone | string(20) nullable unique | 手机号 |
| password | string(255) | 密码哈希 |
| nickname | string(64) nullable | 昵称 |
| avatar | string(512) nullable | 头像 URL |
| status | smallInteger default 1 | 1=正常 0=禁用 |
| last_login_at / last_login_ip | timestamp / string(45) | 登录痕迹 |
| created_at / updated_at / deleted_at | — | 时间戳 + 软删除 |

> 说明：`sys_user` 当前尚无管理侧专属字段（MFA / IP 白名单均为规划项），故买家表结构与其对齐，便于按原 ID 直接搬迁；后续买家侧扩展（第三方登录、会员等级）可在本表独立演进。

### 搬迁判据

持有 spatie `customer` 角色的 `SysUser` 记录（`model_has_roles.model_type = 'App\Models\SysUser'`）。V1.1 之前注册接口执行 `assignRole('customer')`，该判据完整覆盖存量买家。

---

## 二、开发库（PostgreSQL `cubeshop`）验收结果

### 2.1 数量与一致性

| 校验项 | 期望 | 实测 | 结论 |
|---|---|---|---|
| `sys_user` 买家数 | — | 36 | — |
| `users` 记录数 | 等于买家数 | 36 | ✅ |
| ID 集合一致 | YES | YES | ✅ |
| ID 区间 | 保持原值 | 3 ~ 38 | ✅ |
| 逐字段比对差异数 | 0 | 0 | ✅ |

### 2.2 孤儿记录检查（业务表 `user_id` 必须存在于 `users`）

| 表 | 总记录 | 孤儿 | 结论 |
|---|---|---|---|
| orders | 26 | 0 | ✅ |
| cart_items | 12 | 0 | ✅ |
| user_addresses | 32 | 0 | ✅ |
| payments | 14 | 0 | ✅ |
| refunds | 25 | 0 | ✅ |
| reviews | 9 | 0 | ✅ |
| notifications | 51 | 0 | ✅ |
| favorites | 9 | 0 | ✅ |
| browse_histories | 13 | 0 | ✅ |
| user_balances | 2 | 0 | ✅ |
| user_balance_logs | 1 | 0 | ✅ |
| balance_recharges | 1 | 0 | ✅ |

### 2.3 自增序列

| 项 | 实测 |
|---|---|
| `users` max(id) | 37 |
| PG 序列 `users_id_seq.last_value` | 37 (`is_called = true`) |
| 下一个自增 ID | 38（不与存量冲突） |

### 2.4 幂等与可回滚

| 场景 | 操作 | 结果 |
|---|---|---|
| 幂等 | 重复执行 `up()` 3 次 | 记录数恒为 35（无重复行）✅ |
| 回滚 | `migrate:rollback --step=1` | `users` 归零，`sys_user` 买家数据未受影响 ✅ |
| 重迁 | 再次 `migrate` | 记录数恢复 35 ✅ |

---

## 三、回归测试

| 套件 | 结果 |
|---|---|
| 后端 SQLite（`php artisan test`） | **421 passed**（1381 assertions） |
| 后端 PostgreSQL（`-c phpunit.pgsql.xml`） | **421 passed**（1381 assertions） |
| 前端 web（Vitest） | **83 passed**（10 files） |
| 前端 admin（Vitest） | **59 passed** |
| 冒烟脚本（`docs/testing/smoke_test.sh`） | **PASS 32 / FAIL 0** |

> 变更前基线：后端 416 / web 83 / admin 59。新增 5 例集成测试后为 421，无回归。
> 冒烟脚本涵盖下单→支付→发货→收货→评价→收藏足迹→通知→改密→头像→余额充值全链路，阶段 1 后行为与拆分前一致。

---

## 四、重要运维提示

1. **阶段 1 期间新注册的买家仍写入 `sys_user`**（代码未切换）。
   冒烟测试即产生了 1 名这样的买家（id=38）。搬迁迁移**幂等**，在阶段 2 代码切换前重跑一次即可补齐：

   ```bash
   php artisan tinker --execute="(require database_path('migrations/2026_09_16_000023_migrate_customers_to_users_table.php'))->up();"
   ```

   或直接 `php artisan migrate:rollback --step=1 && php artisan migrate`。

2. **全新环境无影响**：迁移先于 seeder 执行，此时无 `customer` 角色、无买家，搬迁自动跳过；表结构正常创建。

3. **回滚方式**：`php artisan migrate:rollback --step=2` 可同时撤销搬迁与建表；`down()` 仅删除与 `sys_user` 买家重复的记录，不会误删阶段 2 之后新注册的用户。

---

## 五、结论

阶段 1 完成，**线上行为完全不变**（代码仍读写 `sys_user`），搬迁数据完整、ID 保持、无孤儿、序列对齐、幂等可回滚。已具备进入**阶段 2（代码切换）**的条件。

进入阶段 2 前须执行：**重跑搬迁迁移补齐增量买家** + 确认工作区干净（阶段 1 已提交）。
