<?php

use App\Services\Common\CaptchaService;
use App\Services\Payment\PaymentChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 集成测试：后台支付渠道配置（§5，权限 payment.channel.manage，仅超管）
 * 覆盖脱敏回显、写入加密、留空不覆盖、启停、连接测试、权限分层。
 */
beforeEach(function () {
    test()->seed(\Database\Seeders\RolePermissionSeeder::class);

    $login = function (string $username, string $password) {
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
});

test('超管可获取渠道列表且敏感字段脱敏', function () {
    $res = $this->getJson('/api/admin/payment-channels', $this->adminAuth)->json('data.list');

    $wechat = collect($res)->firstWhere('channel', 'wechat');
    expect($wechat)->not->toBeNull()
        ->and($wechat['is_online'])->toBeTrue()
        ->and($wechat['config'])->toBeArray();

    // 未配置时也应返回 has_xxx 布尔位（不返回明文密钥）
    expect($wechat['config'])->toHaveKey('has_api_v3_key')
        ->and($wechat['config'])->not->toHaveKey('api_v3_key');
});

test('运营无权限访问渠道配置（403）', function () {
    $this->getJson('/api/admin/payment-channels', $this->operatorAuth)->assertForbidden();
    $this->putJson('/api/admin/payment-channels/wechat', ['name' => 'x'], $this->operatorAuth)->assertForbidden();
});

test('超管更新渠道配置：敏感键加密、留空不覆盖', function () {
    $privateKey = "-----BEGIN RSA PRIVATE KEY-----\nMOCKKEY\n-----END RSA PRIVATE KEY-----";
    $this->putJson('/api/admin/payment-channels/wechat', [
        'config' => [
            'app_id' => 'wx-test-123',
            'mch_id' => '1900000001',
            'api_v3_key' => '0123456789abcdef0123456789abcdef',
            'merchant_private_key' => $privateKey,
            'merchant_cert_serial_no' => 'SERIAL1',
            'wechatpay_public_key' => "-----BEGIN PUBLIC KEY-----\nMOCKPUB\n-----END PUBLIC KEY-----",
        ],
    ], $this->adminAuth)->assertOk();

    $res = $this->getJson('/api/admin/payment-channels/wechat', $this->adminAuth)->json('data');
    $cfg = $res['config'];
    expect($cfg['has_api_v3_key'])->toBeTrue()
        ->and($cfg['has_merchant_private_key'])->toBeTrue()
        ->and($cfg['api_v3_key'])->toMatch('/^\*\*\*/')
        ->and($cfg['app_id'])->toBe('wx-test-123')
        ->and($res['is_configured'])->toBeTrue();

    // 留空不覆盖：只改 app_id，敏感键保留
    $this->putJson('/api/admin/payment-channels/wechat', [
        'config' => ['app_id' => 'wx-test-456', 'api_v3_key' => '', 'merchant_private_key' => '****'],
    ], $this->adminAuth)->assertOk();

    $cfg2 = $this->getJson('/api/admin/payment-channels/wechat', $this->adminAuth)->json('data.config');
    expect($cfg2['app_id'])->toBe('wx-test-456')
        ->and($cfg2['has_api_v3_key'])->toBeTrue()
        ->and($cfg2['has_merchant_private_key'])->toBeTrue();

    // 库里确为密文（非明文）
    $raw = app(PaymentChannelService::class)->find('wechat')->config;
    expect($raw['api_v3_key'])->not->toBe('0123456789abcdef0123456789abcdef')
        ->and(\Illuminate\Support\Facades\Crypt::decryptString($raw['api_v3_key']))->toBe('0123456789abcdef0123456789abcdef');
});

test('启停与连接测试', function () {
    $this->postJson('/api/admin/payment-channels/wechat/toggle', ['enabled' => false], $this->adminAuth)
        ->assertOk()->assertJsonPath('data.enabled', false);

    // 未配置齐全时测试连接返回 ok=false 但不报错
    $this->postJson('/api/admin/payment-channels/wechat/test', [], $this->adminAuth)
        ->assertOk()->assertJsonPath('data.ok', false);

    // mock 渠道已配置齐全（沙箱默认），测试连接成功
    $this->postJson('/api/admin/payment-channels/mock/test', [], $this->adminAuth)
        ->assertOk()->assertJsonPath('data.ok', true);
});

test('变更写入操作日志且不记明文', function () {
    $this->putJson('/api/admin/payment-channels/wechat', [
        'config' => ['app_id' => 'wx-log-test'],
    ], $this->adminAuth)->assertOk();

    $log = \App\Models\SysOperationLog::query()
        ->where('module', 'payment_channel')
        ->where('action', 'update')
        ->latest('id')->first();

    expect($log)->not->toBeNull();
    $content = json_decode((string) $log->content, true);
    expect($content['changed_fields'])->toContain('app_id')
        ->and($log->content)->not->toContain('0123456789abcdef');
});
