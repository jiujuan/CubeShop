<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 同步站内搜索（V1.2 站内搜索 S1-08）新增权限码，并补齐角色授权。
 *
 * 新增：search.manage（引擎切换、搜索开关、热搜词维护、索引重建）。
 * 存量环境不会重跑 seeder，缺少权限码会导致搜索配置页 403。
 *
 * ⚠️ operator **不授予**：切引擎会改变全站检索行为（含降级到 LIKE 引擎），
 *   与 media.manage 同体例，保持超管专属。
 *
 * 本迁移幂等：重复执行不会产生重复数据。
 */
return new class extends Migration
{
    private const NEW_PERMISSIONS = [
        'search.manage',
    ];

    /** 运营可获得的权限（当前为空：搜索配置保持超管专属） */
    private const OPERATOR_PERMISSIONS = [];

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
