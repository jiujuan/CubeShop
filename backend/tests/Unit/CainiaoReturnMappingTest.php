<?php

use App\Exceptions\BusinessException;
use App\Models\Warehouse;
use App\Models\WmsConfig;
use App\Services\Wms\Adapters\Cainiao\Signature;
use App\Services\Wms\Dto\ReturnInboundDto;
use App\Services\Wms\WmsAdapterFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * 菜鸟 Adapter：退货入库 DTO → 奇门报文（WMS 计划 P4 / F4、Step 5，§7.4/§7.5）
 *
 * 与出库映射测试（CainiaoOutboundMappingTest）同思路：Http::fake() 截获真实报文，
 * 断言 `returnorder.create` / `returnorder.cancel` 的实际字段与幂等语义。
 */

beforeEach(function () {
    config([
        'wms.providers.cainiao.gateway.sandbox' => 'https://qimen.sandbox.test/gw',
        'wms.providers.cainiao.gateway.prod' => 'https://qimen.prod.test/gw',
    ]);
});

/** 建仓 + 配好凭证的菜鸟配置（凭证齐备 → 工厂走 CainiaoAdapter） */
function cnRetConfig(array $attrs = []): WmsConfig
{
    $warehouse = Warehouse::create(['code' => 'WH_CNRET_'.uniqid(), 'name' => '退货菜鸟仓', 'status' => 1]);

    $config = WmsConfig::create(array_merge([
        'warehouse_id' => $warehouse->id,
        'provider' => 'cainiao',
        'enabled' => true,
        'auto_push' => true,
        'auto_push_return' => true,
        'push_retry_times' => 3,
        'sku_mapping_mode' => 'same',
        'app_key' => 'cn-ret-key',
        'customer_id' => 'CUBE_OWNER',
        'warehouse_code' => 'CN-WH-RET',
        'api_env' => 'sandbox',
        'callback_token' => str_repeat('r', 20).uniqid(),
    ], $attrs));

    $config->app_secret = 'cn-ret-secret-value';
    $config->save();

    return $config->refresh();
}

/** 标准退货入库 DTO（两行商品） */
function cnRetDto(array $overrides = []): ReturnInboundDto
{
    return new ReturnInboundDto(
        warehouseId: 1,
        bizNo: 'RI20260920000001',
        items: $overrides['items'] ?? [
            ['sku_code' => 'SKU-1', 'wms_sku_code' => 'W-CN-1', 'quantity' => 2, 'product_name' => '退货商品一', 'barcode' => '6901234567890'],
            ['sku_code' => 'SKU-2', 'wms_sku_code' => 'W-CN-2', 'quantity' => 1, 'product_name' => '退货商品二', 'barcode' => null],
        ],
        refundNo: 'RF2026092012345678901',
        orderNo: 'CS20260920000001',
        returnReason: '七天无理由退货',
    );
}

/** 捕获最近一次出站请求 */
function cnRetCaptured(): array
{
    $pair = Http::recorded()->last();
    expect($pair)->not->toBeNull();

    [$request] = $pair;
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return [
        'query' => $query,
        'body' => (array) json_decode($request->body(), true),
        'raw_body' => $request->body(),
    ];
}

test('TC-CNR-01 returnorder.create：主节点字段完整映射（§7.4）', function () {
    Http::fake(['*' => Http::response(json_encode([
        'response' => ['flag' => 'success', 'returnOrderId' => 'CN-RET-1'],
    ]), 200)]);

    $result = app(WmsAdapterFactory::class)->make(cnRetConfig())->createReturnInbound(cnRetDto());

    expect($result->success)->toBeTrue();

    $body = cnRetCaptured()['body'];

    expect($body['returnOrder']['returnOrderCode'])->toBe('RI20260920000001')
        ->and($body['returnOrder']['preDeliveryOrderCode'])->toBe('CS20260920000001')
        ->and($body['returnOrder']['warehouseCode'])->toBe('CN-WH-RET')
        ->and($body['returnOrder']['ownerCode'])->toBe('CUBE_OWNER')
        ->and($body['returnOrder']['returnReason'])->toBe('七天无理由退货');
});

test('TC-CNR-02 行项目：orderLines 与 returnOrder 平级，行号从 1 起、planQty=应退数量', function () {
    Http::fake(['*' => Http::response(json_encode(['response' => ['flag' => 'success']]), 200)]);

    app(WmsAdapterFactory::class)->make(cnRetConfig())->createReturnInbound(cnRetDto());

    $lines = cnRetCaptured()['body']['orderLines']['orderLine'];

    expect($lines)->toHaveCount(2)
        ->and($lines[0]['orderLineNo'])->toBe('1')
        ->and($lines[0]['itemCode'])->toBe('W-CN-1')
        ->and($lines[0]['planQty'])->toBe(2)
        ->and($lines[0]['itemName'])->toBe('退货商品一')
        ->and($lines[0]['barCode'])->toBe('6901234567890')
        ->and($lines[0]['ownerCode'])->toBe('CUBE_OWNER')
        ->and($lines[1]['planQty'])->toBe(1)
        ->and($lines[1])->not->toHaveKey('barCode');
});

