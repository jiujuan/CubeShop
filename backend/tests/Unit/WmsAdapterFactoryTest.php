<?php

use App\Exceptions\BusinessException;
use App\Models\WmsConfig;
use App\Models\Warehouse;
use App\Services\Wms\Adapters\CainiaoAdapter;
use App\Services\Wms\Adapters\MockAdapter;
use App\Services\Wms\Dto\InventoryQueryDto;
use App\Services\Wms\WmsAdapterFactory;
use App\Support\WmsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * WMS 适配器工厂（WMS 计划 P0 / §4.1；P2 / Step 5 改按凭证完备度解析）
 *
 * 锁定「provider × 凭证完备度」的解析规则，以及生产缺密钥时的 fail-closed。
 * P2 起 `api_env` 不再决定走不走真实网关——有凭证就走真链路，避免沙箱通了生产才发现。
 */
function wmsFactoryConfig(array $attrs = []): WmsConfig
{
    static $seq = 0;
    $seq++;

    $warehouse = Warehouse::create(['code' => 'WH_F'.$seq, 'name' => '工厂仓', 'status' => 1]);

    return WmsConfig::create(array_merge([
        'warehouse_id' => $warehouse->id,
        'provider' => WmsProvider::CAINIAO,
        'enabled' => true,
        'auto_push' => true,
        'auto_push_return' => true,
        'push_retry_times' => 3,
        'sku_mapping_mode' => 'same',
        'api_env' => 'sandbox',
        'callback_token' => str_repeat('f', 32).$seq,
    ], $attrs));
}

// WMS-F-01 菜鸟 + 沙箱 + 无凭证 → MockAdapter
test('菜鸟沙箱未配置凭证解析为 Mock 适配器', function () {
    $config = wmsFactoryConfig(['api_env' => 'sandbox', 'app_key' => null]);

    $adapter = app(WmsAdapterFactory::class)->make($config);

    expect($adapter)->toBeInstanceOf(MockAdapter::class)
        ->and($adapter->isMock())->toBeTrue()
        ->and($adapter->provider())->toBe(WmsProvider::MOCK);
});

// WMS-F-02 菜鸟 + 沙箱 + 凭证齐备 → CainiaoAdapter（P2 起「有凭证就走真链路」）
test('菜鸟沙箱配置齐凭证时解析为真实菜鸟适配器', function () {
    $config = wmsFactoryConfig(['api_env' => 'sandbox', 'app_key' => 'test-app-key']);
    // app_secret 不可填充，须经虚拟属性写入（会加密落 app_secret_enc）
    $config->app_secret = 'test-app-secret';
    $config->save();

    $adapter = app(WmsAdapterFactory::class)->make($config);

    expect(app(WmsAdapterFactory::class)->shouldUseMock($config))->toBeFalse()
        ->and($adapter)->toBeInstanceOf(CainiaoAdapter::class)
        ->and($adapter->isMock())->toBeFalse()
        ->and($adapter->provider())->toBe(WmsProvider::CAINIAO);
});

// WMS-F-02B 生产 + 凭证齐备 → 也是 CainiaoAdapter（沙箱与生产同一实现，仅网关地址不同）
test('菜鸟生产环境凭证齐备时同样解析为真实菜鸟适配器', function () {
    $config = wmsFactoryConfig(['api_env' => 'prod', 'app_key' => 'test-app-key']);
    $config->app_secret = 'test-app-secret';
    $config->save();

    expect(app(WmsAdapterFactory::class)->make($config))->toBeInstanceOf(CainiaoAdapter::class);
});

// WMS-F-02C 半配置（只有 AppKey 无 AppSecret）也算凭证不齐 → Mock
test('只配 AppKey 未配 AppSecret 时回落 Mock（半配置视为未配置）', function () {
    $config = wmsFactoryConfig(['api_env' => 'sandbox', 'app_key' => 'only-key']);

    expect(app(WmsAdapterFactory::class)->shouldUseMock($config))->toBeTrue()
        ->and(app(WmsAdapterFactory::class)->make($config))->toBeInstanceOf(MockAdapter::class);
});

// WMS-F-03 菜鸟 + 生产 + 缺密钥 → 返回 Mock 但调用时 fail-closed
test('菜鸟生产缺密钥返回 Mock 但调用时 fail-closed', function () {
    $config = wmsFactoryConfig(['api_env' => 'prod', 'app_key' => null]);

    $factory = app(WmsAdapterFactory::class);
    $adapter = $factory->make($config);

    expect($factory->shouldUseMock($config))->toBeTrue()
        ->and($adapter->isMock())->toBeTrue();

    // fail-closed：生产环境绝不静默降级为"假成功"
    expect(fn () => $adapter->queryInventory(new InventoryQueryDto($config->warehouse_id, ['SKU-1'])))
        ->toThrow(BusinessException::class);
});

// WMS-F-04 京东云仓（P8 未排期）抛错
test('京东云仓解析抛错提示 P8', function () {
    $config = wmsFactoryConfig(['provider' => WmsProvider::JD_CLOUD]);

    expect(fn () => app(WmsAdapterFactory::class)->make($config))
        ->toThrow(BusinessException::class);
});

// WMS-F-05 未知 provider 抛错
test('未知服务商抛错', function () {
    $config = wmsFactoryConfig(['provider' => 'not_exists']);

    expect(fn () => app(WmsAdapterFactory::class)->make($config))
        ->toThrow(BusinessException::class);
});
