<?php

use App\Models\ExpressCompany;
use App\Support\CarrierCode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 承运商编码双向解析（物流分层三期）
 *
 * 背景：同一个快递公司在平台 / 快递100 / 菜鸟奇门三套体系里各有编码，
 * 历史上靠单列 `channel_code` 承载，导致 WMS 回传编码原样落库后快递100 只能命中一半。
 * 详见 docs/design/CubeShop_Logistics_Layering_and_CarrierCode_v1.0.md §4。
 *
 * 这些用例同时是回归护栏：若有人把解析改回「直接读 channel_code」，
 * TC-CC-04（渠道隔离）会立刻变红。
 */
beforeEach(function () {
    CarrierCode::flushCache();
});

/** 建一家字典记录 */
function ccCompany(string $code, ?string $channelCode = null, ?array $carrierCodes = null): ExpressCompany
{
    return ExpressCompany::create([
        'code' => $code,
        'name' => '测试快递-'.$code,
        'channel_code' => $channelCode,
        'carrier_codes' => $carrierCodes,
        'sort' => 1,
        'status' => 1,
    ]);
}

test('TC-CC-01 正查优先取 carrier_codes 的精确配置', function () {
    ccCompany('SF', 'legacy-sf', ['kuaidi100' => 'shunfeng', 'cainiao' => 'SF']);

    expect(CarrierCode::forChannel('SF', CarrierCode::KUAIDI100))->toBe('shunfeng')
        ->and(CarrierCode::forChannel('SF', CarrierCode::CAINIAO))->toBe('SF');
});

test('TC-CC-02 正查回落：carrier_codes 未配时仍认历史的 channel_code（仅快递100）', function () {
    ccCompany('SF', 'shunfeng', null);

    expect(CarrierCode::forChannel('SF', CarrierCode::KUAIDI100))->toBe('shunfeng');
});

test('TC-CC-03 正查兜底：完全无映射时返回平台码本身', function () {
    ccCompany('NT', null, null);

    expect(CarrierCode::forChannel('NT', CarrierCode::KUAIDI100))->toBe('NT')
        ->and(CarrierCode::forChannel('UNKNOWN', CarrierCode::CAINIAO))->toBe('UNKNOWN');
});

test('TC-CC-04 渠道隔离：channel_code 不得污染非快递100 渠道', function () {
    // 关键回归点：channel_code 语义上只属于快递100。若它参与 cainiao 回落，
    // 菜鸟侧会拿到 `jd` 这类快递100 编码，正是那枚被清除的方向反转 bug。
    ccCompany('JD', 'jd', null);

    expect(CarrierCode::forChannel('JD', CarrierCode::CAINIAO))->toBe('JD')
        ->and(CarrierCode::forChannel('JD', CarrierCode::KUAIDI100))->toBe('jd');
});

test('TC-CC-05 正查空编码返回空串（调用方据此判定失败）', function () {
    expect(CarrierCode::forChannel('', CarrierCode::KUAIDI100))->toBe('')
        ->and(CarrierCode::forChannel('   ', CarrierCode::CAINIAO))->toBe('');
});

test('TC-CC-06 反查命中三种候选并按优先级归一', function () {
    ccCompany('SF', 'sf-old', ['kuaidi100' => 'shunfeng', 'cainiao' => 'SF']);

    // carrier_codes.cainiao 命中
    expect(CarrierCode::fromChannel('SF', CarrierCode::CAINIAO))->toBe('SF');
    // carrier_codes.kuaidi100 命中 → 反查到平台码
    expect(CarrierCode::fromChannel('shunfeng', CarrierCode::KUAIDI100))->toBe('SF');
});

test('TC-CC-07 反查命中平台码本身（仓方回传的就是平台码）', function () {
    ccCompany('SF', 'shunfeng', ['kuaidi100' => 'shunfeng']);

    expect(CarrierCode::fromChannel('SF', CarrierCode::CAINIAO))->toBe('SF');
});

test('TC-CC-08 反查忽略大小写', function () {
    ccCompany('SF', 'shunfeng', ['kuaidi100' => 'shunfeng']);

    expect(CarrierCode::fromChannel('sf', CarrierCode::CAINIAO))->toBe('SF')
        ->and(CarrierCode::fromChannel('SHUNFENG', CarrierCode::KUAIDI100))->toBe('SF');
});

test('TC-CC-09 反查未命中返回 null（调用方保留原值并告警）', function () {
    ccCompany('SF', 'shunfeng', ['kuaidi100' => 'shunfeng']);

    expect(CarrierCode::fromChannel('OTHER', CarrierCode::CAINIAO))->toBeNull()
        ->and(CarrierCode::fromChannel('顺丰速运', CarrierCode::CAINIAO))->toBeNull();
});

test('TC-CC-10 反查空串返回 null', function () {
    expect(CarrierCode::fromChannel('', CarrierCode::CAINIAO))->toBeNull();
});

test('TC-CC-11 反查跨渠道兜底：任意已知标识都能识别（与正查的严格隔离相反）', function () {
    ccCompany('SF', 'shunfeng', ['kuaidi100' => 'shunfeng']);

    // 正查严格隔离（见 TC-CC-04），反查刻意宽松：
    // `shunfeng` 虽登记在快递100 名下，菜鸟/京东渠道回传它时同样应认出是顺丰。
    expect(CarrierCode::fromChannel('shunfeng', CarrierCode::CAINIAO))->toBe('SF')
        ->and(CarrierCode::fromChannel('shunfeng', CarrierCode::JD_CLOUD))->toBe('SF')
        ->and(CarrierCode::fromChannel('SF', CarrierCode::CAINIAO))->toBe('SF');
});

test('TC-CC-12 flushCache 让字典改动即时生效', function () {
    ccCompany('SF', 'shunfeng', null);
    expect(CarrierCode::forChannel('SF', CarrierCode::KUAIDI100))->toBe('shunfeng');

    // 模拟后台改字典：不 flush 会读到旧值
    ExpressCompany::where('code', 'SF')->update(['carrier_codes' => json_encode(['kuaidi100' => 'shunfeng-new'])]);
    expect(CarrierCode::forChannel('SF', CarrierCode::KUAIDI100))->toBe('shunfeng');

    CarrierCode::flushCache();
    expect(CarrierCode::forChannel('SF', CarrierCode::KUAIDI100))->toBe('shunfeng-new');
});

test('TC-CC-13 渠道清单暴露给管理端用于渲染输入框', function () {
    expect(CarrierCode::CHANNELS)->toHaveKeys([CarrierCode::KUAIDI100, CarrierCode::CAINIAO, CarrierCode::JD_CLOUD]);
});
