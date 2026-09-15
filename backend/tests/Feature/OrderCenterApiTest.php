<?php

use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\UserAddress;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * V1.1 T-004：订单列表查询扩展（Tab/关键词/时间）+ 再次购买
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $cap = app(CaptchaService::class)->generate();
    $this->userToken = $this->postJson('/api/auth/register', [
        'username' => 'center'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token');
    $this->auth = ['Authorization' => 'Bearer '.$this->userToken];
    $this->userId = $this->getJson('/api/auth/me', $this->auth)->json('data.id');
});

/** 直接建单（避开下单限流；仅用于列表/复购的场景铺垫） */
function seedOrder(int $userId, string $status, string $contactName = '张三', string $phone = '13800000000', array $items = [], ?string $createdAt = null): Order
{
    static $seq = 0;
    $seq++;

    $order = Order::create([
        'order_no' => 'CS'.now()->format('Ymd').str_pad((string) $seq, 6, '0', STR_PAD_LEFT).random_int(10, 99),
        'user_id' => $userId,
        'status' => $status,
        'total_amount' => '100.00',
        'freight_amount' => '0.00',
        'pay_amount' => '100.00',
        'address_snapshot' => [
            'contact_name' => $contactName,
            'contact_phone' => $phone,
            'full_address' => '广东省深圳市南山区科技路 1 号',
        ],
        'paid_at' => $status !== Order::STATUS_PENDING_PAYMENT ? now() : null,
        'shipped_at' => in_array($status, [Order::STATUS_SHIPPED, Order::STATUS_COMPLETED], true) ? now() : null,
        'completed_at' => $status === Order::STATUS_COMPLETED ? now() : null,
    ]);

    // created_at 不在 fillable 中，需显式回写以模拟历史订单
    if ($createdAt !== null) {
        $order->forceFill(['created_at' => \Illuminate\Support\Carbon::parse($createdAt)])->save();
    }

    foreach ($items as [$skuId, $qty, $title]) {
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => null,
            'sku_id' => $skuId,
            'product_title' => $title,
            'sku_specs' => ['规格' => '标准'],
            'price' => '50.00',
            'quantity' => $qty,
            'total_amount' => bcmul('50.00', (string) $qty, 2),
        ]);
    }

    return $order;
}

// T-004：各 tab 返回数量正确
test('TC-CENTER-001 各分组 Tab 返回数量正确', function () {
    seedOrder($this->userId, Order::STATUS_PENDING_PAYMENT);
    seedOrder($this->userId, Order::STATUS_PENDING_PAYMENT);
    seedOrder($this->userId, Order::STATUS_PAID);
    seedOrder($this->userId, Order::STATUS_SHIPPED);
    seedOrder($this->userId, Order::STATUS_COMPLETED);
    seedOrder($this->userId, Order::STATUS_REFUNDING);
    seedOrder($this->userId, Order::STATUS_CANCELLED);

    $expect = [
        'all' => 7,
        'pending_payment' => 2,
        'pending_ship' => 1,
        'pending_receive' => 1,
        'pending_review' => 1,
        'after_sale' => 1,
    ];

    foreach ($expect as $tab => $count) {
        $resp = $this->getJson('/api/orders?tab='.$tab, $this->auth)->json();
        expect($resp['data']['pagination']['total'])->toBe($count, "tab={$tab}");
    }
});

// T-004：关键词命中订单号
test('TC-CENTER-002 关键词命中订单号', function () {
    $order = seedOrder($this->userId, Order::STATUS_PAID);
    seedOrder($this->userId, Order::STATUS_PAID, '李四', '13900000001');

    $resp = $this->getJson('/api/orders?keyword='.$order->order_no, $this->auth)->json();

    expect($resp['data']['pagination']['total'])->toBe(1)
        ->and($resp['data']['list'][0]['order_no'])->toBe($order->order_no);
});

// T-004：关键词命中收货人手机号与姓名
test('TC-CENTER-003 关键词命中收货人姓名与手机号', function () {
    seedOrder($this->userId, Order::STATUS_PAID, '李四', '13900000001');
    seedOrder($this->userId, Order::STATUS_PAID, '张三', '13800000000');

    $byPhone = $this->getJson('/api/orders?keyword=13900000001', $this->auth)->json();
    expect($byPhone['data']['pagination']['total'])->toBe(1)
        ->and($byPhone['data']['list'][0]['order_no'])->not->toBeEmpty();

    $byName = $this->getJson('/api/orders?keyword='.urlencode('李四'), $this->auth)->json();
    expect($byName['data']['pagination']['total'])->toBe(1);
});

// T-004：时间区间边界（含当日）
test('TC-CENTER-004 下单时间区间筛选边界正确', function () {
    seedOrder($this->userId, Order::STATUS_PAID, createdAt: now()->subDays(10)->toDateTimeString());
    seedOrder($this->userId, Order::STATUS_PAID, createdAt: now()->subDays(5)->toDateTimeString());
    seedOrder($this->userId, Order::STATUS_PAID, createdAt: now()->toDateTimeString());

    $start = now()->subDays(6)->toDateString();
    $end = now()->toDateString();
    $resp = $this->getJson("/api/orders?start={$start}&end={$end}", $this->auth)->json();

    expect($resp['data']['pagination']['total'])->toBe(2);
});

