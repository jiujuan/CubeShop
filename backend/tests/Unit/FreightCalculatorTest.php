<?php

use App\Services\Shipping\FreightCalculator;

/**
 * FreightCalculator 纯函数单测（不依赖 DB）
 *
 * 口径：A 组间取最大 / B region 按省 code / C 未命中拒单 / D 0 重只收首费。
 */

function fl(array $overrides = []): array
{
    return array_merge([
        'template_id' => null, 'weight_g' => 0, 'quantity' => 1, 'price' => '10.00',
    ], $overrides);
}

const FIXED_RULES = ['amount' => '8.00'];

const DEFAULT_SPEC = ['mode' => 'fixed', 'rules' => ['amount' => '10.00']];

const WEIGHT_RULES = ['first_weight_g' => 1000, 'first_fee' => '8.00', 'step_weight_g' => 1000, 'step_fee' => '2.00'];

test('fixed 模式：固定金额', function () {
    $r = FreightCalculator::calculate(
        [fl(['template_id' => 1])],
        ['1' => ['mode' => 'fixed', 'rules' => FIXED_RULES]],
        DEFAULT_SPEC, '0.00',
    );

    expect($r->freightAmount)->toBe('8.00')->and($r->notSupport)->toBeFalse();
});

test('未绑定模板走全局默认规则（向后兼容）', function () {
    $r = FreightCalculator::calculate(
        [fl()], [],
        DEFAULT_SPEC, '0.00',
    );

    expect($r->freightAmount)->toBe('10.00');
});

test('模板缺失降级为默认规则', function () {
    $r = FreightCalculator::calculate(
        [fl(['template_id' => 99])], // 99 不存在
        [],
        DEFAULT_SPEC, '0.00',
    );

    expect($r->freightAmount)->toBe('10.00')->and($r->detail[0]['source'])->toBe('fixed');
});

test('weight 模式：首重内只收首费', function () {
    $r = FreightCalculator::calculate(
        [fl(['template_id' => 1, 'weight_g' => 800])],
        ['1' => ['mode' => 'weight', 'rules' => WEIGHT_RULES]],
        DEFAULT_SPEC, '0.00',
    );

    expect($r->freightAmount)->toBe('8.00');
});

test('weight 模式：超出按 ceil 进位续重（1500g=1 个续重档）', function () {
    $r = FreightCalculator::calculate(
        [fl(['template_id' => 1, 'weight_g' => 1500])],
        ['1' => ['mode' => 'weight', 'rules' => WEIGHT_RULES]],
        DEFAULT_SPEC, '0.00',
    );

    expect($r->freightAmount)->toBe('10.00'); // 8 + 1×2
});

test('weight 模式：组内多商品重量累加', function () {
    $r = FreightCalculator::calculate(
        [
            fl(['template_id' => 1, 'weight_g' => 600, 'quantity' => 2]),
            fl(['template_id' => 1, 'weight_g' => 300, 'quantity' => 1]),
        ],
        ['1' => ['mode' => 'weight', 'rules' => WEIGHT_RULES]],
        DEFAULT_SPEC, '0.00',
    );

    expect($r->freightAmount)->toBe('10.00'); // 600×2+300=1500g → 8 + 1×2
});

test('weight 模式：重量为 0 只收首费（决策 D）', function () {
    $r = FreightCalculator::calculate(
        [fl(['template_id' => 1, 'weight_g' => 0])],
        ['1' => ['mode' => 'weight', 'rules' => WEIGHT_RULES]],
        DEFAULT_SPEC, '0.00',
    );

    expect($r->freightAmount)->toBe('8.00');
});

test('region 模式：省 code 命中 areas 用区域价', function () {
    $rules = [
        'default' => ['amount' => '8.00'],
        'areas' => [['provinces' => ['540000', '650000'], 'amount' => '20.00']],
    ];
    $r = FreightCalculator::calculate(
        [fl(['template_id' => 1])],
        ['1' => ['mode' => 'region', 'rules' => $rules]],
        DEFAULT_SPEC, '0.00', '650000',
    );

    expect($r->freightAmount)->toBe('20.00')->and($r->detail[0]['source'])->toBe('region_area');
});

