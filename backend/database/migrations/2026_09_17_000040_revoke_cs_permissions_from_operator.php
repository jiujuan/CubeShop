<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CS-103 修正：回收误授予 operator 的客服中心权限
 *
 * 背景：2026_09_17_000039 给 operator 授予了 cs.ticket.view / cs.ticket.handle / cs.faq.manage，
 * 但 RolePermissionSeeder 从未授予（运营与客服为两条职责线，一期客服由超管兜底）。
 * 后果是同一角色在两种环境下权限不同：
 *   - 存量库升级（跑过 000039）→ operator 能进后台客服工作台；
 *   - 全新安装（只跑 Seeder）→ operator 没有权限，但新工单通知仍发给它 → 「有通知打不开」（403）。
 *
 * 本迁移把两侧统一为「operator 不持有 cs.*」，与 Seeder 对齐。幂等，可重复执行。
 *
 * 注：新工单通知已改为按权限（cs.ticket.view）投递，不再写死角色名，
 *     故回收后通知由超管接收；二期新增客服角色时授予该权限即自动覆盖。
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const CS_PERMISSIONS = [
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

        $permissions = Permission::query()
            ->whereIn('name', self::CS_PERMISSIONS)
            ->where('guard_name', 'web')
            ->get();

        if ($permissions->isNotEmpty()) {
            foreach (Role::query()->where('name', 'operator')->where('guard_name', 'web')->get() as $role) {
                $role->revokePermissionTo($permissions);
            }
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        // 不提供反向授权：000039 对 operator 的授权本身即为误操作，
        // 回滚本迁移不应把错误状态再恢复回来。
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
