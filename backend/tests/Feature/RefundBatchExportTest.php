<?php

use App\Models\CartItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\UserAddress;
use App\Services\Common\CaptchaService;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRoles();
    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin',
        'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];
});

/** 本地闭包建单（避免与其它测试的全局函数冲突） */
function makePaidOrderForBatch(): array
{
    $user = createTestUser();
    $sku = createTestSku(stock: 20, price: '100.00');
    CartItem::create(['user_id' => $user->id, 'sku_id' => $sku->id, 'quantity' => 1]);
    $address = UserAddress::create([
        'user_id' => $user->id, 'contact_name' => 'a', 'contact_phone' => 'b',
        'province' => 'p', 'city' => 'c', 'district' => 'd', 'detail_address' => 'e',
    ]);
    $svc = app(OrderService::class);
    $order = $svc->createFromCart($user->id, $address->id, null, null);
    $order = $svc->transitionTo($order, Order::STATUS_PAID);
    $order = $svc->transitionTo($order, Order::STATUS_REFUNDING);

    // 余额支付单：批量同意时余额退款可同步落 success
    Payment::create([
        'payment_no' => 'PAY'.strtoupper((string) \Illuminate\Support\Str::random(16)),
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'user_id' => $user->id,
        'channel' => Payment::CHANNEL_BALANCE,
        'amount' => $order->pay_amount,
        'status' => Payment::STATUS_SUCCESS,
        'biz_type' => Payment::BIZ_TYPE_ORDER,
        'biz_no' => $order->order_no,
        'paid_at' => now(),
    ]);

    return [$user, $order];
}

function makePendingRefundForBatch(\App\Models\User $user, object $order): Refund
{
    return Refund::create([
        'refund_no' => 'RF'.strtoupper((string) \Illuminate\Support\Str::random(12)),
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'user_id' => $user->id,
        'type' => Refund::TYPE_REFUND,
        'amount' => '100.00',
        'reason' => '不想要了',
        'status' => Refund::STATUS_PENDING,
    ]);
}

test('批量拒绝：pending 全部拒绝，非 pending 逐单回报失败', function () {
    [$user, $order] = makePaidOrderForBatch();
    $r1 = makePendingRefundForBatch($user, $order);
    $r2 = makePendingRefundForBatch($user, $order);
    $r3 = Refund::create([
        'refund_no' => 'RF'.strtoupper((string) \Illuminate\Support\Str::random(12)),
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'user_id' => $user->id,
        'type' => Refund::TYPE_REFUND,
        'amount' => '100.00',
        'status' => Refund::STATUS_REJECTED,
    ]);

    $res = $this->postJson('/api/admin/refunds/batch-process', [
        'ids' => [$r1->id, $r2->id, $r3->id, 999999],
        'action' => 'reject',
        'remark' => '批量驳回：凭证不足',
    ], $this->adminAuth);

    $res->assertOk();
    $data = $res->json('data');
    expect($data['total'])->toBe(4)
        ->and($data['succeeded_count'])->toBe(2)
        ->and($data['failed_count'])->toBe(2)
        ->and(collect($data['succeeded'])->pluck('id')->all())->toEqual([$r2->id, $r1->id])
        ->and($data['succeeded'][0]['status'])->toBe(Refund::STATUS_REJECTED)
        ->and(collect($data['failed'])->first(fn ($f) => $f['id'] === $r3->id)['reason'])->toContain('已处理')
        ->and(collect($data['failed'])->first(fn ($f) => $f['id'] === 999999)['reason'])->toBe('退款单不存在');

    expect($r1->fresh()->status)->toBe(Refund::STATUS_REJECTED)
        ->and($r1->fresh()->admin_remark)->toBe('批量驳回：凭证不足')
        ->and($r2->fresh()->status)->toBe(Refund::STATUS_REJECTED);
});

test('批量同意：余额支付单同步退款成功', function () {
    [$user, $order] = makePaidOrderForBatch();
    $r1 = makePendingRefundForBatch($user, $order);
    $r2 = makePendingRefundForBatch($user, $order);

    $res = $this->postJson('/api/admin/refunds/batch-process', [
        'ids' => [$r1->id, $r2->id],
        'action' => 'approve',
    ], $this->adminAuth);

    $res->assertOk();
    $data = $res->json('data');
    expect($data['succeeded_count'])->toBe(2)
        ->and($data['failed_count'])->toBe(0)
        ->and($data['succeeded'][0]['status'])->toBe(Refund::STATUS_SUCCESS);

    expect($r1->fresh()->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($r1->fresh()->channel)->toBe(Payment::CHANNEL_BALANCE)
        ->and($r2->fresh()->status)->toBe(Refund::STATUS_SUCCESS);
});

test('批量校验：ids 必填且最多 100，action 仅 approve/reject', function () {
    $this->postJson('/api/admin/refunds/batch-process', ['action' => 'approve'], $this->adminAuth)
        ->assertStatus(422);
    $this->postJson('/api/admin/refunds/batch-process', [
        'ids' => range(1, 101), 'action' => 'approve',
    ], $this->adminAuth)->assertStatus(422);
    $this->postJson('/api/admin/refunds/batch-process', [
        'ids' => [1], 'action' => 'delete',
    ], $this->adminAuth)->assertStatus(422);
});

test('导出 CSV：随筛选条件全量输出，含 UTF-8 BOM 与表头', function () {
    [$user, $order] = makePaidOrderForBatch();
    $pending = makePendingRefundForBatch($user, $order);
    $done = Refund::create([
        'refund_no' => 'RF'.strtoupper((string) \Illuminate\Support\Str::random(12)),
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'user_id' => $user->id,
        'type' => Refund::TYPE_REFUND,
        'amount' => '50.00',
        'status' => Refund::STATUS_SUCCESS,
        'refunded_at' => now(),
    ]);

    // 不带筛选：两单都导出
    $res = $this->getJson('/api/admin/refunds/export', $this->adminAuth);
    $res->assertOk();
    $body = $res->streamedContent();
    expect($res->headers->get('Content-Type'))->toContain('text/csv')
        ->and(str_starts_with($body, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($body)->toContain('退款单号')
        ->and($body)->toContain($pending->refund_no)
        ->and($body)->toContain($done->refund_no);

    // 带 status=pending：只导出待审核单
    $res2 = $this->getJson('/api/admin/refunds/export?status=pending', $this->adminAuth);
    $body2 = $res2->streamedContent();
    expect($body2)->toContain($pending->refund_no)
        ->and($body2)->not->toContain($done->refund_no);
});