// T-004：列表返回 actions 与 items_preview
test('TC-CENTER-005 列表返回操作可用性与缩略预览', function () {
    $sku = createTestSku(stock: 5, price: '50.00');
    seedOrder($this->userId, Order::STATUS_SHIPPED, items: [[$sku->id, 2, '测试商品']]);

    $item = $this->getJson('/api/orders?tab=pending_receive', $this->auth)->json('data.list.0');

    expect($item['actions']['can_confirm'])->toBeTrue()
        ->and($item['actions']['can_cancel'])->toBeFalse()
        ->and($item['actions']['can_rebuy'])->toBeTrue()
        ->and($item['item_count'])->toBe(2)
        ->and($item['items_preview'])->toHaveCount(1)
        ->and($item['items_preview'][0]['product_title'])->toBe('测试商品');
});

// T-004：再次购买全成功
test('TC-CENTER-006 再次购买全部加入购物车', function () {
    $skuA = createTestSku(stock: 10, price: '10.00');
    $skuB = createTestSku(stock: 10, price: '20.00');
    $order = seedOrder($this->userId, Order::STATUS_COMPLETED, items: [[$skuA->id, 2, '商品A'], [$skuB->id, 1, '商品B']]);

    $resp = $this->postJson("/api/orders/{$order->id}/rebuy", [], $this->auth)->json();

    expect($resp['code'])->toBe(0)
        ->and($resp['data']['added'])->toBe(2)
        ->and($resp['data']['skipped'])->toBeEmpty()
        ->and($resp['data']['cart_count'])->toBe(3);

    expect((int) CartItem::where('user_id', $this->userId)->where('sku_id', $skuA->id)->value('quantity'))->toBe(2)
        ->and((int) CartItem::where('user_id', $this->userId)->where('sku_id', $skuB->id)->value('quantity'))->toBe(1);
});

// T-004：再次购买混合失效场景
test('TC-CENTER-007 再次购买跳过失效行并返回原因', function () {
    $ok = createTestSku(stock: 10, price: '10.00');
    $offline = createTestSku(stock: 10, price: '10.00', productStatus: 0);
    $soldOut = createTestSku(stock: 10, price: '10.00');
    Inventory::where('sku_id', $soldOut->id)->update(['stock' => 0, 'locked_stock' => 0]);

    $order = seedOrder($this->userId, Order::STATUS_COMPLETED, items: [
        [$ok->id, 1, '正常商品'],
        [$offline->id, 1, '已下架商品'],
        [$soldOut->id, 1, '已售罄商品'],
    ]);

    $resp = $this->postJson("/api/orders/{$order->id}/rebuy", [], $this->auth)->json();

    expect($resp['data']['added'])->toBe(1)
        ->and($resp['data']['skipped'])->toHaveCount(2);

    $reasons = collect($resp['data']['skipped'])->pluck('reason')->all();
    expect($reasons)->toContain('商品已下架')->toContain('已售罄');
});

// T-004：再次购买按最大可购数量加入（不超卖）
test('TC-CENTER-008 再次购买库存不足时按最大可购数量加入', function () {
    $sku = createTestSku(stock: 5, price: '10.00');
    $order = seedOrder($this->userId, Order::STATUS_COMPLETED, items: [[$sku->id, 4, '商品']]);

    // 库存降到 3
    Inventory::where('sku_id', $sku->id)->update(['stock' => 3, 'locked_stock' => 0]);

    $resp = $this->postJson("/api/orders/{$order->id}/rebuy", [], $this->auth)->json();

    expect($resp['data']['added'])->toBe(1)
        ->and((int) CartItem::where('user_id', $this->userId)->where('sku_id', $sku->id)->value('quantity'))->toBe(3);
});

// T-004：他人订单不可复购
test('TC-CENTER-009 他人订单再次购买返回 404', function () {
    $order = seedOrder($this->userId, Order::STATUS_COMPLETED, items: []);

    $cap = app(CaptchaService::class)->generate();
    $otherAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/register', [
        'username' => 'other'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token')];

    $this->postJson("/api/orders/{$order->id}/rebuy", [], $otherAuth)->assertStatus(404);
});

// T-004：V1.0 status 参数向后兼容
test('TC-CENTER-010 status 参数保持向后兼容', function () {
    seedOrder($this->userId, Order::STATUS_PAID);
    seedOrder($this->userId, Order::STATUS_SHIPPED);

    $resp = $this->getJson('/api/orders?status=paid', $this->auth)->json();

    expect($resp['data']['pagination']['total'])->toBe(1)
        ->and($resp['data']['list'][0]['status'])->toBe(Order::STATUS_PAID);
});

// T-004：数据隔离（只看自己的订单）
test('TC-CENTER-011 只能看到自己的订单', function () {
    $other = createTestUser('othercenter');
    seedOrder($this->userId, Order::STATUS_PAID);
    seedOrder($other->id, Order::STATUS_PAID);

    $resp = $this->getJson('/api/orders', $this->auth)->json();

    expect($resp['data']['pagination']['total'])->toBe(1);
});