test('region 模式：未命中 areas 走 default', function () {
    $rules = [
        'default' => ['amount' => '8.00'],
        'areas' => [['provinces' => ['540000'], 'amount' => '20.00']],
    ];
    $r = FreightCalculator::calculate(
        [fl(['template_id' => 1])],
        ['1' => ['mode' => 'region', 'rules' => $rules]],
        DEFAULT_SPEC, '0.00', '440000',
    );

    expect($r->freightAmount)->toBe('8.00')->and($r->detail[0]['source'])->toBe('region_default');
});

test('region 模式：区域条目可按重量口径计费', function () {
    $rules = [
        'areas' => [['provinces' => ['540000'], 'first_weight_g' => 1000, 'first_fee' => '15.00', 'step_weight_g' => 1000, 'step_fee' => '5.00']],
        'default' => ['amount' => '8.00'],
    ];
    $r = FreightCalculator::calculate(
        [fl(['template_id' => 1, 'weight_g' => 2500])],
        ['1' => ['mode' => 'region', 'rules' => $rules]],
        DEFAULT_SPEC, '0.00', '540000',
    );

    expect($r->freightAmount)->toBe('25.00'); // 15 + 2×5
});

test('region 模式：未命中且无 default → not_support（决策 C）', function () {
    $rules = ['areas' => [['provinces' => ['540000'], 'amount' => '20.00']]];
    $r = FreightCalculator::calculate(
        [fl(['template_id' => 1])],
        ['1' => ['mode' => 'region', 'rules' => $rules]],
        DEFAULT_SPEC, '0.00', '440000',
    );

    expect($r->notSupport)->toBeTrue()->and($r->freightAmount)->toBe('0.00');
});

test('region 模式：未传地址但有 default → 走 default', function () {
    $rules = ['default' => ['amount' => '8.00'], 'areas' => [['provinces' => ['540000'], 'amount' => '20.00']]];
    $r = FreightCalculator::calculate(
        [fl(['template_id' => 1])],
        ['1' => ['mode' => 'region', 'rules' => $rules]],
        DEFAULT_SPEC, '0.00', null,
    );

    expect($r->freightAmount)->toBe('8.00');
});

test('多模板混合：组间取最大值（决策 A）', function () {
    $r = FreightCalculator::calculate(
        [
            fl(['template_id' => 1, 'price' => '50.00']),
            fl(['template_id' => 2, 'price' => '50.00']),
        ],
        [
            '1' => ['mode' => 'fixed', 'rules' => ['amount' => '8.00']],
            '2' => ['mode' => 'fixed', 'rules' => ['amount' => '15.00']],
        ],
        DEFAULT_SPEC, '0.00',
    );

    expect($r->freightAmount)->toBe('15.00')
        ->and($r->detail)->toHaveCount(2);
});

test('包邮门槛：满额运费归 0', function () {
    $r = FreightCalculator::calculate(
        [fl(['price' => '99.00'])],
        [],
        DEFAULT_SPEC, '99.00',
    );

    expect($r->freightAmount)->toBe('0.00')->and($r->freeShipping)->toBeTrue()->and($r->freeShippingGap)->toBeNull();
});

test('包邮门槛：未满返回差额 gap', function () {
    $r = FreightCalculator::calculate(
        [fl(['price' => '59.00', 'quantity' => 1])],
        [],
        DEFAULT_SPEC, '99.00',
    );

    expect($r->freightAmount)->toBe('10.00')
        ->and($r->freeShipping)->toBeFalse()
        ->and($r->freeShippingGap)->toBe('40.00');
});

test('包邮门槛为 0：不包邮也无 gap', function () {
    $r = FreightCalculator::calculate(
        [fl(['price' => '999.00'])],
        [],
        DEFAULT_SPEC, '0.00',
    );

    expect($r->freeShipping)->toBeFalse()->and($r->freeShippingGap)->toBeNull();
});

test('任一分组不可配送 → 整单 not_support', function () {
    $r = FreightCalculator::calculate(
        [
            fl(['template_id' => 1]),
            fl(['template_id' => 2]),
        ],
        [
            '1' => ['mode' => 'fixed', 'rules' => ['amount' => '8.00']],
            '2' => ['mode' => 'region', 'rules' => ['areas' => [['provinces' => ['540000'], 'amount' => '20.00']]]],
        ],
        DEFAULT_SPEC, '0.00', '440000',
    );

    expect($r->notSupport)->toBeTrue();
});
