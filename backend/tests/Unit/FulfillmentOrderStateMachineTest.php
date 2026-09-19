<?php

use App\Models\FulfillmentOrder;

/**
 * 发货单状态机（WMS 计划 P1 / F2）
 *
 * 状态机是履约链路的安全底线：任何非法流转都必须被 `canTransitTo()` 挡住
 * （业务层再由 `FulfillmentOrderService::transitionTo()` 转成 40009/409）。
 * 这里做**全矩阵断言**——不是抽样，而是「所有合法组合为真 + 所有非法组合为假」，
 * 以后往矩阵里加边/删边都会立刻反映到本用例。
 */

/** 纯内存构造（不落库，状态机是模型上的静态逻辑） */
function fo(string $status): FulfillmentOrder
{
    return new FulfillmentOrder(['status' => $status]);
}

test('状态常量与中文标签一一对应（11 个状态无遗漏）', function () {
    expect(FulfillmentOrder::STATUS_LABELS)->toHaveCount(11)
        ->and(FulfillmentOrder::TRANSITIONS)->toHaveCount(11);

    foreach (FulfillmentOrder::STATUS_LABELS as $status => $label) {
        expect(is_string($status))->toBeTrue()
            ->and($label)->not->toBe('')
            ->and(array_key_exists($status, FulfillmentOrder::TRANSITIONS))->toBeTrue();
    }
});

test('全矩阵：矩阵内每条流转均合法', function () {
    foreach (FulfillmentOrder::TRANSITIONS as $from => $targets) {
        foreach ($targets as $to) {
            expect(fo($from)->canTransitTo($to))->toBeTrue("{$from} → {$to} 应为合法流转");
        }
    }
});

test('全矩阵：矩阵外的组合一律非法', function () {
    $all = array_keys(FulfillmentOrder::TRANSITIONS);

    foreach ($all as $from) {
        foreach ($all as $to) {
            if ($from === $to) {
                continue; // 自环不在矩阵内，下面单独断言
            }
            $expected = in_array($to, FulfillmentOrder::TRANSITIONS[$from] ?? [], true);
            expect(fo($from)->canTransitTo($to))->toBe($expected, "{$from} → {$to} 合法性不符合矩阵");
        }
    }
});

test('任何状态都不允许流转到自身', function () {
    foreach (array_keys(FulfillmentOrder::TRANSITIONS) as $status) {
        expect(fo($status)->canTransitTo($status))->toBeFalse("{$status} 不应允许自环");
    }
});

test('终态 completed / cancelled 无任何出边', function () {
    expect(FulfillmentOrder::TRANSITIONS[FulfillmentOrder::STATUS_COMPLETED])->toBe([])
        ->and(FulfillmentOrder::TRANSITIONS[FulfillmentOrder::STATUS_CANCELLED])->toBe([]);

    foreach (array_keys(FulfillmentOrder::TRANSITIONS) as $to) {
        expect(fo(FulfillmentOrder::STATUS_COMPLETED)->canTransitTo($to))->toBeFalse()
            ->and(fo(FulfillmentOrder::STATUS_CANCELLED)->canTransitTo($to))->toBeFalse();
    }
});

test('已发货后不再允许取消（只能往 completed 走）', function () {
    $shipped = fo(FulfillmentOrder::STATUS_SHIPPED);

    expect($shipped->canTransitTo(FulfillmentOrder::STATUS_COMPLETED))->toBeTrue()
        ->and($shipped->canTransitTo(FulfillmentOrder::STATUS_CANCELLED))->toBeFalse()
        ->and($shipped->canTransitTo(FulfillmentOrder::STATUS_PUSHED))->toBeFalse();
});

test('推送失败与建单异常都能回到待推送（人工修复后重推）', function () {
    expect(fo(FulfillmentOrder::STATUS_PUSH_FAILED)->canTransitTo(FulfillmentOrder::STATUS_PENDING_PUSH))->toBeTrue()
        ->and(fo(FulfillmentOrder::STATUS_EXCEPTION)->canTransitTo(FulfillmentOrder::STATUS_PENDING_PUSH))->toBeTrue()
        ->and(fo(FulfillmentOrder::STATUS_CREATED)->canTransitTo(FulfillmentOrder::STATUS_PENDING_PUSH))->toBeTrue();
});

