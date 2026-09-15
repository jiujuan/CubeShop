# 缺陷修复报告：超级管理员访问报表接口 403

- 日期：2026-09-16
- 现象：`GET /api/admin/reports/trend?days=30` 返回 `403 Forbidden`，响应码 `40003 无权限执行此操作`
- 影响范围：全部 `report.view` 保护接口（overview / trend / top-products / category-share / users / export）
- 等级：P0（超管不可用）

## 根因

V1.1 一期在 `RolePermissionSeeder::PERMISSIONS` 中新增了 5 个权限码：

```
review.manage / report.view / account.manage / role.manage / inventory.manage
```

但 seeder 只在**建库后跑一次**。开发库（pgsql `cubeshop`）在一期之前已存在，
新增的权限码从未写入 `permissions` 表，超级管理员角色仍只持有旧的 15 个权限，
因此 `permission:report.view` 中间件判定无权限。

测试未暴露该问题的原因：测试库使用 SQLite `:memory:` + `RefreshDatabase`，
每次 `migrate:fresh --seed` 都会重建权限，始终是最新定义，与存量环境不一致。

修复前实测：

```
permissions count: 15
has report.view: NO
super_admin perms: 15
```

## 修复方案（两层）

### 1. 数据同步（存量环境修复）

新增迁移 `2026_09_16_000014_sync_v1_1_permissions.php`：

- 幂等创建 `RolePermissionSeeder::PERMISSIONS` 全量权限码
- `super_admin` 使用 `syncPermissions()` 全量对齐
- `operator` 使用 `givePermissionTo()` 只补齐不回收（避免抹掉手工额外授权）
- 迁移内置 `permissionTablesExist()` 保护，全新库首次迁移（权限表未建）时安全跳过，交由 seeder 处理
- `down()` 仅回收 V1.1 新增的 5 个权限码，且先过滤已存在的记录，避免 `PermissionDoesNotExist`

### 2. 超管兜底（防复发）

`AppServiceProvider::boot()` 增加 `Gate::before`：

```php
Gate::before(function ($user) {
    return $user instanceof SysUser && $user->hasRole('super_admin') ? true : null;
});
```

超级管理员在角色定义上即拥有全部权限，此后新增权限码即使遗漏同步，超管也不会被误判。
仅对 `SysUser` 生效（项目内唯一使用 `HasRoles` 的模型），不影响前台买家账号。

## 验证

### 数据层

```
permissions count: 20
has report.view: YES
admin roles: super_admin
report.view => YES   review.manage => YES   account.manage => YES
role.manage => YES   inventory.manage => YES
```

### 接口层（真实 Token，后端 8000）

| 接口 | admin | operator |
|---|---|---|
| `/api/admin/reports/overview` | 200 | 200 |
| `/api/admin/reports/trend?days=30` | 200 | 200 |
| `/api/admin/reports/top-products` | 200 | 200 |
| `/api/admin/reports/category-share` | 200 | 200 |
| `/api/admin/reports/users` | 200 | 200 |
| `/api/admin/reviews` | 200 | 200 |
| `/api/admin/accounts` | 200 | **403** |
| `/api/admin/roles` | 200 | **403** |

权限边界未被过度放宽：账号/角色管理仍为超管专属。

### 回归测试

新增 `tests/Feature/PermissionSyncTest.php`（4 用例）：

- TC-PERM-001 seeder 声明的每个权限码都已落库
- TC-PERM-002 超级管理员持有全部权限码
- TC-PERM-003 超管可访问 V1.1 全部报表与运营接口
- TC-PERM-004 运营不具备超管专属权限（accounts / roles 仍 403）

结果：

| 套件 | 结果 |
|---|---|
| 后端 SQLite | 319 passed / 989 assertions |
| 后端 PostgreSQL | 319 passed / 989 assertions |

## 部署注意

存量环境升级时需执行：

```bash
php artisan migrate          # 触发 000014 权限同步
php artisan permission:cache-reset   # 如启用了 spatie 权限缓存
```

后续新增权限码时，建议同时补充到迁移或运行 `php artisan db:seed --class=RolePermissionSeeder`。
