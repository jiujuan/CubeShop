<?php

use App\Models\Inventory;
use App\Models\Order;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);
    $cap = app(CaptchaService::class)->generate();
    $this->token = $this->postJson('/api/auth/register', [
        'username' => 'orderuser'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token');
    $this->auth = ['Authorization' => 'Bearer '.$this->token];

    $addr = $this->postJson('/api/user/addresses', [
        'contact_name' => '收件人', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '科技路 1 号',
        'is_default' => true,
    ], $this->auth)->json('data');
    $this->addressId = $addr['id'] ?? $addr;

    $this->sku = createTestSku(stock: 10, price: '66.00');
    $this->postJson('/api/cart', ['sku_id' => $this->sku->id, 'quantity' => 2], $this->auth);
});

function createOrderApi($test, array $auth, int $addressId, ?array $cartItemIds = null): array
{
    $payload = ['address_id' => $addressId];
    if ($cartItemIds !== null) {
        $payload['cart_item_ids'] = $cartItemIds;
    }

    return $test->postJson('/api/orders', $payload, $auth)->json();
}

// ORDER-001 从购物车创建订单
test('TC-ORDER-001 下单成功返回待支付订单', function () {
    $body = createOrderApi($this, $this->auth, $this->addressId);

    expect($body['code'])->toBe(0)
        ->and($body['data']['status'])->toBe(Order::STATUS_PENDING_PAYMENT)
        ->and($body['data']['pay_amount'])->toBe('142.00') // 66×2 + 运费10（无包邮配置）
        ->and($body['data']['order_no'])->toStartWith('CS');

    // 详情含快照明细与地址
    $detail = $this->getJson('/api/orders/'.$body['data']['order_id'], $this->auth)->json('data');
    expect($detail['items'])->toHaveCount(1)
        ->and((int) $detail['items'][0]['quantity'])->toBe(2)
        ->and($detail['address_snapshot']['full_address'])->toContain('科技路 1 号');

    // 锁定库存
    expect((int) Inventory::where('sku_id', $this->sku->id)->value('locked_stock'))->toBe(2)
        ->and((int) Inventory::where('sku_id', $this->sku->id)->value('stock'))->toBe(8);

    // 购物车清空
    expect($this->getJson('/api/cart', $this->auth)->json('data.items'))->toBeEmpty();
});

// ORDER-002 无效地址
test('TC-ORDER-002 使用不存在的地址下单被拒绝', function () {
    $body = createOrderApi($this, $this->auth, 99999);

    expect($body['code'])->toBe(40004);
});

// ORDER-003 库存不足
test('TC-ORDER-003 库存不足下单被拒绝', function () {
    // 购物车加购会被「超库存拒绝」拦截，因此先正常加购，再直接下调库存模拟下单时不足
    $sku2 = createTestSku(stock: 20, price: '5.00');
    $this->postJson('/api/cart', ['sku_id' => $sku2->id, 'quantity' => 9], $this->auth);

    \App\Models\Inventory::where('sku_id', $sku2->id)->update(['stock' => 2]);

    $body = createOrderApi($this, $this->auth, $this->addressId);

    expect($body['code'])->toBe(40009)->and($body['message'])->toContain('库存不足');
});

// ORDER-006 订单列表与详情
test('TC-ORDER-006 订单列表与按单号查询', function () {
    $created = createOrderApi($this, $this->auth, $this->addressId)['data'];

    $list = $this->getJson('/api/orders', $this->auth)->json();
    expect($list['code'])->toBe(0)
        ->and($list['data']['pagination']['total'])->toBeGreaterThanOrEqual(1)
        ->and($list['data']['list'])->not->toBeEmpty();

    $byNo = $this->getJson('/api/orders/by-no/'.$created['order_no'], $this->auth)->json();
    expect($byNo['code'])->toBe(0)->and($byNo['data']['order_no'])->toBe($created['order_no'])
        ->and($byNo['data'])->toHaveKey('items');
});

// ORDER-008 取消订单释放库存
test('TC-ORDER-008 取消订单后库存释放', function () {
    $created = createOrderApi($this, $this->auth, $this->addressId)['data'];
    $orderId = $created['order_id'];

    $resp = $this->postJson("/api/orders/{$orderId}/cancel", ['reason' => '不想要了'], $this->auth);
    expect($resp->json('code'))->toBe(0);

    $detail = $this->getJson('/api/orders/'.$orderId, $this->auth)->json('data');
    expect($detail['status'])->toBe(Order::STATUS_CANCELLED)
        ->and((int) Inventory::where('sku_id', $this->sku->id)->value('stock'))->toBe(10)
        ->and((int) Inventory::where('sku_id', $this->sku->id)->value('locked_stock'))->toBe(0);
});

// 他人订单不可见不可操作
test('他人订单不可查询与取消', function () {
    $created = createOrderApi($this, $this->auth, $this->addressId)['data'];
    $orderId = $created['order_id'];

    $cap = app(CaptchaService::class)->generate();
    $otherToken = $this->postJson('/api/auth/register', [
        'username' => 'hacker'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token');
    $otherAuth = ['Authorization' => 'Bearer '.$otherToken];

    $this->getJson('/api/orders/'.$orderId, $otherAuth)->assertStatus(404);
    $this->postJson("/api/orders/{$orderId}/cancel", [], $otherAuth)->assertStatus(404);
});

// 空购物车下单
test('空购物车下单被拒绝', function () {
    $this->postJson('/api/orders', ['address_id' => $this->addressId], $this->auth);

    // 购物车已空，再次下单应拒绝
    $body = createOrderApi($this, $this->auth, $this->addressId);

    expect($body['code'])->not->toBe(0);
});
