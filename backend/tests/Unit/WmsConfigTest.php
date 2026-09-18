<?php

use App\Models\WmsConfig;
use App\Models\Warehouse;
use App\Services\Wms\WmsConfigService;
use App\Support\WmsMappingMode;
use App\Support\WmsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * WMS 配置模型与服务（WMS 计划 P0 / §4.1）
 *
 * 关注凭证安全不变量（加解密往返、掩码、密文不出口）与回调地址生成。
 */
function wmsWarehouse(string $code = 'WH_T1'): Warehouse
{
    return Warehouse::create(['code' => $code, 'name' => '测试仓'.$code, 'status' => 1]);
}

function wmsConfig(Warehouse $warehouse, array $attrs = []): WmsConfig
{
    return WmsConfig::create(array_merge([
        'warehouse_id' => $warehouse->id,
        'provider' => WmsProvider::CAINIAO,
        'enabled' => true,
        'auto_push' => true,
        'auto_push_return' => true,
        'push_retry_times' => 3,
        'sku_mapping_mode' => WmsMappingMode::SAME,
        'api_env' => 'sandbox',
        'callback_token' => str_repeat('t', 32),
    ], $attrs));
}

// WMS-U-01 凭证加解密往返：写明文 → 库内存密文 → 读回明文
test('凭证写入落库为密文且可读回明文', function () {
    $config = wmsConfig(wmsWarehouse());
    $config->app_secret = 'super-secret-value';
    $config->access_token = 'token-abc-123';
    $config->save();

    $raw = DB::table('wms_configs')->where('id', $config->id)->first();

    // 落库为密文：既不是明文，也不是空
    expect($raw->app_secret_enc)->not->toBe('super-secret-value')
        ->and($raw->app_secret_enc)->not->toBeEmpty()
        ->and($raw->access_token_enc)->not->toBe('token-abc-123');

    // 读回为明文（模型 accessor 解密）
    $fresh = WmsConfig::find($config->id);
    expect($fresh->app_secret)->toBe('super-secret-value')
        ->and($fresh->access_token)->toBe('token-abc-123')
        ->and($fresh->hasAppSecret())->toBeTrue()
        ->and($fresh->hasAccessToken())->toBeTrue();
});

// WMS-U-02 掩码格式：`****` + 末 4 位；未配置返回 null
test('凭证掩码为 **** + 末四位，未配置返回 null', function () {
    $config = wmsConfig(wmsWarehouse());
    $config->app_secret = 'abcdefgh1234';
    $config->save();

    $fresh = WmsConfig::find($config->id);

    expect($fresh->maskedAppSecret())->toBe('****1234')
        // 掩码不泄露长度之外的信息
        ->and($fresh->maskedAppSecret())->not->toContain('abcdefgh')
        // access_token 未配置 → null（区别于"已配置但短"）
        ->and($fresh->maskedAccessToken())->toBeNull()
        ->and($fresh->hasAccessToken())->toBeFalse();
});

// WMS-U-03 密文字段不进入序列化（防止意外出口泄露）
test('模型序列化不包含凭证密文与明文', function () {
    $config = wmsConfig(wmsWarehouse());
    $config->app_secret = 'top-secret';
    $config->save();

    $array = WmsConfig::find($config->id)->toArray();

    expect($array)->not->toHaveKey('app_secret_enc')
        ->and($array)->not->toHaveKey('access_token_enc')
        ->and($array)->not->toHaveKey('app_secret')
        ->and(json_encode($array))->not->toContain('top-secret');
});

// WMS-U-04 回调地址生成：app.url + provider + callback_token
test('回调地址按 app.url 与仓库 token 生成', function () {
    config(['app.url' => 'https://shop.example.com']);

    $config = wmsConfig(wmsWarehouse(), ['callback_token' => str_repeat('a', 32)]);

    $url = app(WmsConfigService::class)->callbackUrl($config);

    expect($url)->toBe('https://shop.example.com/api/wms/callback/cainiao?token='.str_repeat('a', 32));

    // 去掉尾部斜杠的 app.url 也不会产生双斜杠
    config(['app.url' => 'https://shop.example.com/']);
    expect(app(WmsConfigService::class)->callbackUrl($config))
        ->toBe('https://shop.example.com/api/wms/callback/cainiao?token='.str_repeat('a', 32));
});

// WMS-U-05 callback_token 唯一约束
test('callback_token 数据库唯一', function () {
    $warehouseA = wmsWarehouse('WH_A');
    $warehouseB = wmsWarehouse('WH_B');

    wmsConfig($warehouseA, ['callback_token' => str_repeat('z', 32)]);

    expect(fn () => wmsConfig($warehouseB, ['callback_token' => str_repeat('z', 32)]))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});
