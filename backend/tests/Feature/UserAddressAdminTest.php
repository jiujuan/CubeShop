<?php

use App\Models\SysOperationLog;
use App\Models\SysUser;
use App\Models\UserAddress;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $login = function (string $username, string $password) {
        $cap = app(CaptchaService::class)->generate();
        return $this->postJson('/api/auth/login', [
            'username' => $username,
            'password' => $password,
            'captcha_id' => $cap['captcha_id'],
            'captcha_code' => $cap['debug_code'],
        ])->json('data.token');
    };

    // 管理员 / 运营
    $this->adminAuth = ['Authorization' => 'Bearer '.$login('admin', 'Admin@123')];
    $this->operatorAuth = ['Authorization' => 'Bearer '.$login('operator', 'Operator@123')];

    // 前台注册买家 + 一条默认地址
    $cap = app(CaptchaService::class)->generate();
    $this->buyerUsername = 'buyer'.uniqid();
    $this->buyerToken = $this->postJson('/api/auth/register', [
        'username' => $this->buyerUsername,
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token');
    $this->buyerAuth = ['Authorization' => 'Bearer '.$this->buyerToken];
    $this->buyer = SysUser::where('username', $this->buyerUsername)->first();

    $this->address = UserAddress::create([
        'user_id' => $this->buyer->id,
        'contact_name' => '张三',
        'contact_phone' => '13800001111',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区',
        'detail_address' => '科技园南路 88 号',
        'is_default' => true,
    ]);
});

// 权限：买家 403；operator 可查看（address.view）但代改被拒（无 address.manage）
test('TC-ADDR-001 权限分层：买家 403、运营可查看不可代改', function () {
    $this->getJson('/api/admin/users/'.$this->buyer->id.'/addresses', $this->buyerAuth)->assertStatus(403);
    $this->putJson('/api/admin/addresses/'.$this->address->id, ['detail_address' => 'x'], $this->buyerAuth)->assertStatus(403);

    $view = $this->getJson('/api/admin/users/'.$this->buyer->id.'/addresses', $this->operatorAuth);
    expect($view->json('code'))->toBe(0)
        ->and($view->json('data'))->toHaveCount(1)
        ->and($view->json('data.0.contact_phone'))->toBe('138****1111')
        ->and($view->json('data.0.is_default'))->toBeTrue();

    $edit = $this->putJson('/api/admin/addresses/'.$this->address->id, ['detail_address' => '改成'], $this->operatorAuth);
    expect($edit->json('code'))->toBe(40003);
});

// 超管代改成功：数据更新 + 操作日志含 before/after
test('TC-ADDR-002 超管代改成功并记录操作日志', function () {
    $resp = $this->putJson('/api/admin/addresses/'.$this->address->id, [
        'contact_name' => '李四',
        'detail_address' => '科技园南路 99 号',
    ], $this->adminAuth);

    expect($resp->json('code'))->toBe(0)
        ->and($resp->json('data.contact_name'))->toBe('李四')
        ->and($resp->json('data.contact_phone'))->toBe('138****1111')
        ->and($resp->json('data.is_default'))->toBeTrue();

    $this->assertDatabaseHas('user_addresses', [
        'id' => $this->address->id,
        'contact_name' => '李四',
        'detail_address' => '科技园南路 99 号',
        'is_default' => true,
    ]);

    $log = SysOperationLog::where('module', 'address')->where('action', 'update_address')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->target_type)->toBe('user_address')
        ->and($log->content)->toContain('科技园南路 88 号')
        ->and($log->content)->toContain('科技园南路 99 号');
});

// 保护：is_default / user_id 显式拒绝 40000，且默认标记未被改动
test('TC-ADDR-003 禁改字段显式拒绝', function () {
    $resp = $this->putJson('/api/admin/addresses/'.$this->address->id, [
        'is_default' => false,
        'detail_address' => '不该生效',
    ], $this->adminAuth);

    expect($resp->json('code'))->toBe(40000)
        ->and($this->address->refresh()->is_default)->toBeTrue()
        ->and($this->address->refresh()->detail_address)->toBe('科技园南路 88 号');
});

// 快照不可变：代改地址后，历史订单的 address_snapshot 不变（关键断言）
test('TC-ADDR-004 代改地址不影响历史订单快照', function () {
    $sku = createTestSku(stock: 10, price: '40.00');
    $this->postJson('/api/cart', ['sku_id' => $sku->id, 'quantity' => 1], $this->buyerAuth);
    $order = $this->postJson('/api/orders', ['address_id' => $this->address->id], $this->buyerAuth)->json('data');

    $resp = $this->putJson('/api/admin/addresses/'.$this->address->id, [
        'contact_name' => '李四',
        'detail_address' => '新地址 1 号',
    ], $this->adminAuth);
    expect($resp->json('code'))->toBe(0);

    $detail = $this->getJson('/api/admin/orders/'.$order['order_id'], $this->adminAuth)->json('data');
    expect($detail['address_snapshot']['contact_name'])->toBe('张三')
        ->and($detail['address_snapshot']['full_address'])->toContain('科技园南路 88 号');
});

// 边界：空提交 40000；手机号非法 40000；软删除地址 40004
test('TC-ADDR-005 边界：空提交/非法手机号/已删除', function () {
    $empty = $this->putJson('/api/admin/addresses/'.$this->address->id, [], $this->adminAuth);
    expect($empty->json('code'))->toBe(40000);

    $badPhone = $this->putJson('/api/admin/addresses/'.$this->address->id, ['contact_phone' => '12345'], $this->adminAuth);
    expect($badPhone->json('code'))->toBe(40000);

    $this->address->delete();
    $deleted = $this->putJson('/api/admin/addresses/'.$this->address->id, ['detail_address' => 'x'], $this->adminAuth);
    expect($deleted->json('code'))->toBe(40004);
});

// 前台地址上限：第 21 条被拒 40000
test('TC-ADDR-006 地址数量上限 20 条', function () {
    for ($i = 0; $i < 19; $i++) {
        UserAddress::create([
            'user_id' => $this->buyer->id,
            'contact_name' => '收货人'.$i,
            'contact_phone' => '1380000'.str_pad((string) ($i + 20), 4, '0', STR_PAD_LEFT),
            'detail_address' => '填充地址 '.$i,
        ]);
    }
    expect(UserAddress::where('user_id', $this->buyer->id)->count())->toBe(20);

    $cap = app(CaptchaService::class)->generate();
    $resp = $this->postJson('/api/user/addresses', [
        'contact_name' => '超额',
        'contact_phone' => '13899990000',
        'detail_address' => '第 21 条',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ], $this->buyerAuth);

    expect($resp->json('code'))->toBe(40000)
        ->and($resp->json('message'))->toContain('20')
        ->and(UserAddress::where('user_id', $this->buyer->id)->count())->toBe(20);
});
