<?php

use App\Models\Refund;
use App\Models\RefundLog;
use App\Services\Refund\RefundLogger;
use App\Services\Refund\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// Phase 1 迁移落地验证：refunds 渠道字段 + refund_logs 表
test('phase1 migration adds refund channel fields and refund_logs table', function () {
    foreach (['channel', 'payment_no', 'out_refund_no', 'channel_refund_no', 'refund_status', 'channel_raw', 'failed_reason', 'refunded_at', 'retry_count'] as $col) {
        expect(Schema::hasColumn('refunds', $col))->toBeTrue();
    }
    foreach (['refund_id', 'type', 'channel', 'out_refund_no', 'request', 'response', 'channel_status', 'actor_type', 'actor_id', 'note', 'created_at'] as $col) {
        expect(Schema::hasColumn('refund_logs', $col))->toBeTrue();
    }
});

// Refund 模型：STATUS_PROCESSING、渠道字段 fillable/casts、refundLogs 关联
test('refund model exposes processing status, channel casts and refundLogs relation', function () {
    [$user, $sku, $order] = createPaidOrder();
    $refund = app(RefundService::class)->apply($order, $user->id, '不想要了', null);

    $refund->update([
        'channel' => 'wechat',
        'payment_no' => 'PAY123',
        'out_refund_no' => 'R' . \Illuminate\Support\Str::ulid(),
        'channel_refund_no' => 'CHREF1',
        'refund_status' => 'PROCESSING',
        'channel_raw' => ['foo' => 'bar'],
        'refunded_at' => now(),
        'retry_count' => 2,
    ]);
    $refund->refresh();

    expect(Refund::STATUS_PROCESSING)->toBe('processing')
        ->and($refund->channel)->toBe('wechat')
        ->and($refund->channel_raw)->toBe(['foo' => 'bar'])   // array cast
        ->and($refund->retry_count)->toBe(2)
        ->and($refund->refunded_at)->not->toBeNull();
});

// RefundLogger：append-only 写入，关联退款可查
test('RefundLogger writes append-only log linked to refund', function () {
    [$user, $sku, $order] = createPaidOrder();
    $refund = app(RefundService::class)->apply($order, $user->id, '不想要了', null);
    $refund->update(['channel' => 'wechat', 'out_refund_no' => 'R' . \Illuminate\Support\Str::ulid()]);

    $log = RefundLogger::record($refund, RefundLogger::TYPE_CHANNEL_REQUEST, [
        'request' => ['amount' => 100],
        'channel_status' => 'PROCESSING',
        'actor_type' => 'admin',
        'actor_id' => 1,
        'note' => 'retry 1',
    ]);

    expect($log)->toBeInstanceOf(RefundLog::class)
        ->and($log->type)->toBe('channel_request')
        ->and($log->channel)->toBe('wechat')
        ->and($log->out_refund_no)->toBe($refund->out_refund_no)
        ->and($log->request)->toBe(['amount' => 100])
        ->and($log->created_at)->not->toBeNull();

    expect($refund->refundLogs()->count())->toBe(1);

    // 再次写入追加而非更新（append-only）
    RefundLogger::record($refund, RefundLogger::TYPE_QUERY, ['channel_status' => 'PROCESSING']);
    expect($refund->refundLogs()->count())->toBe(2);
});
