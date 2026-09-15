<?php

use App\Models\SysUser;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

/**
 * 权限同步回归测试
 *
 * 背景：V1.1 一期新增了 5 个权限码，但存量环境不会重跑 seeder，
 * 导致超级管理员实际未持有 report.view，访问报表接口 403。
 * 本测试确保「seeder 中声明的权限码」与「超管实际持有的权限」始终一致，
 * 防止后续新增权限码时再次遗漏。
 */
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('TC-PERM-001 seeder 声明的每个权限码都已落库', function () {
    foreach (RolePermissionSeeder::PERMISSIONS as $name) {
        expect(Permission::where('name', $name)->where('guard_name', 'web')->exists())
            ->toBeTrue("权限码 {$name} 未同步到数据库");
    }
});

test('TC-PERM-002 超级管理员持有全部权限码', function () {
    $superAdmin = Role::where('name', 'super_admin')->firstOrFail();

    expect($superAdmin->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(RolePermissionSeeder::PERMISSIONS);

    $admin = SysUser::where('username', 'admin')->firstOrFail();
    foreach (RolePermissionSeeder::PERMISSIONS as $name) {
        expect($admin->can($name))->toBeTrue("超级管理员缺少权限 {$name}");
    }
});

test('TC-PERM-003 超级管理员可访问 V1.1 全部报表与运营接口', function () {
    $admin = SysUser::where('username', 'admin')->firstOrFail();
    $token = $admin->createToken('test')->plainTextToken;

    $endpoints = [
        '/api/admin/reports/overview',
        '/api/admin/reports/trend?days=30',
        '/api/admin/reports/top-products',
        '/api/admin/reports/category-share',
        '/api/admin/reports/users',
        '/api/admin/reviews',
        '/api/admin/accounts',
        '/api/admin/roles',
    ];

    foreach ($endpoints as $endpoint) {
        getJson($endpoint, ['Authorization' => "Bearer {$token}"])
            ->assertOk();
    }
});

test('TC-PERM-004 运营角色不具备超管专属权限', function () {
    $operator = SysUser::where('username', 'operator')->firstOrFail();
    $token = $operator->createToken('test')->plainTextToken;

    // 运营可查看报表与评价
    getJson('/api/admin/reports/trend?days=30', ['Authorization' => "Bearer {$token}"])
        ->assertOk();

    // 账号与角色管理为超管专属
    getJson('/api/admin/accounts', ['Authorization' => "Bearer {$token}"])
        ->assertForbidden();
    getJson('/api/admin/roles', ['Authorization' => "Bearer {$token}"])
        ->assertForbidden();
});
