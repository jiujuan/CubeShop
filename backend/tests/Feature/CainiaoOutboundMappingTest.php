<?php

use App\Exceptions\BusinessException;
use App\Exceptions\Wms\WmsBizException;
use App\Exceptions\Wms\WmsUnsupportedException;
use App\Models\Warehouse;
use App\Models\WmsConfig;
use App\Services\Wms\Adapters\Cainiao\Signature;
use App\Services\Wms\Dto\CancelOutboundDto;
use App\Services\Wms\Dto\InventoryQueryDto;
use App\Services\Wms\Dto\OutboundDto;
use App\Services\Wms\Dto\ReturnInboundDto;
use App\Services\Wms\WmsAdapterFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * 菜鸟 Adapter：平台 DTO → 奇门报文（WMS 计划 P2 / F4~F6、Step 4）
 *
 * 用 `Http::fake()` 截获真实发出的请求，断言**实际报文**（而非中间结构）——
 * 这是 P2 唯一能离线验证的部分。真实沙箱连通性属 P7 联调。
 */

beforeEach(function () {
    config([
        'wms.providers.cainiao.gateway.sandbox' => 'https://qimen.sandbox.test/gw',
        'wms.providers.cainiao.gateway.prod' => 'https://qimen.prod.test/gw',
    ]);
});

/** 建仓 + 配好凭证的菜鸟配置（凭证齐备 → 工厂走 CainiaoAdapter） */
function cainiaoConfig(array $attrs = []): WmsConfig
{
    $warehouse = Warehouse::create(['code' => 'WH_CN_'.uniqid(), 'name' => '菜鸟仓', 'status' => 1]);

    $config = WmsConfig::create(array_merge([
        'warehouse_id' => $warehouse->id,
        'provider' => 'cainiao',
        'enabled' => true,
        'auto_push' => true,
        'auto_push_return' => true,
        'push_retry_times' => 3,
        'sku_mapping_mode' => 'same',
        'app_key' => 'cn-app-key',
        'customer_id' => 'CUBE_OWNER',
        'warehouse_code' => 'CN-WH-1',
        'api_env' => 'sandbox',
        'callback_token' => str_repeat('c', 26).uniqid(),
    ], $attrs));

    $config->app_secret = 'cn-app-secret-value';
    $config->save();

    return $config->refresh();
}

/** 标准出库 DTO（两行商品） */
function cainiaoOutboundDto(array $overrides = []): OutboundDto
{
    return new OutboundDto(
        warehouseId: 1,
        bizNo: 'FO20260920000001',
        items: $overrides['items'] ?? [
            ['sku_code' => 'SKU-1', 'wms_sku_code' => 'W-CN-1', 'quantity' => 2, 'product_name' => '示例商品', 'barcode' => '6901234567890'],
            ['sku_code' => 'SKU-2', 'wms_sku_code' => 'W-CN-2', 'quantity' => 1, 'product_name' => '第二件', 'barcode' => null],
        ],
        receiverName: $overrides['receiverName'] ?? '张三',
        receiverPhone: $overrides['receiverPhone'] ?? '13800008000',
        receiverAddress: '浙江省杭州市余杭区文一西路 1 号',
        remark: '请轻拿轻放',
        orderNo: 'CS20260920000001',
        province: $overrides['province'] ?? '浙江省',
        city: $overrides['city'] ?? '杭州市',
        district: $overrides['district'] ?? '余杭区',
        detailAddress: $overrides['detailAddress'] ?? '文一西路 1 号',
    );
}

/** 捕获最近一次出站请求（URL + 解析后的 body + 查询参数） */
function cainiaoCapturedRequest(): array
{
    $pair = Http::recorded()->last();
    expect($pair)->not->toBeNull();

    [$request] = $pair;

    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return [
        'url' => $request->url(),
        'query' => $query,
        'body' => (array) json_decode($request->body(), true),
        'raw_body' => $request->body(),
    ];
}

