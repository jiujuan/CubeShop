<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CS-117 缺陷 #4：建立独立「客服」角色（cs_agent）—— 存量库升级路径
 *
 * 背景：一期客服由超管兜底（operator 明确不持有 cs.*，见 000040），
 * 但「缺独立客服角色」导致无法按最小权限给客服开号：给 operator 就等于把
 * 商品/订单/退款/营销全部放出去。本迁移创建 cs_agent 角色并只授予客服中心三个权限。
 *
 * 与 `RolePermissionSeeder` 的关系（两条独立路径，必须结果一致）：
 * - Seeder 决定**全新安装**，本迁移决定**存量库升级**；
 * - 差异由 tests/Feature/CsPermissionSyncTest.php 逐项比对锁定。
 *
 * 幂等：角色与权限都用 findOrCreate；重复执行不会报错、不会改变既有授权集合。
 * 不创建账号：客服账号由超管在「系统 → 管理员账号」按需创建（该页已放行 cs_agent）。
 */
return new class extends Migration
{
    /** 客服角色的权限清单（与 RolePermissionSeeder::CS_AGENT_PERMISSIONS 一致） */
    private const ROLE = 'cs_agent';

    /** @var array<int, string> */
    private const PERMISSIONS = [
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

        // 权限码本身由 000039 创建；此处再 findOrCreate 一次，兼容「只跑了部分迁移」的库
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // 注意：不能用 Role::create()——spatie 的静态 create 会丢弃 display_name 附加字段
        $role = Role::findOrCreate(self::ROLE, 'web');
        if ($role->display_name !== '客服') {
            $role->display_name = '客服';
            $role->save();
        }

        // 只补不撤：不覆盖超管后续在「角色权限」页做的手工调整
        $role->givePermissionTo(self::PERMISSIONS);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! $this->permissionTablesExist()) {
            return;
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $role = Role::query()->where('name', self::ROLE)->where('guard_name', 'web')->first();
        if ($role !== null && $this->roleHasNoUser($role)) {
            $role->delete();
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /** 角色下仍有账号时保留角色（删角色会连带清掉授权关系，风险大于收益） */
    private function roleHasNoUser(Role $role): bool
    {
        try {
            return \App\Models\SysUser::query()->role($role->name)->count() === 0;
        } catch (\Throwable) {
            return false;
        }
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
