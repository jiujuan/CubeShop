<?php

use App\Models\PaymentReconciliationDiff;
use App\Models\PaymentReconciliationRun;
use App\Models\SysUser;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * 支付渠道日终对账后台接口（A7-支付渠道对账，权限 payment.reconcile.view / handle）
 *
 * 覆盖：运行清单 / 详情（含差异汇总）/ 差异工单清单（筛选）/ 处置（resolve|ignore）与权限分层。
 */
beforeEach(function () {
    test()->seed(\Database\Seeders\RolePermissionSeeder::class);
    config(['app.debug' => true]);

    $login = function (string $username, string $password): ?string {
        $cap = app(CaptchaService::class)->generate();

        return test()->postJson('/api/auth/login', [
            'username' => $username,
            'password' => $password,
            'captcha_id' => $cap['captcha_id'],
            'captcha_code' => $cap['debug_code'],
        ])->json('data.token');
    };

    $this->adminAuth = ['Authorization' => 'Bearer '.$login('admin', 'Admin@123')];
    $this->operatorAuth = ['Authorization' => 'Bearer '.$login('operator', 'Operator@123')];

    // 用于 403 分支：仅持有 payment.view、无对账权限的账号
    $noPermRole = Role::create(['name' => 'pcr_no_perm', 'guard_name' => 'web']);
    $noPermRole->syncPermissions(['payment.view']);
    $noPermUser = SysUser::create([
        'username' => 'pcrnoperm'.uniqid(),
        'password' => Hash::make('Test@1234'),
        'nickname' => '无对账权',
        'status' => 1,
    ]);
    $noPermUser->assignRole('pcr_no_perm');
    $this->noPermAuth = ['Authorization' => 'Bearer '.$login($noPermUser->username, 'Test@1234')];

    // 预置一条存在 1 笔差异的运行批次
    $this->run = PaymentReconciliationRun::create([
        'reconcile_date' => '2026-09-20',
        'channel' => 'mock',
        'status' => PaymentReconciliationRun::STATUS_PARTIAL,
        'local_count' => 1, 'channel_count' => 2, 'matched_count' => 1, 'diff_count' => 1,
        'local_amount' => '100.00', 'channel_amount' => '130.00',
    ]);
    $this->diff = PaymentReconciliationDiff::create([
        'run_id' => $this->run->id,
        'reconcile_date' => '2026-09-20',
        'channel' => 'mock',
        'diff_type' => PaymentReconciliationDiff::TYPE_MISSING_LOCAL,
        'payment_no' => null,
        'channel_trade_no' => 'CH_ONLY_1',
        'order_no' => null,
        'local_amount' => null,
        'channel_amount' => '30.00',
        'local_status' => null,
        'channel_status' => 'paid',
        'detail' => json_encode(['trade_no' => 'CH_ONLY_1']),
        'status' => PaymentReconciliationDiff::STATUS_PENDING,
    ]);
});

test('A7C-01 超管可查看运行清单并带渠道中文名', function () {
    $data = $this->getJson('/api/admin/payment-reconciles', $this->adminAuth)->json('data');

    $row = collect($data['list'])->firstWhere('id', $this->run->id);
    expect($row)->not->toBeNull()
        ->and($row['channel_label'])->toBe('本地模拟')
        ->and($row['status_label'])->toBe('存在差异')
        ->and($data['pagination']['total'])->toBeGreaterThan(0);
});

test('A7C-02 运行详情含按类型汇总的待处理差异', function () {
    $data = $this->getJson("/api/admin/payment-reconciles/{$this->run->id}", $this->adminAuth)->json('data');

    expect($data['id'])->toBe($this->run->id)
        ->and($data['pending_by_type'])->toHaveKey(PaymentReconciliationDiff::TYPE_MISSING_LOCAL)
        ->and($data['pending_by_type'][PaymentReconciliationDiff::TYPE_MISSING_LOCAL])->toBe(1);
});

test('A7C-03 差异工单清单支持按类型与关键词筛选', function () {
    $byType = $this->getJson('/api/admin/payment-reconcile-diffs?diff_type='.PaymentReconciliationDiff::TYPE_MISSING_LOCAL, $this->adminAuth)->json('data');
    expect(collect($byType['list'])->pluck('id')->all())->toContain($this->diff->id);

    $byKw = $this->getJson('/api/admin/payment-reconcile-diffs?keyword=CH_ONLY_1', $this->adminAuth)->json('data');
    expect(collect($byKw['list'])->pluck('id')->all())->toContain($this->diff->id);

    $none = $this->getJson('/api/admin/payment-reconcile-diffs?keyword=NOPE_NOPE', $this->adminAuth)->json('data');
    expect($none['pagination']['total'])->toBe(0);
});