test('TC-CN-01 创建出库单：平台字段完整映射为奇门报文', function () {
    Http::fake(['*' => Http::response(json_encode([
        'response' => ['flag' => 'success', 'code' => '0', 'deliveryOrderId' => 'CN-1'],
    ]), 200)]);

    $config = cainiaoConfig();
    $result = app(WmsAdapterFactory::class)->make($config)->createOutbound(cainiaoOutboundDto());

    expect($result->success)->toBeTrue();

    $sent = cainiaoCapturedRequest();
    $body = $sent['body'];

    expect($body['deliveryOrder']['deliveryOrderCode'])->toBe('FO20260920000001')
        ->and($body['deliveryOrder']['orderType'])->toBe('JYCK')
        ->and($body['deliveryOrder']['sourceOrderCode'])->toBe('CS20260920000001')
        ->and($body['deliveryOrder']['warehouseCode'])->toBe('CN-WH-1')
        ->and($body['deliveryOrder']['ownerCode'])->toBe('CUBE_OWNER')
        ->and($body['deliveryOrder']['remark'])->toBe('请轻拿轻放')
        ->and($body['sourcePlatformCode'])->toBe('OTHER');

    $receiver = $body['deliveryOrder']['receiverInfo'];
    expect($receiver['name'])->toBe('张三')
        ->and($receiver['mobile'])->toBe('13800008000')
        ->and($receiver['province'])->toBe('浙江省')
        ->and($receiver['city'])->toBe('杭州市')
        ->and($receiver['area'])->toBe('余杭区')       // 平台 district → 奇门 area
        ->and($receiver['detailAddress'])->toBe('文一西路 1 号');
});

test('TC-CN-02 明细行：orderLines 与 deliveryOrder 平级，行号从 1 起', function () {
    Http::fake(['*' => Http::response(json_encode(['response' => ['flag' => 'success']]), 200)]);

    app(WmsAdapterFactory::class)->make(cainiaoConfig())->createOutbound(cainiaoOutboundDto());

    $body = cainiaoCapturedRequest()['body'];

    expect($body['orderLines'])->toBeArray()
        ->and($body['deliveryOrder'])->toBeArray();

    $lines = $body['orderLines']['orderLine'];
    expect($lines)->toHaveCount(2)
        ->and($lines[0]['orderLineNo'])->toBe('1')
        ->and($lines[1]['orderLineNo'])->toBe('2')
        ->and($lines[0]['itemCode'])->toBe('W-CN-1')
        ->and($lines[0]['planQty'])->toBe(2)
        ->and($lines[0]['itemName'])->toBe('示例商品')
        ->and($lines[0]['barCode'])->toBe('6901234567890')
        ->and($lines[0]['ownerCode'])->toBe('CUBE_OWNER');
});

test('TC-CN-03 服务商字段约定：请求 URL 含 method/app_key/sign，签名可被独立复算', function () {
    Http::fake(['*' => Http::response(json_encode(['response' => ['flag' => 'success']]), 200)]);

    $config = cainiaoConfig();
    app(WmsAdapterFactory::class)->make($config)->createOutbound(cainiaoOutboundDto());

    $sent = cainiaoCapturedRequest();
    $query = $sent['query'];

    expect($query['method'])->toBe('taobao.qimen.deliveryorder.create')
        ->and($query['app_key'])->toBe('cn-app-key')
        ->and($query['sign_method'])->toBe('md5')
        ->and($query['sign'])->toMatch('/^[0-9A-F]{32}$/');

    // 独立复算签名：把 URL 里除 sign 外的参数 + 原样 body 重新签一遍
    $params = $query;
    unset($params['sign']);
    $expected = (new Signature)->sign($params, 'cn-app-secret-value', $sent['raw_body']);

    expect($query['sign'])->toBe($expected);

    // 密钥绝不外泄：URL 与 body 都不得出现 AppSecret 明文
    expect($sent['url'])->not->toContain('cn-app-secret-value')
        ->and($sent['raw_body'])->not->toContain('cn-app-secret-value');
});

