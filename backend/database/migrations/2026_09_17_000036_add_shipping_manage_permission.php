<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * T-047（E03）：新增权限码 `shipping.manage`（物流管理：快递公司字典维护与异常看板）
 *
 * 背景同 marketing.manage：已存在的环境不会重跑 seeder，必须以迁移同步。
 * 本迁移幂等，可重复执行。
 */
return new class extends Migration
{
    private const NEW_PERMISSION = 'shipping.manage';

    /** 运营角色应获得的权限（只增不减，与 RolePermissionSeeder 保持一致） */
    private const OPERATOR_GRANTS = ['shipping.manage'];

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

        // 运营：授予物流管理
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
