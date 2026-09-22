<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 同步媒体库（图片资产治理 P2）新增权限码，并补齐角色授权。
 *
 * 新增：media.view（浏览/复用）、media.upload（上传）、media.manage（替换/删除）。
 * 存量环境不会重跑 seeder，缺少权限码会导致媒体库页与选图器 403。
 *
 * ⚠️ operator 只**补授** media.view / media.upload：
 *    - `givePermissionTo` 只增不减，不会覆盖后续迁移补进去的其它权限；
 *    - media.manage 涉及替换文件与软删（影响所有引用方），保持超管专属。
 *
 * 本迁移幂等：重复执行不会产生重复数据。
 */
return new class extends Migration
{
    private const NEW_PERMISSIONS = [
        'media.view',
        'media.upload',
        'media.manage',
    ];

    /** 运营可获得的权限（不含 media.manage） */
    private const OPERATOR_PERMISSIONS = [
        'media.view',
        'media.upload',
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
            ->givePermissionTo(self::OPERATOR_PERMISSIONS);

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
