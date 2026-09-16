<?php

use App\Models\SysUser;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * 用户表拆分 · 阶段 3 集成测试（遗留数据清理）
 *
 * 覆盖迁移 `2026_09_16_000028_cleanup_user_split_legacy_data`：
 *  - 删除 sys_user 中的买家僵尸记录（数据保留在 users 表）
 *  - 清理 model_has_roles 中买家的角色记录
 *  - 移除已无持有者的 customer 角色
 *  - 不影响后台管理员账号与其角色
 *  - 幂等、且有「用户名不一致不删」的安全网
 *
 * 方案文档：docs/design/CubeShop_UserTable_Split_Analysis.md（阶段 3）
 */
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/** 执行清理迁移的 up() */
function runCleanupMigration(): void
{
    (require database_path('migrations/2026_09_16_000028_cleanup_user_split_legacy_data.php'))->up();
}

/**
 * 构造一位「阶段 1/2 之后」的遗留买家：
 * 同一账号同时存在于 sys_user（僵尸）与 users（在用），并在 spatie 中持有 customer 角色
 */
function legacyBuyer(string $username): array
{
    $customer = Role::findOrCreate('customer', 'web');

    $sys = SysUser::create([
        'username' => $username, 'password' => 'Secret123', 'nickname' => $username, 'status' => 1,
    ]);
    $sys->assignRole($customer);

    // 注意：User 模型走 $fillable，`id` 不在其中，必须用 forceCreate 显式保留原 ID
    $user = User::forceCreate([
        'id' => $sys->id,                                     // 拆分时保持原 ID
        'username' => $username, 'password' => 'Secret123', 'nickname' => $username, 'status' => 1,
    ]);

    return [$sys, $user];
}

test('TC-CLEAN-001 清理后 sys_user 不再保留买家记录', function () {
    [$sys, $user] = legacyBuyer('zombie_a');

    runCleanupMigration();

    expect(SysUser::withTrashed()->whereKey($sys->id)->exists())->toBeFalse()
        // 数据未丢失：完整保留在 users 表
        ->and(User::whereKey($user->id)->exists())->toBeTrue()
        ->and(User::whereKey($user->id)->value('username'))->toBe('zombie_a');
});

test('TC-CLEAN-002 清理买家在 model_has_roles 中的角色记录', function () {
    [$sys] = legacyBuyer('zombie_b');

    expect(DB::table('model_has_roles')->where('model_type', 'App\Models\SysUser')->where('model_id', $sys->id)->count())->toBe(1);

    runCleanupMigration();

    expect(DB::table('model_has_roles')->where('model_type', 'App\Models\SysUser')->where('model_id', $sys->id)->count())->toBe(0);
});

test('TC-CLEAN-003 customer 角色被移除', function () {
    legacyBuyer('zombie_c');                                        // 该辅助会重建遗留角色
    expect(Role::where('name', 'customer')->exists())->toBeTrue();

    runCleanupMigration();

    expect(Role::where('name', 'customer')->exists())->toBeFalse()
        // 内置后台角色保留
        ->and(Role::where('name', 'super_admin')->exists())->toBeTrue()
        ->and(Role::where('name', 'operator')->exists())->toBeTrue();
});

test('TC-CLEAN-004 管理员账号与角色不受影响', function () {
    [$sys] = legacyBuyer('zombie_d');

    $admin = SysUser::where('username', 'admin')->firstOrFail();
    $operator = SysUser::where('username', 'operator')->firstOrFail();

    runCleanupMigration();

    expect(SysUser::withTrashed()->whereKey($admin->id)->exists())->toBeTrue()
        ->and(SysUser::withTrashed()->whereKey($operator->id)->exists())->toBeTrue()
        ->and($admin->fresh()->hasRole('super_admin'))->toBeTrue()
        ->and($operator->fresh()->hasRole('operator'))->toBeTrue()
        ->and($sys->fresh() ?? null)->toBeNull();
});

test('TC-CLEAN-005 清理幂等：重复执行不报错且结果一致', function () {
    legacyBuyer('zombie_e');

    runCleanupMigration();
    $sysCount = SysUser::withTrashed()->count();
    $roleCount = Role::count();

    runCleanupMigration();

    expect(SysUser::withTrashed()->count())->toBe($sysCount)
        ->and(Role::count())->toBe($roleCount)
        ->and(Role::where('name', 'customer')->exists())->toBeFalse();
});

test('TC-CLEAN-006 安全网：用户名不一致不会误删', function () {
    // 构造 id 相同但用户名不同的两条记录（不构成同一账号）
    $sys = SysUser::create([
        'username' => 'not_the_same', 'password' => 'Secret123', 'nickname' => 'x', 'status' => 1,
    ]);
    User::forceCreate([
        'id' => $sys->id,
        'username' => 'different_buyer', 'password' => 'Secret123', 'nickname' => 'y', 'status' => 1,
    ]);

    runCleanupMigration();

    // ID 与用户名未同时匹配 → 不认定为同一账号，保留 sys_user 记录
    expect(SysUser::withTrashed()->whereKey($sys->id)->exists())->toBeTrue();
});
