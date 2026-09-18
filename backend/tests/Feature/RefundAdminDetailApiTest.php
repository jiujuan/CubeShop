<?php

use App\Models\Order;
use App\Models\Refund;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 后台退款处理增强：详情接口 + 同意/拒绝理由 + 图片凭证
 *
 * 覆盖：用户申请携带凭证图并落库；详情接口返回订单商品明细（产品图/链接）、
 * 用户、订单摘要与后台处理流水；后台审核可附理由与说明图片；拒绝记录理由。
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

function rdBuyer(): array
{
    $user = createTestUser('rd');

    return ['user' => $user, 'auth' => ['Authorization' => 'Bearer '.$user->createToken('rd')->plainTextToken]];
}

/** 下单 + 沙箱支付 */
function rdOrder(array $auth, int $skuId, int $qty = 1): Order
{
    test()->postJson('/api/cart', ['sku_id' => $skuId, 'quantity' => $qty], $auth)->assertOk();
    $addr = test()->postJson('/api/user/addresses', [
        'contact_name' => '收件人', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '科技路 1 号',
    ], $auth)->json('data');
    $addressId = $addr['id'] ?? $addr;

    $data = test()->postJson('/api/orders', ['address_id' => $addressId], $auth)->json('data');
    $order = Order::find(oid($data['order_id']));

    $payNo = test()->postJson('/api/payments', ['order_no' => $order->order_no, 'channel' => 'wechat'], $auth)
        ->json('data.pay_params.payment_no');
    test()->postJson("/api/payments/sandbox/{$payNo}", [], $auth)->assertOk();

    return $order->fresh();
}

test('TC-RFD-DTL-001 申请携带凭证图片落库，详情接口返回商品明细与处理流水', function () {
    ['user' => $user, 'auth' => $auth] = rdBuyer();
    $sku = createTestSku(stock: 10, price: '66.00');
    $order = rdOrder($auth, $sku->id, 1);

    test()->postJson("/api/orders/{$order->id}/refund", [
        'reason' => '商品破损',
        'images' => ['/storage/uploads/products/20260919/a.jpg', '/storage/uploads/products/20260919/b.jpg'],
    ], $auth)->assertOk();

    $refund = Refund::where('order_id', $order->id)->latest('id')->first();
    expect($refund->images)->toBe([
        '/storage/uploads/products/20260919/a.jpg',
        '/storage/uploads/products/20260919/b.jpg',
    ]);

    // 详情接口
    $res = test()->getJson('/api/admin/refunds/'.rfid($refund->id), $this->adminAuth);
    $res->assertOk()->assertJsonFragment(['code' => 0]);

    expect($res->json('data.refund_no'))->toBe($refund->refund_no)
        ->and($res->json('data.images'))->toBe($refund->images)
        ->and($res->json('data.user.id'))->toBe($user->id)
        ->and($res->json('data.order.order_no'))->toBe($order->order_no)
        // 商品明细：产品 id（后台商品详情链接）与标题
        ->and($res->json('data.items.0.product_id'))->toBe($sku->product_id)
        ->and($res->json('data.items.0.product_title'))->not->toBeEmpty()
        ->and($res->json('data.items.0.quantity'))->toBe(1)
        // 处理流水：至少有一条「用户提交申请」
        ->and($res->json('data.logs'))->not->toBeEmpty();
});

test('TC-RFD-DTL-002 后台同意可附理由与说明图片', function () {
    ['auth' => $auth] = rdBuyer();
    $sku = createTestSku(stock: 10, price: '66.00');
    $order = rdOrder($auth, $sku->id, 1);

    test()->postJson("/api/orders/{$order->id}/refund", ['reason' => '不想要了'], $auth)->assertOk();
    $refund = Refund::where('order_id', $order->id)->latest('id')->first();

    test()->postJson('/api/admin/refunds/'.rfid($refund->id).'/process', [
        'action' => 'approve',
        'admin_remark' => '核对无误，同意退款',
        'admin_images' => ['/storage/uploads/products/20260919/ok.jpg'],
    ], $this->adminAuth)->assertOk();

    $refund->refresh();
    expect($refund->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($refund->admin_remark)->toBe('核对无误，同意退款')
        ->and($refund->admin_images)->toBe(['/storage/uploads/products/20260919/ok.jpg'])
        ->and($refund->processed_by)->not->toBeNull()
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUNDED);
});

test('TC-RFD-DTL-003 后台拒绝记录理由，订单回到已支付', function () {
    ['auth' => $auth] = rdBuyer();
    $sku = createTestSku(stock: 10, price: '66.00');
    $order = rdOrder($auth, $sku->id, 1);

    test()->postJson("/api/orders/{$order->id}/refund", ['reason' => '不喜欢'], $auth)->assertOk();
    $refund = Refund::where('order_id', $order->id)->latest('id')->first();

    test()->postJson('/api/admin/refunds/'.rfid($refund->id).'/process', [
        'action' => 'reject',
        'admin_remark' => '商品已使用，不符合退款条件',
    ], $this->adminAuth)->assertOk();

    $refund->refresh();
    expect($refund->status)->toBe(Refund::STATUS_REJECTED)
        ->and($refund->admin_remark)->toBe('商品已使用，不符合退款条件')
        ->and($order->fresh()->status)->toBe(Order::STATUS_PAID);
});

test('TC-RFD-DTL-004 详情接口越权/不存在返回 40004', function () {
    test()->getJson('/api/admin/refunds/999999', $this->adminAuth)
        ->assertJsonFragment(['code' => 40004]);
});
