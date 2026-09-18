<?php

use App\Models\CsTicket;
use App\Models\CsTicketType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Refund;
use App\Models\Shipping;
use App\Models\ShippingTrace;
use App\Models\User;
use App\Services\Common\CaptchaService;
use App\Services\Cs\CsTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * CS-202：工单关联订单快照 —— HTTP 集成测试（后台 + 买家端）
 *
 * 鉴权：admin（super_admin，含 cs.ticket.view）走成功路径；买家走注册拿 token。
 * 断言口径：新增 `order_snapshot` 节点；旧 `order` 节点保持兼容形状。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    // 后台 token
    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    // 买家 token
    $cap2 = app(CaptchaService::class)->generate();
    $username = 'cs202'.uniqid();
    $this->buyerAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/register', [
        'username' => $username,
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap2['debug_code'],
        'captcha_id' => $cap2['captcha_id'],
    ])->json('data.token')];
    $this->buyer = User::where('username', $username)->firstOrFail();
    $this->buyer->update(['phone' => '13800001234']);

    $this->type = CsTicketType::create([
        'name' => '物流问题', 'code' => 'logistics', 'require_order' => false,
        'sort' => 1, 'is_active' => true,
    ]);

    $this->order = Order::create([
        'order_no' => 'CS202'.time().rand(1000, 9999),
        'user_id' => $this->buyer->id,
        'status' => Order::STATUS_SHIPPED,
        'total_amount' => 268.00,
        'pay_amount' => 258.00,
        'discount_amount' => 10.00,
        'promotion_discount' => 0,
        'freight_amount' => 0,
        'address_snapshot' => [
            'contact_name' => '张三',
            'contact_phone' => '13800001234',
            'full_address' => '广东省深圳市南山区科技园 1 号',
        ],
    ]);
});

// ---------- 数据工厂 ----------

function cs202ApiTicket(User $user, ?Order $order = null): CsTicket
{
    return app(CsTicketService::class)->createTicket($user, [
        'type_id' => CsTicketType::where('code', 'logistics')->value('id'),
        'title' => '物流咨询',
        'content' => '包裹到哪了',
        'order_id' => $order?->id,
    ]);
}

function cs202ApiItem(Order $order, string $title, float $price, int $qty): OrderItem
{
    return OrderItem::create([
        'order_id' => $order->id, 'product_id' => null, 'sku_id' => null,
        'product_title' => $title, 'sku_specs' => ['颜色' => '黑'],
        'sku_image' => '/storage/sku/'.$title.'.png',
        'price' => $price, 'quantity' => $qty, 'total_amount' => $price * $qty,
        'coupon_share' => 0, 'promotion_share' => 0,
    ]);
}

/** @param array<int, array{context: string, at: string}> $traces */
function cs202ApiShipping(Order $order, array $traces = []): Shipping
{
    static $seq = 0;
    $seq++;

    $shipping = Shipping::create([
        'order_id' => $order->id, 'company_code' => 'SF', 'company_name' => '顺丰速运',
        'tracking_no' => 'SF202609170'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
        'trace_status' => Shipping::TRACE_IN_TRANSIT, 'shipped_at' => now()->subDay(),
    ]);

    foreach ($traces as $trace) {
        ShippingTrace::create([
            'shipping_id' => $shipping->id, 'context' => $trace['context'], 'occurred_at' => $trace['at'],
        ]);
    }

    return $shipping;
}

function cs202ApiRefund(Order $order, string $no, float $amount, string $status, ?string $remark = null): Refund
{
    return Refund::create([
        'refund_no' => $no, 'order_id' => $order->id, 'order_no' => $order->order_no,
        'user_id' => $order->user_id, 'amount' => $amount, 'status' => $status,
        'admin_remark' => $remark,
    ]);
}

// ---------- 集成 ①②：快照存在性与 null 容错 ----------