test('TC-CNR-03 成功回执：returnOrderId → wms_order_no（平台记 wms_inbound_no）', function () {
    Http::fake(['*' => Http::response(json_encode([
        'response' => ['flag' => 'success', 'returnOrderId' => 'CN-RET-7777'],
    ]), 200)]);

    $result = app(WmsAdapterFactory::class)->make(cnRetConfig())->createReturnInbound(cnRetDto());

    expect($result->success)->toBeTrue()
        ->and($result->idempotent)->toBeFalse()
        ->and($result->data['wms_order_no'])->toBe('CN-RET-7777');
});

test('TC-CNR-04 幂等回执（S07 / RETURN_ORDER_EXISTS）→ 成功且标记 idempotent', function () {
    foreach (['S07', 'RETURN_ORDER_EXISTS'] as $code) {
        Http::fake(['*' => Http::response(json_encode([
            'response' => ['flag' => 'failure', 'code' => $code, 'message' => '单据已存在', 'returnOrderId' => 'CN-RET-EXIST'],
        ]), 200)]);

        $result = app(WmsAdapterFactory::class)->make(cnRetConfig())->createReturnInbound(cnRetDto());

        expect($result->success)->toBeTrue()
            ->and($result->idempotent)->toBeTrue()
            ->and($result->data['wms_order_no'])->toBe('CN-RET-EXIST');
    }
});

test('TC-CNR-05 returnorder.cancel：报文带 returnOrderCode / warehouseCode / ownerCode', function () {
    Http::fake(['*' => Http::response(json_encode(['response' => ['flag' => 'success']]), 200)]);

    $result = app(WmsAdapterFactory::class)->make(cnRetConfig())->cancelReturnInbound('RI20260920000001');

    expect($result->success)->toBeTrue()
        ->and($result->data['status'])->toBe('cancelled');

    $body = cnRetCaptured()['body'];
    expect($body['returnOrderCode'])->toBe('RI20260920000001')
        ->and($body['warehouseCode'])->toBe('CN-WH-RET')
        ->and($body['ownerCode'])->toBe('CUBE_OWNER')
        ->and(cnRetCaptured()['query']['method'])->toBe('taobao.qimen.returnorder.cancel');
});

test('TC-CNR-06 仓方「已收货」拒绝取消 → WmsBizException（业务终局，不可重试）', function () {
    Http::fake(['*' => Http::response(json_encode([
        'response' => ['flag' => 'failure', 'code' => 'S08', 'message' => '单据状态不允许取消'],
    ]), 200)]);

    expect(fn () => app(WmsAdapterFactory::class)->make(cnRetConfig())->cancelReturnInbound('RI1'))
        ->toThrow(\App\Exceptions\Wms\WmsBizException::class);
});

test('TC-CNR-07 缺 WMS 货品编码 / 数量≤0 / 缺明细 → 拒绝推送（BusinessException，不发请求）', function () {
    Http::fake();

    $adapter = app(WmsAdapterFactory::class)->make(cnRetConfig());

    expect(fn () => $adapter->createReturnInbound(cnRetDto(['items' => [
        ['sku_code' => 'SKU-1', 'wms_sku_code' => '', 'quantity' => 1],
    ]])))->toThrow(BusinessException::class);

    expect(fn () => $adapter->createReturnInbound(cnRetDto(['items' => [
        ['sku_code' => 'SKU-1', 'wms_sku_code' => 'W-1', 'quantity' => 0],
    ]])))->toThrow(BusinessException::class);

    expect(fn () => $adapter->createReturnInbound(cnRetDto(['items' => []])))
        ->toThrow(BusinessException::class);

    Http::assertNothingSent();
});

test('TC-CNR-08 缺仓库/货主编码 → 拒绝调用（fail-fast 不发请求）；签名可独立复算', function () {
    Http::fake(['*' => Http::response(json_encode(['response' => ['flag' => 'success']]), 200)]);

    expect(fn () => app(WmsAdapterFactory::class)->make(cnRetConfig(['warehouse_code' => '']))->createReturnInbound(cnRetDto()))
        ->toThrow(BusinessException::class);

    expect(fn () => app(WmsAdapterFactory::class)->make(cnRetConfig(['customer_id' => '']))->createReturnInbound(cnRetDto()))
        ->toThrow(BusinessException::class);

    Http::assertNothingSent();

    // 正常调用一次，独立复算签名（与出库同构：query 参数 + 原样 body）
    Http::fake(['*' => Http::response(json_encode(['response' => ['flag' => 'success']]), 200)]);
    app(WmsAdapterFactory::class)->make(cnRetConfig())->createReturnInbound(cnRetDto());

    $sent = cnRetCaptured();
    $params = $sent['query'];
    unset($params['sign']);
    $expected = (new Signature)->sign($params, 'cn-ret-secret-value', $sent['raw_body']);

    expect($sent['query']['method'])->toBe('taobao.qimen.returnorder.create')
        ->and($sent['query']['sign'])->toBe($expected)
        ->and($sent['raw_body'])->not->toContain('cn-ret-secret-value');
});
