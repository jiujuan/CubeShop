<?php

use App\Models\Inventory;
use App\Models\Order;
use App\Models\Refund;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 退货退款领域能力（WMS 计划 P4 / D3，为接入菜鸟打基础）
 *
 * 覆盖：storefront 申请退货退款（带 public_id 与物流）→ 后台审核通过停在
 * approved/waiting_return（不立即放款，订单保持 refunding）→ 后台确认收货按
 * 实收正品回库存并退款完成（订单 refunded）；负向：缺退货明细 422、仅退款不可
 * 确认收货、残次不回库存。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];
});

function rrBuyer(): array
{
    $user = createTestUser('rr');

    return ['user' => $user, 'auth' => ['Authorization' => 'Bearer '.$user->createToken('rr')->plainTextToken]];
}

function rrAddress(array $auth): int
{
    $addr = test()->postJson('/api/user/addresses', [
        'contact_name' => '收件人', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '科技路 1 号',
    ], $auth)->json('data');

    return $addr['id'] ?? $addr;
}

/** 下单 + 沙箱支付，返回 Order 模型 */
function rrOrder(array $auth, int $skuId, int $qty = 2): Order
{
    test()->postJson('/api/cart', ['sku_id' => $skuId, 'quantity' => $qty], $auth)->assertOk();
    $addressId = rrAddress($auth);
    $data = test()->postJson('/api/orders', ['address_id' => $addressId], $auth)->json('data');
    $order = Order::find(oid($data['order_id']));

    $payNo = test()->postJson('/api/payments', ['order_no' => $order->order_no, 'channel' => 'wechat'], $auth)
        ->json('data.pay_params.payment_no');
    test()->postJson("/api/payments/sandbox/{$payNo}", [], $auth)->assertOk();

    return $order->fresh();
}

test('TC-RFD-RET-001 退货退款完整闭环：申请→审核通过(不放款)→确认收货回库存退款', function () {
    ['auth' => $auth] = rrBuyer();
    $sku = createTestSku(stock: 20, price: '66.00');
    $order = rrOrder($auth, $sku->id, 2);

    $stockBefore = Inventory::where('sku_id', $sku->id)->value('stock');

    // 申请退货退款（sku_id 用 public_id，模拟 web 前端）
    $res = test()->postJson("/api/orders/{$order->id}/refund", [
        'reason' => '商品质量问题',
        'type' => 'return_refund',
        'return_details' => [[
            'sku_id' => $sku->public_id,
            'quantity' => 2,
            'product_title' => $sku->product->title ?? '商品',
            'sku_specs' => ['规格' => '标准'],
        ]],
        'return_tracking_no' => 'SF1234567890',
        'return_express_company' => '顺丰速运',
    ], $auth);
    $res->assertOk();

    $refund = Refund::where('order_id', $order->id)->latest('id')->first();
    expect($refund->type)->toBe(Refund::TYPE_RETURN_REFUND)
        ->and($refund->status)->toBe(Refund::STATUS_PENDING)
        ->and($refund->return_status)->toBeNull()
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUNDING);

    // 后台审核通过：停在 approved + waiting_return，订单保持 refunding（不立即放款）
    test()->postJson('/api/admin/refunds/'.rfid($refund->id).'/process', ['action' => 'approve'], $this->adminAuth)->assertOk();

    $refund->refresh();
    expect($refund->status)->toBe(Refund::STATUS_APPROVED)
        ->and($refund->return_status)->toBe(Refund::RETURN_STATUS_WAITING_RETURN)
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUNDING)
        // 审核通过尚未回库存
        ->and((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe($stockBefore);

    // 后台确认收货（实收 2 件正品）
    test()->postJson('/api/admin/refunds/'.rfid($refund->id).'/receive', [
        'received_details' => [['sku_id' => $sku->id, 'quantity' => 2, 'condition' => 'good']],
    ], $this->adminAuth)->assertOk();

    $refund->refresh();
    expect($refund->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($refund->return_status)->toBe(Refund::RETURN_STATUS_RECEIVED)
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUNDED)
        // 正品回库存：+2
        ->and((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe($stockBefore + 2);
});

test('TC-RFD-RET-002 退货退款申请缺少应退明细 → 40000', function () {
    ['auth' => $auth] = rrBuyer();
    $sku = createTestSku(stock: 20, price: '66.00');
    $order = rrOrder($auth, $sku->id, 1);

    test()->postJson("/api/orders/{$order->id}/refund", [
        'type' => 'return_refund',
        'reason' => '无明细',
    ], $auth)->assertJsonFragment(['code' => 40000])->assertStatus(400);

    expect(Refund::where('order_id', $order->id)->exists())->toBeFalse();
});

test('TC-RFD-RET-003 仅退款类型调用确认收货 → 400 不可确认', function () {
    ['auth' => $auth] = rrBuyer();
    $sku = createTestSku(stock: 20, price: '66.00');
    $order = rrOrder($auth, $sku->id, 1);

    // 仅退款申请 + 审核通过（立即 success）
    test()->postJson("/api/orders/{$order->id}/refund", ['reason' => '不想要了'], $auth)->assertOk();
    $refund = Refund::where('order_id', $order->id)->latest('id')->first();
    test()->postJson('/api/admin/refunds/'.rfid($refund->id).'/process', ['action' => 'approve'], $this->adminAuth)->assertOk();

    // 仅退款不可确认收货
    test()->postJson('/api/admin/refunds/'.rfid($refund->id).'/receive', [
        'received_details' => [['sku_id' => $sku->id, 'quantity' => 1, 'condition' => 'good']],
    ], $this->adminAuth)->assertStatus(400);
});

test('TC-RFD-RET-004 确认收货残次不回库存，但退款仍完成', function () {
    ['auth' => $auth] = rrBuyer();
    $sku = createTestSku(stock: 20, price: '66.00');
    $order = rrOrder($auth, $sku->id, 2);

    $stockBefore = Inventory::where('sku_id', $sku->id)->value('stock');

    test()->postJson("/api/orders/{$order->id}/refund", [
        'type' => 'return_refund',
        'reason' => '残次退回',
        'return_details' => [['sku_id' => $sku->public_id, 'quantity' => 2, 'product_title' => '商品', 'sku_specs' => []]],
    ], $auth)->assertOk();

    $refund = Refund::where('order_id', $order->id)->latest('id')->first();
    test()->postJson('/api/admin/refunds/'.rfid($refund->id).'/process', ['action' => 'approve'], $this->adminAuth)->assertOk();

    // 全部残次退回
    test()->postJson('/api/admin/refunds/'.rfid($refund->id).'/receive', [
        'received_details' => [['sku_id' => $sku->id, 'quantity' => 2, 'condition' => 'defective']],
    ], $this->adminAuth)->assertOk();

    $refund->refresh();
    expect($refund->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($refund->return_status)->toBe(Refund::RETURN_STATUS_RECEIVED)
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUNDED)
        // 残次不计可售：库存不变
        ->and((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe($stockBefore);
});