it('① 后台详情返回完整 order_snapshot（订单/商品/物流/退款/跳转参数）', function () {
    cs202ApiItem($this->order, '蓝牙耳机', 129.00, 2);
    cs202ApiShipping($this->order, [
        ['context' => '已揽收', 'at' => now()->subDays(2)->toDateTimeString()],
        ['context' => '派送中', 'at' => now()->toDateTimeString()],
    ]);
    cs202ApiRefund($this->order, 'RF2026001', 20.00, Refund::STATUS_PENDING, '已核实凭证');

    $ticket = cs202ApiTicket($this->buyer, $this->order);

    $res = $this->withHeaders($this->adminAuth)->getJson('/api/admin/cs/tickets/'.$ticket->id);
    $res->assertOk();

    $snapshot = $res->json('data.order_snapshot');

    expect($snapshot)->toBeArray()
        ->and($snapshot['order_no'])->toBe($this->order->order_no)
        ->and($snapshot['status_label'])->toBe('已发货')
        ->and($snapshot['pay_amount'])->toBe('258.00')
        ->and($snapshot['items'])->toHaveCount(1)
        ->and($snapshot['shipping']['latest_trace']['context'])->toBe('派送中')
        ->and($snapshot['refunds'])->toHaveCount(1)
        ->and($snapshot['refunds'][0]['admin_remark'])->toBe('已核实凭证')
        ->and($snapshot['jump']['latest_refund_no'])->toBe('RF2026001')
        ->and($snapshot['address']['phone_masked'])->toBe('138****1234');
});

it('② 无关联订单的工单 order_snapshot 与 order 均为 null（不报错）', function () {
    $ticket = cs202ApiTicket($this->buyer, null);

    $res = $this->withHeaders($this->adminAuth)->getJson('/api/admin/cs/tickets/'.$ticket->id);
    $res->assertOk();

    expect($res->json('data.order_snapshot'))->toBeNull()
        ->and($res->json('data.order'))->toBeNull();
});

// ---------- 集成 ③：商品清单与订单明细一致 ----------

it('③ 商品清单与订单明细一致（标题/规格/数量/单价/行实付）', function () {
    cs202ApiItem($this->order, '蓝牙耳机', 129.00, 2);
    cs202ApiItem($this->order, '数据线', 29.00, 3);
    $ticket = cs202ApiTicket($this->buyer, $this->order);

    $items = $this->withHeaders($this->adminAuth)
        ->getJson('/api/admin/cs/tickets/'.$ticket->id)->json('data.order_snapshot.items');

    $dbItems = $this->order->items()->orderBy('id')->get();

    expect($items)->toHaveCount(2);
    foreach ($dbItems as $i => $db) {
        expect($items[$i]['title'])->toBe($db->product_title)
            ->and($items[$i]['quantity'])->toBe((int) $db->quantity)
            ->and($items[$i]['price'])->toBe($db->price)
            ->and($items[$i]['specs'])->toBe($db->sku_specs)
            ->and($items[$i]['image'])->toBe($db->sku_image)
            // JSON 往返会把 258.0 归一为 258，比较前统一转 float
            ->and((float) $items[$i]['payable_amount'])->toBe((float) $db->payableAmount());
    }
});

// ---------- 集成 ④：物流最新轨迹 ----------

it('④ 物流轨迹取最新一条（按 occurred_at）', function () {
    cs202ApiShipping($this->order, [
        ['context' => '运输中', 'at' => now()->subDay()->toDateTimeString()],
        ['context' => '已签收', 'at' => now()->toDateTimeString()],
        ['context' => '已揽收', 'at' => now()->subDays(3)->toDateTimeString()],
    ]);
    $ticket = cs202ApiTicket($this->buyer, $this->order);

    $shipping = $this->withHeaders($this->adminAuth)
        ->getJson('/api/admin/cs/tickets/'.$ticket->id)->json('data.order_snapshot.shipping');

    expect($shipping['company_name'])->toBe('顺丰速运')
        ->and($shipping['trace_status'])->toBe(Shipping::TRACE_IN_TRANSIT)
        ->and($shipping['latest_trace']['context'])->toBe('已签收');
});

it('④-b 未发货订单 shipping 为 null', function () {
    cs202ApiItem($this->order, '商品', 100.00, 1);
    $ticket = cs202ApiTicket($this->buyer, $this->order);

    expect($this->withHeaders($this->adminAuth)->getJson('/api/admin/cs/tickets/'.$ticket->id)
        ->json('data.order_snapshot.shipping'))->toBeNull();
});

// ---------- 集成 ⑤：退款记录 ----------

