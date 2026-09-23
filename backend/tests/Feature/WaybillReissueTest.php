<?php

use App\Models\Order;
use App\Models\Shipping;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 电子面单补出 / 重打（POST /admin/shippings/{id}/waybill/reissue，权限 shipping.manage）
 *
 * 覆盖：
 *  ① 当前渠道可用（mock）→ 写回 tracking_no / waybill_channel / waybill_data.print_template，且不新建发货行；
 *  ② 当前渠道不可用（null/off）→ 40022；
 *  ③ 确定性：同一运单多次补出，Mock 单号一致（安全可重出）；
 *  ④ 运单缺 company_code → 40022。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $cap = app(\App\Services\Common\CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];
});

function reissueOrder(): Order
{
    $user = createTestUser('re'.uniqid());

    return Order::create([
        'order_no' => 'RE'.uniqid(),
        'user_id' => $user->id,
        'status' => Order::STATUS_SHIPPED,
        'total_amount' => '10.00',
        'freight_amount' => '0.00',
        'pay_amount' => '10.00',
        'tracking_no' => 'MANUAL_OLD',
        'address_snapshot' => [
            'contact_name' => '张三', 'contact_phone' => '13800000000',
            'province' => '广东', 'city' => '深圳', 'district' => '南山', 'detail_address' => '科技园1号',
            'full_address' => '广东省深圳市南山区科技园1号',
        ],
    ]);
}

function reissueShipping(Order $order, string $companyCode = 'SF'): Shipping
{
    return Shipping::create([
        'order_id' => $order->id,
        'company_code' => $companyCode,
        'company_name' => '顺丰速运',
        'tracking_no' => 'MANUAL_OLD',
        'trace_status' => Shipping::TRACE_PENDING,
        'shipped_at' => now(),
    ]);
}

it('mock 渠道补出写回模板且不新建发货行', function () {
    config(['services.waybill.channel' => 'mock']);
    $order = reissueOrder();
    $shipping = reissueShipping($order);

    $this->postJson("/api/admin/shippings/{$shipping->id}/waybill/reissue", [], $this->adminAuth)
        ->assertOk()
        ->assertJsonPath('data.channel', 'mock')
        ->assertJsonPath('message', '面单已补出');

    $shipping->refresh();
    expect($shipping->tracking_no)->toStartWith('MOCK')
        ->and($shipping->waybill_channel)->toBe('mock')
        ->and($shipping->waybill_printed_at)->not->toBeNull()
        ->and($shipping->waybill_data['print_template'] ?? null)->not->toBeNull();

    // 订单冗余双号同步
    expect($order->refresh()->tracking_no)->toBe($shipping->tracking_no);
    // 仅更新，不新建发货行
    expect(Shipping::where('order_id', $order->id)->count())->toBe(1);
});

it('渠道不可用时补出返回 40022', function () {
    config(['services.waybill.channel' => null]); // NullWaybillChannel → 不可用
    $order = reissueOrder();
    $shipping = reissueShipping($order);

    $this->postJson("/api/admin/shippings/{$shipping->id}/waybill/reissue", [], $this->adminAuth)
        ->assertOk()
        ->assertJsonPath('code', 40022);

    // 不发生写回
    $shipping->refresh();
    expect($shipping->waybill_channel)->toBeNull();
});

it('同一运单多次补出 Mock 单号一致（确定性）', function () {
    config(['services.waybill.channel' => 'mock']);
    $order = reissueOrder();
    $shipping = reissueShipping($order);

    $no1 = $this->postJson("/api/admin/shippings/{$shipping->id}/waybill/reissue", [], $this->adminAuth)->json('data.tracking_no');
    $no2 = $this->postJson("/api/admin/shippings/{$shipping->id}/waybill/reissue", [], $this->adminAuth)->json('data.tracking_no');

    expect($no1)->toStartWith('MOCK')->and($no1)->toBe($no2);
});

it('运单缺快递公司编码时补出返回 40022', function () {
    config(['services.waybill.channel' => 'mock']);
    $order = reissueOrder();
    $shipping = reissueShipping($order, ''); // company_code 为空

    $this->postJson("/api/admin/shippings/{$shipping->id}/waybill/reissue", [], $this->adminAuth)
        ->assertOk()
        ->assertJsonPath('code', 40022);
});

it('未认证补出返回 401', function () {
    config(['services.waybill.channel' => 'mock']);
    $order = reissueOrder();
    $shipping = reissueShipping($order);

    $this->postJson("/api/admin/shippings/{$shipping->id}/waybill/reissue", [])
        ->assertUnauthorized(); // 401（未带 token）
});