test('TC-CN-04 幂等回执（S07 单据已存在）→ 成功且标记 idempotent，WMS 单号取自回执', function () {
    Http::fake(['*' => Http::response(json_encode([
        'response' => ['flag' => 'failure', 'code' => 'S07', 'message' => '单据已存在', 'deliveryOrderId' => 'CN-EXIST-9'],
    ]), 200)]);

    $result = app(WmsAdapterFactory::class)->make(cainiaoConfig())->createOutbound(cainiaoOutboundDto());

    expect($result->success)->toBeTrue()
        ->and($result->idempotent)->toBeTrue()
        ->and($result->data['wms_order_no'])->toBe('CN-EXIST-9');
});

test('TC-CN-05 成功回执：WMS 单号归一为 deliveryOrderId', function () {
    Http::fake(['*' => Http::response(json_encode([
        'response' => ['flag' => 'success', 'code' => '0', 'deliveryOrderId' => 'CN-20260920-7777'],
    ]), 200)]);

    $result = app(WmsAdapterFactory::class)->make(cainiaoConfig())->createOutbound(cainiaoOutboundDto());

    expect($result->success)->toBeTrue()
        ->and($result->idempotent)->toBeFalse()
        ->and($result->data['wms_order_no'])->toBe('CN-20260920-7777');
});

test('TC-CN-06 缺 WMS 货品编码 → 拒绝推送（BusinessException）', function () {
    Http::fake();

    $dto = cainiaoOutboundDto(['items' => [
        ['sku_code' => 'SKU-1', 'wms_sku_code' => '', 'quantity' => 1],
    ]]);

    expect(fn () => app(WmsAdapterFactory::class)->make(cainiaoConfig())->createOutbound($dto))
        ->toThrow(BusinessException::class);

    Http::assertNothingSent();
});

test('TC-CN-07 明细数量 ≤ 0 → 拒绝推送', function () {
    Http::fake();

    $dto = cainiaoOutboundDto(['items' => [
        ['sku_code' => 'SKU-1', 'wms_sku_code' => 'W-1', 'quantity' => 0],
    ]]);

    expect(fn () => app(WmsAdapterFactory::class)->make(cainiaoConfig())->createOutbound($dto))
        ->toThrow(BusinessException::class);

    Http::assertNothingSent();
});

test('TC-CN-08 缺仓库编码 / 货主编码 → 拒绝调用（不发请求）', function () {
    Http::fake();

    // 缺 warehouse_code
    expect(fn () => app(WmsAdapterFactory::class)->make(cainiaoConfig(['warehouse_code' => '']))->createOutbound(cainiaoOutboundDto()))
        ->toThrow(BusinessException::class);

    // 缺 customer_id（ownerCode）
    expect(fn () => app(WmsAdapterFactory::class)->make(cainiaoConfig(['customer_id' => '']))->createOutbound(cainiaoOutboundDto()))
        ->toThrow(BusinessException::class);

    Http::assertNothingSent();
});

test('TC-CN-09 收件人必填项缺失（姓名/手机/详细地址）→ 拒绝推送', function () {
    Http::fake();

    $cases = [
        ['receiverName' => ''],
        ['receiverPhone' => ''],
        ['detailAddress' => '', 'province' => '', 'city' => '', 'district' => ''],
    ];

    foreach ($cases as $override) {
        $dto = cainiaoOutboundDto($override);
        // receiverAddress 也会兜底 detailAddress，故第三例需把完整地址也清掉
        if (($override['detailAddress'] ?? null) === '') {
            $dto = new OutboundDto(
                warehouseId: 1, bizNo: 'FO1', items: [['sku_code' => 'S', 'wms_sku_code' => 'W', 'quantity' => 1]],
                receiverName: '张三', receiverPhone: '13800008000', receiverAddress: null, orderNo: 'CS1',
            );
        }

        expect(fn () => app(WmsAdapterFactory::class)->make(cainiaoConfig())->createOutbound($dto))
            ->toThrow(BusinessException::class);
    }

    Http::assertNothingSent();
});

