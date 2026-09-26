<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 同步库存盘点新增权限码，并补齐角色授权。
 *
 * 新增：inventory.check（盘点单建单 / 实盘录入 / 导入导出 / 作废）
 *
 * ⚠️ 过账**不在此列**：过账会直接改写库存，复用已有的 inventory.manage，
 *    与「手工调整库存」同一授权口径。即盘点员可以录数、但调账需要库存管理权——
 *    两档权限是有意设计的职责分离，不是遗漏。
 *
 * 存量环境不会重跑 seeder，缺少权限码会导致盘点页 403。
 *
 * 本迁移幂等：重复执行不会产生重复数据。
 */
return new class extends Migration
{
    private const NEW_PERMISSIONS = [
        'inventory.check',
    ];

    /** 运营可获得的权限（盘点日常由运营执行） */
    private const OPERATOR_PERMISSIONS = [
        'inventory.check',
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

        if (self::OPERATOR_PERMISSIONS !== []) {
            Role::findOrCreate('operator', 'web')
                ->givePermissionTo(self::OPERATOR_PERMISSIONS);
        }

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
