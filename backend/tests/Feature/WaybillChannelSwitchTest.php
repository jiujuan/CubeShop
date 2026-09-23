<?php

use App\Providers\AppServiceProvider;
use App\Services\Common\ConfigService;
use App\Support\Shipping\MockWaybillChannel;
use App\Support\Shipping\NullWaybillChannel;
use App\Support\Shipping\WaybillChannelInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * 电子面单申请渠道后台切换（V1.2，与轨迹查询渠道对称）
 *
 * 覆盖：① 未配置 → 跟随 .env（source=env）；② 非法渠道 → 40000；
 *       ③ 切到 mock → 落库 + 即时生效（source=database，available=true）；
 *       ④ 恢复为 '' → 回 env；⑤ AppServiceProvider.applyWaybillChannelOverride 运行期覆写（DB 值覆盖 config）。
 *
 * 注意：本地 .env 可能已设 WAYBILL_CHANNEL（如 mock）用于演示，测试里按需中和，保证断言确定性。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $cap = app(\App\Services\Common\CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];
});

it('未配置时跟随 .env（source=env）', function () {
    // 中和本地 .env 可能设的 WAYBILL_CHANNEL，验证「未启用」口径
    config(['services.waybill.channel' => null]);

    $res = $this->getJson('/api/admin/shippings/waybill-channel', $this->adminAuth)
        ->assertOk()
        ->json('data');

    expect($res['configured'])->toBe('')
        ->and($res['source'])->toBe('env')
        ->and($res['channel'])->toBeNull()
        ->and($res['available'])->toBeFalse()
        ->and($res['label'])->toBe('手动录入（未启用电子面单）');
});

it('拒绝非法渠道', function () {
    $this->putJson('/api/admin/shippings/waybill-channel', ['channel' => 'sf_express'], $this->adminAuth)
        ->assertOk()
        ->assertJsonPath('code', 40000);
});

it('切换到 mock 后落库并即时生效', function () {
    $this->putJson('/api/admin/shippings/waybill-channel', ['channel' => 'mock'], $this->adminAuth)
        ->assertOk()
        ->assertJsonPath('data.configured', 'mock')
        ->assertJsonPath('message', '电子面单渠道已切换');

    // 落库
    expect(DB::table('system_configs')->where('config_key', 'waybill.channel')->value('config_value'))
        ->toBe('mock');

    // 立即查看：source=database，mock 可用
    $res = $this->getJson('/api/admin/shippings/waybill-channel', $this->adminAuth)
        ->assertOk()
        ->json('data');

    expect($res['configured'])->toBe('mock')
        ->and($res['source'])->toBe('database')
        ->and($res['channel'])->toBe('mock')
        ->and($res['available'])->toBeTrue();
});

it('恢复为跟随 .env（channel=空串）', function () {
    $this->putJson('/api/admin/shippings/waybill-channel', ['channel' => 'mock'], $this->adminAuth)->assertOk();

    $this->putJson('/api/admin/shippings/waybill-channel', ['channel' => ''], $this->adminAuth)
        ->assertOk()
        ->assertJsonPath('data.configured', '')
        ->assertJsonPath('message', '已恢复为跟随环境配置');

    expect(DB::table('system_configs')->where('config_key', 'waybill.channel')->value('config_value'))
        ->toBe('');
});

it('AppServiceProvider.applyWaybillChannelOverride 用 DB 值覆盖 config', function () {
    $provider = new AppServiceProvider($this->app);
    $method = new ReflectionMethod($provider, 'applyWaybillChannelOverride');
    $method->setAccessible(true);

    // DB=mock → config 应被覆写为 mock
    DB::table('system_configs')->updateOrInsert(
        ['config_key' => 'waybill.channel'],
        ['config_value' => 'mock', 'updated_at' => now()],
    );
    app(ConfigService::class)->flush();
    config(['services.waybill.channel' => null]);
    $method->invoke($provider);
    expect(config('services.waybill.channel'))->toBe('mock')
        ->and(app(WaybillChannelInterface::class))->toBeInstanceOf(MockWaybillChannel::class);

    // DB=off → config 应被覆写为 null（强制手动录入）
    DB::table('system_configs')->updateOrInsert(
        ['config_key' => 'waybill.channel'],
        ['config_value' => 'off', 'updated_at' => now()],
    );
    app(ConfigService::class)->flush();
    $method->invoke($provider);
    expect(config('services.waybill.channel'))->toBeNull()
        ->and(app(WaybillChannelInterface::class))->toBeInstanceOf(NullWaybillChannel::class);

    // DB='' → 不覆写，保留原 config（跟随 .env）
    DB::table('system_configs')->updateOrInsert(
        ['config_key' => 'waybill.channel'],
        ['config_value' => '', 'updated_at' => now()],
    );
    app(ConfigService::class)->flush();
    config(['services.waybill.channel' => 'kuaidi100']);
    $method->invoke($provider);
    expect(config('services.waybill.channel'))->toBe('kuaidi100');
});