it('⑤ 退款记录包含该订单全部退款单', function () {
    cs202ApiRefund($this->order, 'RF-1', 10.00, Refund::STATUS_SUCCESS);
    cs202ApiRefund($this->order, 'RF-2', 25.00, Refund::STATUS_PENDING);
    $ticket = cs202ApiTicket($this->buyer, $this->order);

    $refunds = $this->withHeaders($this->adminAuth)
        ->getJson('/api/admin/cs/tickets/'.$ticket->id)->json('data.order_snapshot.refunds');

    expect($refunds)->toHaveCount(2)
        ->and(collect($refunds)->pluck('refund_no')->sort()->values()->all())->toBe(['RF-1', 'RF-2'])
        ->and(collect($refunds)->pluck('amount')->sort()->values()->all())->toBe(['10.00', '25.00']);
});

// ---------- 集成 ⑥：买家端不含内部字段 ----------

it('⑥ 买家端快照不含客服内部字段（admin_remark）与后台跳转参数（jump）', function () {
    cs202ApiRefund($this->order, 'RF-9', 15.00, Refund::STATUS_PENDING, '客服内部处理意见');
    $ticket = cs202ApiTicket($this->buyer, $this->order);

    $res = $this->withHeaders($this->buyerAuth)->getJson('/api/cs/tickets/'.$ticket->id);
    $res->assertOk();

    $snapshot = $res->json('data.order_snapshot');

    expect($snapshot)->toBeArray()
        ->and($snapshot)->not->toHaveKey('jump')
        ->and($snapshot['refunds'][0])->not->toHaveKey('admin_remark');

    // 明文内部备注不得出现在买家端响应正文
    $res->assertDontSee('客服内部处理意见', false);
});

// ---------- 集成 ⑦：旧契约兼容 ----------

it('⑦ 旧 order 节点保持兼容形状（order_no/status/pay_amount/created_at/product_image）', function () {
    cs202ApiItem($this->order, '首图商品', 129.00, 1);
    $ticket = cs202ApiTicket($this->buyer, $this->order);

    $legacy = $this->withHeaders($this->adminAuth)
        ->getJson('/api/admin/cs/tickets/'.$ticket->id)->json('data.order');

    expect(array_keys($legacy))->toBe(['order_no', 'status', 'pay_amount', 'created_at', 'product_image'])
        ->and($legacy['order_no'])->toBe($this->order->order_no)
        ->and($legacy['pay_amount'])->toBe('258.00')
        ->and($legacy['product_image'])->toBe('/storage/sku/首图商品.png');
});

// ---------- 集成 ⑧：脱敏 ----------

it('⑧ 快照内收货手机仅保留尾号，明文不出现在快照中（两端一致）', function () {
    $ticket = cs202ApiTicket($this->buyer, $this->order);

    foreach ([['admin', $this->adminAuth, '/api/admin/cs/tickets/'], ['buyer', $this->buyerAuth, '/api/cs/tickets/']] as [$who, $auth, $prefix]) {
        $res = $this->withHeaders($auth)->getJson($prefix.$ticket->id);
        $res->assertOk();

        $snapshot = $res->json('data.order_snapshot');

        expect($snapshot['address']['phone_masked'])->toBe('138****1234', "{$who} 端脱敏结果不符")
            ->and(json_encode($snapshot, JSON_UNESCAPED_UNICODE))->not->toContain('13800001234', "{$who} 端快照泄漏明文手机");
    }
});

// ---------- 集成 ⑨：实时性 ----------

it('⑨ 快照实时读取：订单状态变更后同一接口立即反映', function () {
    $ticket = cs202ApiTicket($this->buyer, $this->order);
    $url = '/api/admin/cs/tickets/'.$ticket->id;

    expect($this->withHeaders($this->adminAuth)->getJson($url)->json('data.order_snapshot.status'))
        ->toBe(Order::STATUS_SHIPPED);

    $this->order->update(['status' => Order::STATUS_COMPLETED]);

    $after = $this->withHeaders($this->adminAuth)->getJson($url)->json('data.order_snapshot');
    expect($after['status'])->toBe(Order::STATUS_COMPLETED)
        ->and($after['status_label'])->toBe('已完成');
});

// ---------- 权限 ----------

it('无 cs.ticket.view 权限的角色访问详情仍被拒（本任务未放宽权限）', function () {
    $cap = app(CaptchaService::class)->generate();
    $operatorAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'operator', 'password' => 'Operator@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    $ticket = cs202ApiTicket($this->buyer, $this->order);

    $this->withHeaders($operatorAuth)->getJson('/api/admin/cs/tickets/'.$ticket->id)
        ->assertForbidden()
        ->assertJsonPath('code', 40003);
});