test('A7C-04 差异清单带回显中文标签', function () {
    $data = $this->getJson('/api/admin/payment-reconcile-diffs', $this->adminAuth)->json('data');
    $row = collect($data['list'])->firstWhere('id', $this->diff->id);

    expect($row['diff_type_label'])->toContain('漏单')
        ->and($row['status_label'])->toBe('待处理')
        ->and($row['channel_amount'])->toBe('30.00');
});

test('A7C-05 运营（含对账权限）可查看清单', function () {
    $this->getJson('/api/admin/payment-reconciles', $this->operatorAuth)->assertOk();
    $this->getJson('/api/admin/payment-reconcile-diffs', $this->operatorAuth)->assertOk();
    $this->getJson("/api/admin/payment-reconciles/{$this->run->id}", $this->operatorAuth)->assertOk();
});

test('A7C-06 无 payment.reconcile.view 权限 → 403', function () {
    $this->getJson('/api/admin/payment-reconciles', $this->noPermAuth)->assertForbidden();
    $this->getJson('/api/admin/payment-reconcile-diffs', $this->noPermAuth)->assertForbidden();
    $this->getJson("/api/admin/payment-reconciles/{$this->run->id}", $this->noPermAuth)->assertForbidden();
});

test('A7C-07 未登录访问对账接口 → 401', function () {
    $this->getJson('/api/admin/payment-reconciles')->assertUnauthorized();
});

test('A7C-08 处置 resolve：置 resolved 并回显', function () {
    $resp = $this->postJson("/api/admin/payment-reconcile-diffs/{$this->diff->id}/resolve", [
        'action' => 'resolve',
        'remark' => '已补单',
    ], $this->adminAuth)->assertOk()->json('data');

    expect($resp['status'])->toBe(PaymentReconciliationDiff::STATUS_RESOLVED)
        ->and($resp['status_label'])->toBe('已处置');

    $this->diff->refresh();
    expect($this->diff->status)->toBe(PaymentReconciliationDiff::STATUS_RESOLVED)
        ->and($this->diff->handled_by)->toBe((int) SysUser::where('username', 'admin')->value('id'))
        ->and($this->diff->handle_remark)->toBe('已补单');
});

test('A7C-09 处置 ignore：置 ignored', function () {
    $resp = $this->postJson("/api/admin/payment-reconcile-diffs/{$this->diff->id}/resolve", [
        'action' => 'ignore',
        'remark' => '已知在途',
    ], $this->adminAuth)->assertOk()->json('data');

    expect($resp['status'])->toBe(PaymentReconciliationDiff::STATUS_IGNORED);
    $this->diff->refresh();
    expect($this->diff->status)->toBe(PaymentReconciliationDiff::STATUS_IGNORED);
});

test('A7C-10 已处置的差异重复 resolve → 409 业务冲突', function () {
    $this->postJson("/api/admin/payment-reconcile-diffs/{$this->diff->id}/resolve", [
        'action' => 'resolve', 'remark' => 'x',
    ], $this->adminAuth)->assertOk();

    $this->postJson("/api/admin/payment-reconcile-diffs/{$this->diff->id}/resolve", [
        'action' => 'resolve', 'remark' => 'y',
    ], $this->adminAuth)->assertStatus(409)->assertJsonPath('code', 40009);
});

test('A7C-11 处置不存在的差异 → 404', function () {
    $this->postJson('/api/admin/payment-reconcile-diffs/999999/resolve', [
        'action' => 'resolve',
    ], $this->adminAuth)->assertStatus(404)->assertJsonPath('code', 40004);
});

test('A7C-12 非法 action 参数 → 422', function () {
    $this->postJson("/api/admin/payment-reconcile-diffs/{$this->diff->id}/resolve", [
        'action' => 'not_an_action',
    ], $this->adminAuth)->assertStatus(422);
});

