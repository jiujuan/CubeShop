<?php

namespace Tests\Unit;

use App\Services\Wms\Callback\CallbackMessageParser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 回传报文解析器（WMS 计划 P3 / Step 4）
 *
 * 纯解析单测：信封容错（三层）、包裹三种形态、行项目单条/列表、
 * msg_type 判定（method 优先 / 状态兜底）。
 */

test('confirm 报文：method 显式标注、packages 列表、行项目列表全解析', function () {
    $parser = new CallbackMessageParser;

    $msg = $parser->parse([
        'method' => 'taobao.qimen.deliveryorder.confirm',
        'app_key' => 'K',
        'deliveryOrder' => [
            'deliveryOrderCode' => 'FO1',
            'deliveryOrderId' => 'CN-1',
            'status' => 'SHIPPED',
            'packages' => ['package' => [
                ['logisticsCode' => 'SF', 'logisticsName' => '顺丰速运', 'expressCode' => 'SF1', 'weight' => '1.5',
                    'items' => ['item' => [['itemCode' => 'SKU-1', 'quantity' => 2]]]],
                ['logisticsCode' => 'YTO', 'expressCode' => 'Y1'],
            ]],
        ],
    ]);

    expect($msg['msg_type'])->toBe('confirm')
        ->and($msg['status_key'])->toBe('shipped')
        ->and($msg['biz_no'])->toBe('FO1')
        ->and($msg['wms_no'])->toBe('CN-1')
        ->and($msg['packages'])->toHaveCount(2)
        ->and($msg['packages'][0]['tracking_no'])->toBe('SF1')
        ->and($msg['packages'][0]['carrier_name'])->toBe('顺丰速运')
        ->and($msg['packages'][0]['weight'])->toBe('1.5')
        ->and($msg['packages'][0]['items'][0]['platform_sku_code'])->toBe('SKU-1')
        ->and($msg['packages'][0]['items'][0]['quantity'])->toBe(2)
        ->and($msg['packages'][1]['sort'])->toBe(1)
        ->and($msg['packages'][1]['items'])->toBe([]);
});

test('status 报文：根层平铺 deliveryOrderCode 与 status，单包裹对象形态', function () {
    $parser = new CallbackMessageParser;

    $msg = $parser->parse([
        'method' => 'taobao.qimen.deliveryorder.status',
        'deliveryOrderCode' => 'FO2',
        'status' => 'PICKING',
    ]);

    expect($msg['msg_type'])->toBe('status')
        ->and($msg['status_key'])->toBe('picking')
        ->and($msg['biz_no'])->toBe('FO2')
        ->and($msg['packages'])->toBe([]);
});

test('信封容错：body 包裹式与根层唯一业务键包裹式都能定位 deliveryOrder', function () {
    $parser = new CallbackMessageParser;

    $bodyWrapped = $parser->parse([
        'method' => 'taobao.qimen.deliveryorder.confirm',
        'body' => ['deliveryOrder' => ['deliveryOrderCode' => 'FO3', 'status' => 'SHIPPED']],
    ]);
    $envelopeWrapped = $parser->parse([
        'method' => 'taobao.qimen.deliveryorder.confirm',
        'response' => ['deliveryOrderCode' => 'FO4', 'status' => 'SHIPPED'],
    ]);

    expect($bodyWrapped['biz_no'])->toBe('FO3')
        ->and($envelopeWrapped['biz_no'])->toBe('FO4');
});

test('method 缺失时按状态兜底判定 msg_type（SHIPPED → confirm，其余 → status）', function () {
    $parser = new CallbackMessageParser;

    $confirmLike = $parser->parse(['deliveryOrderCode' => 'FO5', 'status' => 'SHIPPED']);
    $statusLike = $parser->parse(['deliveryOrderCode' => 'FO6', 'status' => 'PACKED']);

    expect($confirmLike['msg_type'])->toBe('confirm')
        ->and($statusLike['msg_type'])->toBe('status');
});

test('未知消息类型与空报文：msg_type 为 null（外层按已消费告警处理）', function () {
    $parser = new CallbackMessageParser;

    // P4 起 returnorder.confirm 已是受支持类型，改用真正未知的 method 断言
    expect($parser->parse(['method' => 'taobao.qimen.unknown.message', 'deliveryOrderCode' => 'FO7'])['msg_type'])->toBeNull()
        ->and($parser->parse([])['msg_type'])->toBeNull()
        ->and($parser->parse([])['biz_no'])->toBeNull();
});

test('包裹简式：顶层 expressCode 平铺（无 packages 节点）归一为单包裹', function () {
    $parser = new CallbackMessageParser;

    $msg = $parser->parse([
        'method' => 'taobao.qimen.deliveryorder.confirm',
        'deliveryOrder' => [
            'deliveryOrderCode' => 'FO8',
            'status' => 'SHIPPED',
            'logisticsCode' => 'SF',
            'expressCode' => 'SF888',
        ],
    ]);

    expect($msg['packages'])->toHaveCount(1)
        ->and($msg['packages'][0]['tracking_no'])->toBe('SF888')
        ->and($msg['packages'][0]['sort'])->toBe(0);
});
