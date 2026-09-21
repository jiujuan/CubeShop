<?php

use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\Shipping;
use App\Models\ShippingPackage;
use App\Models\Warehouse;
use App\Services\Wms\Callback\Handlers\DeliveryOrderConfirmHandler;
use App\Support\CarrierCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/**
 * WMS 回传的承运商编码入站归一（物流分层三期）
 *
 * 修复前：仓方回传的 `logisticsCode` 原样写入 `shippings.company_code`，与自发货存的
 * 平台码（SF）语义不一致。快递100 解析时 `SF` 命中、`shunfeng` 碰巧对、`OTHER` 直接失败。
 *
 * 修复后：`DeliveryOrderConfirmHandler` 落库前先经 `CarrierCode::fromChannel()` 归一，
 * `shippings.company_code` 语义统一为平台码；未命中保留原值并告警，绝不阻断发货。
 */
beforeEach(function () {
    seedRoles();
    CarrierCode::flushCache();
    $this->seed(\Database\Seeders\ExpressCompanySeeder::class);

    $this->user = createTestUser('normusr'.bin2hex(random_bytes(3)));
});

/**
 * 构造待发货的 PUSHED 发货单。
 *
 * @param  array<string, mixed>  $foAttrs
 */
function normShipFo(array $foAttrs = []): FulfillmentOrder
{
    $warehouse = Warehouse::create([
        'code' => 'WH_NM_'.bin2hex(random_bytes(4)), 'name' => '归一测试仓', 'status' => 1,
    ]);

    $order = Order::create([
        'order_no' => 'CS'.now()->format('Ymd').random_int(1000000000, 9999999999),
        'user_id' => test()->user->id,
        'status' => Order::STATUS_PENDING_SHIP,
        'total_amount' => 100, 'pay_amount' => 100, 'discount_amount' => 0,
        'promotion_discount' => 0, 'freight_amount' => 0,
        'address_snapshot' => [
            'contact_name' => '李四', 'contact_phone' => '13900000000',
            'full_address' => '广东省深圳市南山区科技路 2 号',
        ],
        'warehouse_id' => $warehouse->id,
    ]);

    $fo = FulfillmentOrder::create(array_merge([
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'outbound_no' => 'FO'.now()->format('Ymd').random_int(1000000000, 9999999999),
        'warehouse_id' => $warehouse->id,
        'provider' => 'cainiao',
        'status' => FulfillmentOrder::STATUS_PUSHED,
        'buyer_info' => $order->address_snapshot,
        'extend' => [],
    ], $foAttrs));

    $fo->items()->create([
        'sku_id' => null,
        'platform_sku_code' => 'SKU-NM',
        'wms_sku_code' => 'W-NM',
        'product_name' => '归一测试商品',
        'qty' => 1,
        'shipped_qty' => 0,
    ]);

    return $fo->load('items');
}

/** 驱动一次 confirm 回传，返回落库的 shipping */
function normConfirm(FulfillmentOrder $fo, string $carrierCode, string $trackingNo): Shipping
{
    app(DeliveryOrderConfirmHandler::class)->handle($fo->fresh(), [
        'packages' => [[
            'carrier_code' => $carrierCode,
            'carrier_name' => '测试承运商',
            'tracking_no' => $trackingNo,
            'weight' => null,
            'items' => [],
            'sort' => 0,
        ]],
        'order' => [],
    ]);

    return Shipping::query()->where('order_id', $fo->order_id)->firstOrFail();
}

test('TC-NM-01 仓方回传平台码：原样归一且快递100 仍能解析', function () {
    $fo = normShipFo();
    $shipping = normConfirm($fo, 'SF', 'SF111');

    expect($shipping->company_code)->toBe('SF')
        ->and(CarrierCode::forChannel($shipping->company_code, CarrierCode::KUAIDI100))->toBe('shunfeng');
});

test('TC-NM-02 仓方回传快递100 编码：归一为平台码（修复前会漏命中）', function () {
    $fo = normShipFo();
    $shipping = normConfirm($fo, 'shunfeng', 'SF222');

    // 修复前这里会落 'shunfeng'，快递100 只是碰巧能用；现在语义统一
    expect($shipping->company_code)->toBe('SF');
});

test('TC-NM-03 仓方回传未知编码：保留原值并告警，不阻断发货', function () {
    Log::spy();

    $fo = normShipFo();
    $shipping = normConfirm($fo, 'OTHER', 'OTH333');

    expect($shipping->company_code)->toBe('OTHER');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($msg) => $msg === '[WMS] 回传承运商编码无法归一为平台码');
});

test('TC-NM-04 原文 Collections：$shipping_packages 保留仓方原始编码供溯源', function () {
    $fo = normShipFo();
    $shipping = normConfirm($fo, 'shunfeng', 'SF444');

    $package = ShippingPackage::query()->where('shipping_id', $shipping->id)->firstOrFail();

    // 主表已归一，包裹表保留仓方回传原值，便于对账时溯源
    expect($package->carrier_code)->toBe('shunfeng')
        ->and($shipping->company_code)->toBe('SF');
});

test('TC-NM-05 归一结果可被快递100 正确解析（端到端口径一致）', function () {
    foreach (['SF' => 'SF555', 'shunfeng' => 'SF666', 'YTO' => 'YTO777'] as $raw => $no) {
        $shipping = normConfirm(normShipFo(), $raw, $no);

        expect(CarrierCode::forChannel($shipping->company_code, CarrierCode::KUAIDI100))
            ->not->toBe('')
            ->and($shipping->company_code)->toBe(CarrierCode::fromChannel($raw, CarrierCode::CAINIAO));
    }
});

test('TC-NM-06 按单据 provider 选渠道（京东云仓接入后无需改代码）', function () {
    $fo = normShipFo(['provider' => 'jd_cloud']);
    $shipping = normConfirm($fo, 'JD', 'JD888');

    // jd_cloud 渠道下 JD 本身就是平台码，规则 3 命中
    expect($shipping->company_code)->toBe('JD');
});