test('A7C-13 处置需 payment.reconcile.handle：运营可、无权限账号 403', function () {
    $this->postJson("/api/admin/payment-reconcile-diffs/{$this->diff->id}/resolve", [
        'action' => 'resolve',
    ], $this->operatorAuth)->assertOk();

    // 重新造一条 pending 给无权限账号测 403
    $diff2 = PaymentReconciliationDiff::create([
        'run_id' => $this->run->id,
        'reconcile_date' => '2026-09-20',
        'channel' => 'mock',
        'diff_type' => PaymentReconciliationDiff::TYPE_MISSING_LOCAL,
        'channel_trade_no' => 'CH_ONLY_2',
        'channel_amount' => '40.00',
        'status' => PaymentReconciliationDiff::STATUS_PENDING,
    ]);
    $this->postJson("/api/admin/payment-reconcile-diffs/{$diff2->id}/resolve", [
        'action' => 'resolve',
    ], $this->noPermAuth)->assertForbidden();
});

test('A7C-14 看板统计接口：超管可见结构、无权限 403、未登录 401', function () {
    $data = $this->getJson('/api/admin/payment-reconciles/stats', $this->adminAuth)
        ->assertOk()
        ->json('data');

    expect($data)->toHaveKeys([
        'total_runs', 'total_diffs', 'pending_diffs', 'processing_diffs',
        'resolved_diffs', 'ignored_diffs', 'by_type', 'trend',
    ])
        ->and($data['total_runs'])->toBe(1) // beforeEach 预置 1 条运行批次
        ->and($data['total_diffs'])->toBe(1)
        ->and($data['trend'])->toHaveCount(14);

    $this->getJson('/api/admin/payment-reconciles/stats', $this->noPermAuth)->assertForbidden();
    $this->getJson('/api/admin/payment-reconciles/stats')->assertUnauthorized();
});

test('A7C-15 导出对账差异 CSV：表头 + 行数据 + 筛选生效', function () {
    $res = $this->getJson('/api/admin/payment-reconcile-diffs/export?status=pending', $this->adminAuth);

    $res->assertOk();
    expect(str_contains((string) $res->headers->get('Content-Type'), 'text/csv'))->toBeTrue();

    $body = $res->streamedContent();
    expect(str_starts_with($body, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($body)->toContain('对账日期')
        ->and($body)->toContain('CH_ONLY_1');

    // 处置后不再出现在 pending 筛选的导出里
    $this->postJson("/api/admin/payment-reconcile-diffs/{$this->diff->id}/resolve", ['action' => 'resolve'], $this->operatorAuth)->assertOk();
    $body2 = $this->getJson('/api/admin/payment-reconcile-diffs/export?status=pending', $this->adminAuth)->streamedContent();
    expect($body2)->not->toContain('CH_ONLY_1');
});

test('A7C-15b 导出无权限 → 403', function () {
    $this->getJson('/api/admin/payment-reconcile-diffs/export', $this->noPermAuth)->assertForbidden();
});

test('A7C-16 前台对账看板：运营 200 / 无权 403 / 买家 403 / 未登录 401 / 非法平台 422', function () {
    $this->diff->forceFill(['platform' => 'h5'])->save();

    $data = $this->getJson('/api/payment-reconcile/dashboard?platform=h5', $this->adminAuth)
        ->assertOk()->json('data');
    expect($data['total_diffs'])->toBe(1)
        ->and($data['by_channel'][0]['channel'])->toBe('mock')
        ->and($data['trend'])->toHaveCount(14);

    // operator 持有 view 权限 → 200
    $this->getJson('/api/payment-reconcile/dashboard', $this->operatorAuth)->assertOk();

    // 无对账权限的运营账号 → 403
    $this->getJson('/api/payment-reconcile/dashboard', $this->noPermAuth)->assertForbidden();

    // 买家账号（无 spatie）→ 403（买家端 sanctum 为无状态守卫，必须真实登录拿 token）
    $cap = app(\App\Services\Common\CaptchaService::class)->generate();
    $buyerToken = $this->postJson('/api/auth/register', [
        'username' => 'pcrbuyer'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token');
    $this->getJson('/api/payment-reconcile/dashboard', ['Authorization' => 'Bearer '.$buyerToken])->assertForbidden();

    // 未登录 → 401
    $this->getJson('/api/payment-reconcile/dashboard')->assertUnauthorized();

    // 非法 platform → 422
    $this->getJson('/api/payment-reconcile/dashboard?platform=ios', $this->adminAuth)->assertUnprocessable();
});
