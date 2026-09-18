<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 同步「公告管理」新增权限码 announcement.manage，并补齐角色授权。
 *
 * 背景：announcement.manage 新增在 RolePermissionSeeder 中，存量环境（开发库 pgsql）
 * 不会重跑 seeder，会导致后台公告管理页面 403。
 *
 * 本迁移幂等：重复执行不会产生重复数据。
 */
return new class extends Migration
{
    /** 本次新增的权限码（用于 down 时精确回收） */
    private const NEW_PERMISSIONS = [
        'announcement.manage',
    ];

    /**
     * 运营角色的权限范围（与 RolePermissionSeeder 保持一致，只增不减）
     */
    private const OPERATOR_PERMISSIONS = [
        'product.view', 'product.create', 'product.update', 'category.manage',
        'order.view', 'order.ship', 'order.export',
        'shipping.manage',
        'refund.view', 'refund.process',
        'dashboard.view',
        'address.view',
        'review.manage', 'report.view', 'inventory.manage',
        'marketing.manage',
        'payment.view', 'order.log',
        'payment.offline.review', 'balance.recharge.view',
        // 公告管理（P-Announcement）
        'announcement.manage',
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

        // 运营：补齐公告管理权限
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