test('TC-CN-10 库存查询：items.item → 归一为 items[] 与 quantities 映射', function () {
    Http::fake(['*' => Http::response(json_encode([
        'response' => [
            'flag' => 'success',
            'warehouseCode' => 'CN-WH-1',
            'items' => ['item' => [
                ['itemCode' => 'W-SKU-001', 'quantity' => 128, 'lockQuantity' => 4],
                ['itemCode' => 'W-SKU-002', 'quantity' => 0, 'lockQuantity' => 0],
            ]],
        ],
    ]), 200)]);

    $result = app(WmsAdapterFactory::class)->make(cainiaoConfig())
        ->queryInventory(new InventoryQueryDto(warehouseId: 1, skuCodes: ['W-SKU-001', 'W-SKU-002']));

    expect($result->success)->toBeTrue()
        ->and($result->data['items'])->toHaveCount(2)
        ->and($result->data['quantities']['W-SKU-001'])->toBe(128)
        ->and($result->data['quantities']['W-SKU-002'])->toBe(0)
        ->and($result->data['mock'])->toBeFalse();
});

test('TC-CN-11 取消出库单：报文带 deliveryOrderCode / deliveryOrderId', function () {
    Http::fake(['*' => Http::response(json_encode(['response' => ['flag' => 'success']]), 200)]);

    app(WmsAdapterFactory::class)->make(cainiaoConfig())->cancelOutbound(new CancelOutboundDto(
        warehouseId: 1, bizNo: 'FO20260920000001', wmsOutboundNo: 'CN-1', reason: '用户取消',
    ));

    $body = cainiaoCapturedRequest()['body'];

    expect($body['deliveryOrderCode'])->toBe('FO20260920000001')
        ->and($body['deliveryOrderId'])->toBe('CN-1')
        ->and($body['warehouseCode'])->toBe('CN-WH-1')
        ->and($body['ownerCode'])->toBe('CUBE_OWNER');
});

test('TC-CN-12 取消「已出库」单据：仓方业务拒绝 → WmsBizException（不可重试）', function () {
    Http::fake(['*' => Http::response(json_encode([
        'response' => ['flag' => 'failure', 'code' => 'S08', 'message' => '单据状态不允许取消'],
    ]), 200)]);

    expect(fn () => app(WmsAdapterFactory::class)->make(cainiaoConfig())->cancelOutbound(new CancelOutboundDto(
        warehouseId: 1, bizNo: 'FO1', wmsOutboundNo: 'CN-1',
    )))->toThrow(WmsBizException::class);
});

test('TC-CN-13 退货入库（P4）→ WmsUnsupportedException，绝不返回假成功', function () {
    Http::fake();

    $adapter = app(WmsAdapterFactory::class)->make(cainiaoConfig());

    expect(fn () => $adapter->createReturnInbound(new ReturnInboundDto(warehouseId: 1, bizNo: 'RT1', items: [])))
        ->toThrow(WmsUnsupportedException::class)
        ->and(fn () => $adapter->cancelReturnInbound('RT1'))
        ->toThrow(WmsUnsupportedException::class);

    Http::assertNothingSent();
});

test('TC-CN-14 网络异常 → 收敛为可重试失败结果（不抛异常）', function () {
    Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('connect timeout')]);

    $result = app(WmsAdapterFactory::class)->make(cainiaoConfig())->createOutbound(cainiaoOutboundDto());

    expect($result->success)->toBeFalse()
        ->and($result->retryable)->toBeTrue()
        ->and($result->error)->toContain('网络异常');
});

test('TC-CN-15 未配置网关地址时 fail-closed（不静默 Mock）', function () {
    config(['wms.providers.cainiao.gateway.sandbox' => null]);
    Http::fake();

    expect(fn () => app(WmsAdapterFactory::class)->make(cainiaoConfig())->createOutbound(cainiaoOutboundDto()))
        ->toThrow(BusinessException::class);

    Http::assertNothingSent();
});
