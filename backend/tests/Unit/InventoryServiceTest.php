<?php

use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(InventoryService::class);
    $this->sku = createTestSku(stock: 10, price: '10.00');
    $this->skuId = $this->sku->id;
});

// INV-U-01 锁定：可售→锁定，流水记录
test('lock 成功后可售减少、锁定增加并记录流水', function () {
    $this->service->lock($this->skuId, 3, 'order', null, '测试锁定');

    $inv = Inventory::where('sku_id', $this->skuId)->first();
    expect((int) $inv->stock)->toBe(7)
        ->and((int) $inv->locked_stock)->toBe(3)
        ->and((int) $inv->version)->toBe(1);

    $log = InventoryLog::where('sku_id', $this->skuId)->latest('id')->first();
    expect($log->change_type)->toBe('lock')
        ->and((int) $log->change_qty)->toBe(-3)
        ->and((int) $log->before_stock)->toBe(10)
        ->and((int) $log->after_stock)->toBe(7);
});

// INV-U-02 锁定不足：拒绝且不产生任何变更
test('lock 库存不足时拒绝且库存不变', function () {
    $this->service->lock($this->skuId, 11);
})->throws(App\Exceptions\BusinessException::class, '库存不足')
    ->group('inv');

test('lock 失败后库存与流水保持不变', function () {
    try {
        $this->service->lock($this->skuId, 11);
    } catch (App\Exceptions\BusinessException) {
    }

    $inv = Inventory::where('sku_id', $this->skuId)->first();
    expect((int) $inv->stock)->toBe(10)
        ->and((int) $inv->locked_stock)->toBe(0)
        ->and(InventoryLog::where('sku_id', $this->skuId)->count())->toBe(0);
});

// INV-U-03 非法数量
test('lock/ release / deduct 数量小于等于 0 拒绝', function () {
    $this->service->lock($this->skuId, 0);
})->throws(App\Exceptions\BusinessException::class);

test('release 数量为负拒绝', function () {
    $this->service->release($this->skuId, -1);
})->throws(App\Exceptions\BusinessException::class);

test('deduct 数量为 0 拒绝', function () {
    $this->service->deduct($this->skuId, 0);
})->throws(App\Exceptions\BusinessException::class);

// INV-U-04 释放：锁定→可售
test('release 后锁定减少、可售恢复', function () {
    $this->service->lock($this->skuId, 4, 'order');
    $this->service->release($this->skuId, 4, 'cancel');

    $inv = Inventory::where('sku_id', $this->skuId)->first();
    expect((int) $inv->stock)->toBe(10)
        ->and((int) $inv->locked_stock)->toBe(0);

    $types = InventoryLog::where('sku_id', $this->skuId)->orderBy('id')->pluck('change_type')->all();
    expect($types)->toBe(['lock', 'unlock']);
});

// INV-U-05 扣减：锁定直接减少，可售不变（支付成功语义）
test('deduct 后锁定减少且可售不变', function () {
    $this->service->lock($this->skuId, 5, 'order');
    $this->service->deduct($this->skuId, 5, 'order');

    $inv = Inventory::where('sku_id', $this->skuId)->first();
    expect((int) $inv->stock)->toBe(5)
        ->and((int) $inv->locked_stock)->toBe(0);

    $log = InventoryLog::where('sku_id', $this->skuId)->where('change_type', 'deduct')->first();
    expect((int) $log->change_qty)->toBe(-5);
});

// INV-U-06 调整：正向增加 / 负向防超卖
test('adjust 正向增加库存', function () {
    $after = $this->service->adjust($this->skuId, 5, null, '补货');
    expect($after)->toBe(15)
        ->and((int) Inventory::where('sku_id', $this->skuId)->value('stock'))->toBe(15);
});

test('adjust 负向扣到负数被拒绝', function () {
    $this->service->adjust($this->skuId, -11);
})->throws(App\Exceptions\BusinessException::class, '库存不足');

test('adjust 0 直接返回当前库存', function () {
    expect($this->service->adjust($this->skuId, 0))->toBe(10);
});

// INV-U-07 查询
test('getStock 与 isSufficient', function () {
    expect($this->service->getStock($this->skuId))->toBe(10)
        ->and($this->service->isSufficient($this->skuId, 10))->toBeTrue()
        ->and($this->service->isSufficient($this->skuId, 11))->toBeFalse()
        ->and($this->service->isSufficient($this->skuId, 0))->toBeFalse();
});

test('getStockMap 返回 sku 到库存映射', function () {
    $other = createTestSku(stock: 2);
    $map = $this->service->getStockMap([$this->skuId, $other->id]);
    expect($map)->toBe([$this->skuId => 10, $other->id => 2]);
});

// INV-U-08 lock→release→lock 链路一致性
test('锁定释放再锁定数量守恒', function () {
    $this->service->lock($this->skuId, 6);
    $this->service->release($this->skuId, 2);
    $this->service->lock($this->skuId, 1);

    $inv = Inventory::where('sku_id', $this->skuId)->first();
    expect((int) $inv->stock)->toBe(5)      // 10 - 6 + 2 - 1
        ->and((int) $inv->locked_stock)->toBe(5); // 6 - 2 + 1
});
