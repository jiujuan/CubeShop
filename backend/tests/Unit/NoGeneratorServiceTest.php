<?php

use App\Services\Common\NoGeneratorService;
use App\Services\Common\NoGeneratorService as NoGen;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('单号格式：前缀 + 日期 + 6 位序列', function (string $prefix) {
    $no = app(NoGeneratorService::class)->generate($prefix);

    expect($no)->toStartWith($prefix)
        ->and(strlen($no))->toBe(strlen($prefix) + 8 + 6)
        ->and(substr($no, strlen($prefix), 8))->toBe(now()->format('Ymd'));
})->with([
    '订单' => [NoGen::PREFIX_ORDER],
    '支付' => [NoGen::PREFIX_PAYMENT],
    '退款' => [NoGen::PREFIX_REFUND],
    '充值' => [NoGen::PREFIX_RECHARGE],
]);

test('generateRechargeNo 使用 RC 前缀', function () {
    expect(app(NoGeneratorService::class)->generateRechargeNo())->toStartWith(NoGen::PREFIX_RECHARGE);
});

test('批量生成 500 个单号无重复', function () {
    $service = app(NoGeneratorService::class);
    $set = [];
    for ($i = 0; $i < 500; $i++) {
        $set[] = $service->generateOrderNo();
    }

    expect(count($set))->toBe(count(array_unique($set)));
});
