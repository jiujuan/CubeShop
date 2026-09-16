<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * T-032（F06）：新增权限码 `marketing.manage`（营销管理：优惠券与满减活动）
 *
 * 背景：权限码在 `RolePermissionSeeder` 中新增，但已存在的环境（如开发库 pgsql）
 * 不会重跑 seeder，故必须以迁移同步，否则超管/运营实际拿不到该权限（接口 403）。
 * 本迁移幂等，可重复执行。
 */
return new class extends Migration
{
    private const NEW_PERMISSION = 'marketing.manage';

    /** 运营角色应获得的权限（只增不减，与 RolePermissionSeeder 保持一致） */
    private const OPERATOR_GRANTS = ['marketing.manage'];

    public function up(): void
    {
        if (! $this->permissionTablesExist()) {
            return;
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::findOrCreate(self::NEW_PERMISSION, 'web');

        // 超级管理员：持有全部权限码（全量对齐，防后续新增再遗漏）
        Role::findOrCreate('super_admin', 'web')
            ->givePermissionTo(self::NEW_PERMISSION);

        // 运营：授予营销管理
        Role::findOrCreate('operator', 'web')
            ->givePermissionTo(self::OPERATOR_GRANTS);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! $this->permissionTablesExist()) {
            return;
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::where('name', 'super_admin')->first()?->revokePermissionTo(self::NEW_PERMISSION);
        Role::where('name', 'operator')->first()?->revokePermissionTo(self::OPERATOR_GRANTS);
        Permission::where('name', self::NEW_PERMISSION)->where('guard_name', 'web')->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function permissionTablesExist(): bool
    {
        try {
            return Permission::query()->count() >= 0;
        } catch (\Throwable) {
            return false;
        }
    }
};
