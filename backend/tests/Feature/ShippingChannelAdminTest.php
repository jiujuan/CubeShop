<?php

use App\Models\Order;
use App\Models\Shipping;
use App\Models\ShippingTrace;
use App\Models\SystemConfig;
use App\Services\Common\ConfigService;
use App\Support\Shipping\NullChannel;
use App\Support\Shipping\ShippingChannelInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * V1.1 三期：物流渠道查看/切换（admin）与运单轨迹详情
 *
 * 覆盖：① 默认跟随 .env；② 后台切换写库且下次启动覆盖生效；③ off 强制关闭；
 *       ④ 非法渠道值拒绝；⑤ 切换需 shipping.manage；⑥ 轨迹详情与用户端同口径；
 *       ⑦ 详情不存在 / 无权限。
 *
 * ⚠️ 密钥不入库：key/customer 仍走 .env，接口只回显「是否已配置」。
 */

beforeEach(function () {
    seedRoles();

    $cap = app(\App\Services\Common\CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    // 模拟 .env：SHIPPING_CHANNEL=mock
    config(['services.shipping.channel' => 'mock', 'services.shipping.key' => '', 'services.shipping.customer' => '']);
    app(ConfigService::class)->flush();
});

/** 建一条带轨迹的运单 */
function channelAdminShipping(string $trackingNo = 'SFCHN0001'): Shipping
{
    $user = createTestUser('chn'.uniqid());
    $order = Order::create([
        'order_no' => 'CSCHN'.uniqid(),
        'user_id' => $user->id,
        'status' => Order::STATUS_SHIPPED,
        'total_amount' => '100.00',
        'freight_amount' => '0.00',
        'pay_amount' => '100.00',
        'address_snapshot' => ['name' => '测试', 'contact_phone' => '13800000000'],
    ]);

    $shipping = Shipping::create([
        'order_id' => $order->id,
        'company_code' => 'SF',
        'company_name' => '顺丰速运',
        'tracking_no' => $trackingNo,
        'phone' => '13800000000',
        'trace_status' => Shipping::TRACE_IN_TRANSIT,
        'shipped_at' => now()->subDay(),
    ]);

    foreach ([['快件已揽收', now()->subDays(2)], ['快件已到达中转中心', now()->subDay()]] as [$context, $at]) {
        ShippingTrace::create([
            'shipping_id' => $shipping->id,
            'context' => $context,
            'occurred_at' => $at,
        ]);
    }

    return $shipping;
}

test('TC-CHN-01 渠道查看：默认跟随 .env 且不明文返回密钥', function () {
    $res = $this->getJson('/api/admin/shippings/channel', $this->adminAuth);

    expect($res->json('code'))->toBe(0)
        ->and($res->json('data.configured'))->toBe('')
        ->and($res->json('data.channel'))->toBe('mock')
        ->and($res->json('data.source'))->toBe('env')
        ->and($res->json('data.key_configured'))->toBeFalse();

    // 密钥不得出现在响应里
    expect($res->json())->not->toHaveKey('key')
        ->and($res->json('data'))->not->toHaveKey('key');
});

test('TC-CHN-02 后台切换渠道写库，并在下次启动覆盖生效', function () {
    $put = $this->putJson('/api/admin/shippings/channel', ['channel' => 'kuaidi100'], $this->adminAuth);
    expect($put->json('code'))->toBe(0)
        ->and($put->json('data.configured'))->toBe('kuaidi100');

    expect(SystemConfig::where('config_key', 'shipping.channel')->value('config_value'))->toBe('kuaidi100');

    // 模拟下一个请求：重新 boot（AppServiceProvider 用 DB 值覆盖 config）
    config(['services.shipping.channel' => 'mock']);
    (new \App\Providers\AppServiceProvider(app()))->boot();

    expect(config('services.shipping.channel'))->toBe('kuaidi100');

    $res = $this->getJson('/api/admin/shippings/channel', $this->adminAuth);
    expect($res->json('data.source'))->toBe('database')
        ->and($res->json('data.channel'))->toBe('kuaidi100');
});

test('TC-CHN-03 off 强制关闭查询（覆盖 .env 中已配置的渠道）', function () {
    $this->putJson('/api/admin/shippings/channel', ['channel' => 'off'], $this->adminAuth)
        ->assertOk();

    config(['services.shipping.channel' => 'mock']);
    (new \App\Providers\AppServiceProvider(app()))->boot();

    expect(config('services.shipping.channel'))->toBeNull()
        ->and(app(ShippingChannelInterface::class))->toBeInstanceOf(NullChannel::class);
});

test('TC-CHN-04 非法渠道值被拒绝', function () {
    $res = $this->putJson('/api/admin/shippings/channel', ['channel' => 'evil-channel'], $this->adminAuth);

    expect($res->json('code'))->toBe(40000)
        // 迁移已注册该配置项，非法值不得写库污染
        ->and(SystemConfig::where('config_key', 'shipping.channel')->value('config_value'))->toBe('')
        ->and($res->json('data.allowed'))->toContain('kuaidi100');
});

test('TC-CHN-05 切换渠道需 shipping.manage 权限', function () {
    $user = createTestUser('chnnoperm');
    $auth = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];

    $this->putJson('/api/admin/shippings/channel', ['channel' => 'mock'], $auth)->assertStatus(403);
    // 查看渠道只需 order.view：无该权限同样 403
    $this->getJson('/api/admin/shippings/channel', $auth)->assertStatus(403);
});

test('TC-CHN-06 运单轨迹详情与用户端口径一致', function () {
    $shipping = channelAdminShipping();
    $res = $this->getJson("/api/admin/shippings/{$shipping->id}", $this->adminAuth);

    expect($res->json('code'))->toBe(0)
        ->and($res->json('data.tracking_no'))->toBe('SFCHN0001')
        ->and($res->json('data.order_no'))->toBe($shipping->order->order_no)
        ->and($res->json('data.has_trace'))->toBeTrue()
        ->and($res->json('data.traces'))->toHaveCount(2)
        // 倒序：最新在顶
        ->and($res->json('data.traces.0.context'))->toBe('快件已到达中转中心');

    // 与用户端 /orders/{id}/shipping 同一口径（context + occurred_at）
    expect(array_keys($res->json('data.traces.0')))->toBe(['context', 'occurred_at']);
});

test('TC-CHN-07 轨迹详情不存在返回 40004，无权限返回 403', function () {
    $this->getJson('/api/admin/shippings/999999', $this->adminAuth)
        ->assertOk()
        ->assertJsonPath('code', 40004);

    $user = createTestUser('chnnoperm2');
    $auth = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
    $this->getJson('/api/admin/shippings/1', $auth)->assertStatus(403);
});
