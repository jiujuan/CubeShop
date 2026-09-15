<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 同步 V1.1 新增权限码，并补齐角色授权。
 *
 * 背景：V1.1 一期在 RolePermissionSeeder 中新增了 review.manage / report.view /
 * account.manage / role.manage / inventory.manage 五个权限码，但已存在的环境
 * （如开发库 pgsql）不会重跑 seeder，导致超级管理员实际并未持有 report.view，
 * 访问 /api/admin/reports/* 返回 403。测试库因每次 migrate:fresh --seed 重建
 * 而未暴露该问题。
 *
 * 本迁移幂等：重复执行不会产生重复数据。
 */
return new class extends Migration
{
    /**
     * V1.1 新增的权限码（仅用于可读性标注，实际以 RolePermissionSeeder 为准）
     */
    private const V11_PERMISSIONS = [
        'review.manage',
        'report.view',
        'account.manage',
        'role.manage',
        'inventory.manage',
    ];

    /**
     * 运营角色的权限范围（与 RolePermissionSeeder 保持一致，只增不减）
     */
    private const OPERATOR_PERMISSIONS = [
        'product.view', 'product.create', 'product.update', 'category.manage',
        'order.view', 'order.ship', 'order.export',
        'refund.view', 'refund.process',
        'dashboard.view',
        'address.view',
        'review.manage', 'report.view', 'inventory.manage',
    ];

    public function up(): void
    {
        // 角色/权限表可能尚未创建（全新库首次迁移），此时交由 seeder 处理
        if (! $this->permissionTablesExist()) {
            return;
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (RolePermissionSeeder::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // 超级管理员：始终持有全部权限码（全量对齐，防止后续新增权限再次遗漏）
        Role::findOrCreate('super_admin', 'web')
            ->syncPermissions(RolePermissionSeeder::PERMISSIONS);

        // 运营：补齐设计范围内的权限（不移除已额外授予的权限）
        Role::findOrCreate('operator', 'web')
            ->givePermissionTo(self::OPERATOR_PERMISSIONS);

        Role::findOrCreate('customer', 'web'); // 买家，无后台权限

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! $this->permissionTablesExist()) {
            return;
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 仅回收本次新增的权限码，保留 V1.0 既有授权
        $existing = Permission::whereIn('name', self::V11_PERMISSIONS)
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

    /**
     * 判断 spatie 权限相关数据表是否已存在
     */
    private function permissionTablesExist(): bool
    {
        try {
            return Permission::query()->count() >= 0;
        } catch (\Throwable) {
            return false;
        }
    }
};
