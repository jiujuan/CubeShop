<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CS-103（客户服务中心一期）：新增客服中心权限码
 *
 * cs.ticket.view    查看工单（工作台列表/详情）
 * cs.ticket.handle  处理工单（回复/内部备注/状态变更/转交/优先级）
 * cs.faq.manage     帮助中心维护（分类与文章 CRUD、发布下架）
 *
 * 背景同 shipping.manage：已存在的环境不会重跑 seeder，必须以迁移同步。本迁移幂等。
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const NEW_PERMISSIONS = [
        'cs.ticket.view',
        'cs.ticket.handle',
        'cs.faq.manage',
    ];

    public function up(): void
    {
        if (! $this->permissionTablesExist()) {
            return;
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::NEW_PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // 超级管理员：持有全部权限码
        Role::findOrCreate('super_admin', 'web')->givePermissionTo(self::NEW_PERMISSIONS);

        // 运营/客服：授予客服中心全部权限（一期只做「能否进入模块」，数据范围隔离在二期 CS-212）
        Role::findOrCreate('operator', 'web')->givePermissionTo(self::NEW_PERMISSIONS);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! $this->permissionTablesExist()) {
            return;
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::where('name', 'super_admin')->first()?->revokePermissionTo(self::NEW_PERMISSIONS);
        Role::where('name', 'operator')->first()?->revokePermissionTo(self::NEW_PERMISSIONS);

        Permission::whereIn('name', self::NEW_PERMISSIONS)->where('guard_name', 'web')->delete();

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
