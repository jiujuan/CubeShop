<?php

use App\Models\SysUser;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * 用户表拆分 · 阶段 1 集成测试（建表与数据搬迁）
 *
 * 覆盖迁移 `2026_09_16_000023_migrate_customers_to_users_table`：
 *  - 买家（持有 customer 角色）按原 ID 复制到 users
 *  - 管理员（无 customer 角色）不被搬迁
 *  - 幂等：重复执行不产生重复行
 *  - 软删除买家一并搬迁并保留 deleted_at
 *  - 搬迁后自增序列对齐，新买家 ID 不与存量冲突
 *
 * 方案文档：docs/design/CubeShop_UserTable_Split_Analysis.md（阶段 1）
 */
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/** 执行搬迁迁移的 up() */
function runBuyerMigration(): void
{
    (require database_path('migrations/2026_09_16_000023_migrate_customers_to_users_table.php'))->up();
}

/** 创建一个买家（持有 customer 角色） */
function makeBuyer(string $username, array $extra = []): SysUser
{
    $user = SysUser::create(array_merge([
        'username' => $username,
        'password' => 'Secret123',
        'phone' => null,
        'email' => null,
        'nickname' => $username,
        'status' => 1,
    ], $extra));

    $user->assignRole('customer');

    return $user;
}

test('TC-SPLIT-001 买家按原 ID 搬迁到 users 表', function () {
    $a = makeBuyer('buyer_a', ['phone' => '13800000001']);
    $b = makeBuyer('buyer_b', ['email' => 'buyer_b@example.com']);

    runBuyerMigration();

    expect(DB::table('users')->count())->toBe(2);

    $rowA = DB::table('users')->where('id', $a->id)->first();
    expect($rowA)->not->toBeNull()
        ->and($rowA->id)->toBe($a->id)               // 原 ID 保持不变
        ->and($rowA->username)->toBe('buyer_a')
        ->and($rowA->phone)->toBe('13800000001')
        ->and($rowA->password)->toBe($a->password);   // 密码哈希原样保留

    expect(DB::table('users')->where('id', $b->id)->value('email'))->toBe('buyer_b@example.com');
});

test('TC-SPLIT-002 管理员不被搬迁', function () {
    $admin = SysUser::where('username', 'admin')->firstOrFail();
    $operator = SysUser::where('username', 'operator')->firstOrFail();

    makeBuyer('buyer_c');

    runBuyerMigration();

    expect(DB::table('users')->whereIn('id', [$admin->id, $operator->id])->count())->toBe(0)
        ->and(DB::table('users')->count())->toBe(1)
        ->and(DB::table('users')->value('username'))->toBe('buyer_c');
});

test('TC-SPLIT-003 迁移幂等：重复执行不产生重复行', function () {
    makeBuyer('buyer_d');

    runBuyerMigration();
    runBuyerMigration();
    runBuyerMigration();

    expect(DB::table('users')->count())->toBe(1);
});

test('TC-SPLIT-004 软删除买家一并搬迁并保留 deleted_at', function () {
    $buyer = makeBuyer('buyer_e');
    $buyer->delete();                                  // 软删除

    runBuyerMigration();

    $row = DB::table('users')->where('id', $buyer->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->deleted_at)->not->toBeNull();
});

test('TC-SPLIT-005 搬迁后自增序列对齐，新买家 ID 不冲突', function () {
    makeBuyer('buyer_f');
    makeBuyer('buyer_g');

    runBuyerMigration();

    $maxId = (int) DB::table('users')->max('id');

    $newId = DB::table('users')->insertGetId([
        'username' => 'buyer_new',
        'password' => 'Secret123',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($newId)->toBeGreaterThan($maxId);
    expect(DB::table('users')->count())->toBe(3);
});
