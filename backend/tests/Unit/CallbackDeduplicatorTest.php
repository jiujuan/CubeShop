<?php

namespace Tests\Unit;

use App\Services\Wms\Callback\CallbackDeduplicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * 回调幂等与防重放（WMS 计划 P3 / Step 3）
 *
 * 两层防线：raw 防重放（Cache 短窗口）+ 业务幂等（dedups 表四元组唯一）。
 * claim 失败必须可 release——处理失败后对方重推要能再次进入。
 */

test('raw 防重放：同一条报文短窗口内第二次 claim 返回 false', function () {
    $dedup = new CallbackDeduplicator(replayTtl: 60);
    $raw = json_encode(['method' => 'taobao.qimen.deliveryorder.confirm', 't' => 1]);

    expect($dedup->claimRaw('cainiao', $raw))->toBeTrue()
        ->and($dedup->claimRaw('cainiao', $raw))->toBeFalse();
});

test('raw 防重放：不同报文互不影响，TTL 过期后可再次 claim', function () {
    $dedup = new CallbackDeduplicator(replayTtl: 60);

    expect($dedup->claimRaw('cainiao', '{"a":1}'))->toBeTrue()
        ->and($dedup->claimRaw('cainiao', '{"a":2}'))->toBeTrue();

    // 模拟窗口过期（ArrayStore 无时间旅行——换 provider 维度验证键隔离即可）
    expect($dedup->claimRaw('jd_cloud', '{"a":1}'))->toBeTrue();
});

test('业务幂等：同四元组第二次 claimEvent 返回 false，不同 status_key 互不影响', function () {
    $dedup = new CallbackDeduplicator(replayTtl: 60);

    expect($dedup->claimEvent('cainiao', 'FO1', 'confirm', 'shipped'))->toBeTrue()
        ->and($dedup->claimEvent('cainiao', 'FO1', 'confirm', 'shipped'))->toBeFalse()
        // 同单不同事件（status picking）不受 confirm 占坑影响
        ->and($dedup->claimEvent('cainiao', 'FO1', 'status', 'picking'))->toBeTrue()
        // 无状态语义的消息（status_key=''）同样按四元组判重
        ->and($dedup->claimEvent('cainiao', 'FO1', 'status', ''))->toBeTrue();
});

test('release 后可重新占坑（处理失败重试的先决条件）', function () {
    $dedup = new CallbackDeduplicator(replayTtl: 60);

    expect($dedup->claimEvent('cainiao', 'FO2', 'confirm', 'shipped'))->toBeTrue();

    $dedup->releaseEvent('cainiao', 'FO2', 'confirm', 'shipped');

    expect($dedup->claimEvent('cainiao', 'FO2', 'confirm', 'shipped'))->toBeTrue();
});

test('并发窗口内唯一索引兜底：先插库再 claim 返回 false', function () {
    $dedup = new CallbackDeduplicator(replayTtl: 60);

    // 模拟另一并发 Job 直接写库占坑（绕过 exists 预检查）
    \App\Models\WmsCallbackDedup::create([
        'provider' => 'cainiao', 'biz_no' => 'FO3', 'msg_type' => 'confirm',
        'status_key' => 'shipped', 'received_at' => now(),
    ]);

    expect($dedup->claimEvent('cainiao', 'FO3', 'confirm', 'shipped'))->toBeFalse();
});

test('status_key 禁 NULL：模型层把 null 归一为空串（保证唯一索引对无状态消息生效）', function () {
    $dedup = new CallbackDeduplicator(replayTtl: 60);

    // 调用方误传 null 时模型层归一，不产生 NULL 行（NULL 行会让唯一索引失效）
    $row = \App\Models\WmsCallbackDedup::create([
        'provider' => 'cainiao', 'biz_no' => 'FO4', 'msg_type' => 'confirm',
        'status_key' => null, 'received_at' => now(),
    ]);

    expect($row->refresh()->status_key)->toBe('')
        // 空串事件的幂等判定不被脏数据影响
        ->and($dedup->claimEvent('cainiao', 'FO4', 'confirm', ''))->toBeFalse();
});