test('推送失败不能直接跳到已推送（必须重新经过推送中）', function () {
    expect(fo(FulfillmentOrder::STATUS_PUSH_FAILED)->canTransitTo(FulfillmentOrder::STATUS_PUSHED))->toBeFalse()
        ->and(fo(FulfillmentOrder::STATUS_EXCEPTION)->canTransitTo(FulfillmentOrder::STATUS_PUSHED))->toBeFalse();
});

test('未打包前不允许直接标记完成（必须经已发货）', function () {
    expect(fo(FulfillmentOrder::STATUS_PACKED)->canTransitTo(FulfillmentOrder::STATUS_COMPLETED))->toBeFalse()
        ->and(fo(FulfillmentOrder::STATUS_PUSHED)->canTransitTo(FulfillmentOrder::STATUS_COMPLETED))->toBeFalse();
});

test('未知状态没有任何出边，也不接受任何流转', function () {
    $unknown = fo('not_a_status');

    foreach (array_keys(FulfillmentOrder::TRANSITIONS) as $to) {
        expect($unknown->canTransitTo($to))->toBeFalse();
    }
    expect(fo(FulfillmentOrder::STATUS_PUSHED)->canTransitTo('not_a_status'))->toBeFalse();
});

test('可取消白名单由流转矩阵推导，且随矩阵保持同步', function () {
    // 不变量：可取消 ⇔ 矩阵里存在 → cancelled 的边
    $expected = [];
    foreach (FulfillmentOrder::TRANSITIONS as $from => $targets) {
        if (in_array(FulfillmentOrder::STATUS_CANCELLED, $targets, true)) {
            $expected[] = $from;
        }
    }

    expect(FulfillmentOrder::cancellableStates())->toBe($expected)
        // 回归点：推送失败 / 建单异常也必须可取消（曾因手工白名单漂移被漏掉）
        ->and(FulfillmentOrder::cancellableStates())->toContain(
            FulfillmentOrder::STATUS_PUSH_FAILED,
            FulfillmentOrder::STATUS_EXCEPTION,
        );

    foreach ([
        FulfillmentOrder::STATUS_PACKED,
        FulfillmentOrder::STATUS_SHIPPED,
        FulfillmentOrder::STATUS_COMPLETED,
        FulfillmentOrder::STATUS_CANCELLED,
    ] as $status) {
        expect(in_array($status, FulfillmentOrder::cancellableStates(), true))->toBeFalse();
    }
});

test('可推送白名单只含待推/失败/异常态', function () {
    expect(FulfillmentOrder::PUSHABLE)->toBe([
        FulfillmentOrder::STATUS_CREATED,
        FulfillmentOrder::STATUS_PENDING_PUSH,
        FulfillmentOrder::STATUS_PUSH_FAILED,
        FulfillmentOrder::STATUS_EXCEPTION,
    ]);

    foreach ([
        FulfillmentOrder::STATUS_PUSHING,
        FulfillmentOrder::STATUS_PUSHED,
        FulfillmentOrder::STATUS_SHIPPED,
        FulfillmentOrder::STATUS_CANCELLED,
    ] as $status) {
        expect(in_array($status, FulfillmentOrder::PUSHABLE, true))->toBeFalse();
    }
});

test('isOutbounded 覆盖打包及之后的状态', function () {
    expect(fo(FulfillmentOrder::STATUS_PACKED)->isOutbounded())->toBeTrue()
        ->and(fo(FulfillmentOrder::STATUS_SHIPPED)->isOutbounded())->toBeTrue()
        ->and(fo(FulfillmentOrder::STATUS_COMPLETED)->isOutbounded())->toBeTrue()
        ->and(fo(FulfillmentOrder::STATUS_PICKING)->isOutbounded())->toBeFalse()
        ->and(fo(FulfillmentOrder::STATUS_PUSHED)->isOutbounded())->toBeFalse();
});

test('statusLabel 取中文，未知状态回落原值', function () {
    expect(fo(FulfillmentOrder::STATUS_PENDING_PUSH)->statusLabel())->toBe('待推送')
        ->and(fo(FulfillmentOrder::STATUS_PUSH_FAILED)->statusLabel())->toBe('推送失败')
        ->and(fo('weird')->statusLabel())->toBe('weird');
});
