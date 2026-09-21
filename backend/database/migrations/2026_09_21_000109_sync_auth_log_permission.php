<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 同步「认证日志查看」新增权限码 log.auth.view，并补齐角色授权。
 *
 * 背景：log.auth.view 新增在 RolePermissionSeeder 中，存量环境（开发库 pgsql）
 * 不会重跑 seeder，会导致后台认证日志页面 403。
 *
 * ⚠️ operator 这里只**补授** log.auth.view（不列全量）：
 *    `givePermissionTo` 只增不减，故不会覆盖掉后续迁移补进去的其它权限。
 *
 * 本迁移幂等：重复执行不会产生重复数据。
 */
return new class extends Migration
{
    private const NEW_PERMISSIONS = [
        'log.auth.view',
    ];

    public function up(): void
    {
        if (! $this->permissionTablesExist()) {
            return;
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (RolePermissionSeeder::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // 超级管理员：全量对齐（防止后续新增权限再次遗漏）
        Role::findOrCreate('super_admin', 'web')
            ->syncPermissions(RolePermissionSeeder::PERMISSIONS);

        Role::findOrCreate('operator', 'web')
            ->givePermissionTo(self::NEW_PERMISSIONS);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! $this->permissionTablesExist()) {
            return;
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $existing = Permission::whereIn('name', self::NEW_PERMISSIONS)
            ->where('guard_name', 'web')
            ->pluck('name')
            ->all();

        if ($existing !== []) {
            Role::where('name', 'super_admin')->first()?->revokePermissionTo($existing);
            Role::where('name', 'operator')->first()?->revokePermissionTo($existing);
            Permission::whereIn('name', $existing)->where('guard_name', 'web')->delete();
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /** 判断 spatie 权限相关数据表是否已存在 */
    private function permissionTablesExist(): bool
    {
        try {
            return Permission::query()->count() >= 0;
        } catch (\Throwable) {
            return false;
        }
    }
};
