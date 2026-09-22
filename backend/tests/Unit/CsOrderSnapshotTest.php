<?php

use App\Models\CsTicket;
use App\Models\CsTicketType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Refund;
use App\Models\Shipping;
use App\Models\ShippingTrace;
use App\Models\User;
use App\Services\Cs\CsTicketService;
use App\Support\MediaUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * CS-202：工单关联订单快照 —— 单元层（CsTicketService::orderSnapshot）
 *
 * 直接调用服务，校验：字段完整性 / 空值容错 / 脱敏规则 / 实时性 / 行实付口径 / 查询次数（无 N+1）。
 */
beforeEach(function () {
    $this->service = app(CsTicketService::class);
    $this->buyer = createTestUser('cs202buyer');

    $this->type = CsTicketType::create([
        'name' => '物流问题', 'code' => 'logistics', 'require_order' => false,
        'sort' => 1, 'is_active' => true,
    ]);

    $this->order = Order::create([
        'order_no' => 'CS20260917000001',
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

function cs202Ticket(User $user, ?Order $order = null): CsTicket
{
    return app(CsTicketService::class)->createTicket($user, [
        'type_id' => CsTicketType::where('code', 'logistics')->value('id'),
        'title' => '订单咨询',
        'content' => '物流到哪了',
        'order_id' => $order?->id,
    ]);
}

function cs202Item(Order $order, string $title, float $price, int $qty, float $couponShare = 0, float $promotionShare = 0): OrderItem
{
    return OrderItem::create([
        'order_id' => $order->id,
        'product_id' => null,
        'sku_id' => null,
        'product_title' => $title,
        'sku_specs' => ['颜色' => '黑'],
        'sku_image' => '/storage/sku/'.$title.'.png',
        'price' => $price,
        'quantity' => $qty,
        'total_amount' => $price * $qty,
        'coupon_share' => $couponShare,
        'promotion_share' => $promotionShare,
    ]);
}

/** @param array<int, array{context: string, at: string}> $traces */
function cs202Shipping(Order $order, array $traces = []): Shipping
{
    // shippings 有 (company_code, tracking_no) 唯一约束，运单号需递增
    static $seq = 0;
    $seq++;

    $shipping = Shipping::create([
        'order_id' => $order->id,
        'company_code' => 'SF',
        'company_name' => '顺丰速运',
        'tracking_no' => 'SF1234567890'.$seq,
        'trace_status' => Shipping::TRACE_IN_TRANSIT,
        'shipped_at' => now()->subDays(2),
    ]);

    foreach ($traces as $trace) {
        ShippingTrace::create([
            'shipping_id' => $shipping->id,
            'context' => $trace['context'],
            'occurred_at' => $trace['at'],
        ]);
    }

    return $shipping;
}

function cs202Refund(Order $order, string $no, float $amount, string $status, ?string $remark = null): Refund
{
    return Refund::create([
        'refund_no' => $no,
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'user_id' => $order->user_id,
        'amount' => $amount,
        'status' => $status,
        'admin_remark' => $remark,
    ]);
}

// ---------- 1. 字段完整性 ----------

it('快照字段完整：订单 / 商品 / 脱敏收货 / 物流 / 退款 / 跳转参数', function () {
    cs202Item($this->order, '蓝牙耳机', 129.00, 2, 5.00);
    cs202Shipping($this->order, [
        ['context' => '已揽收', 'at' => now()->subDays(2)->toDateTimeString()],
        ['context' => '运输中（深圳）', 'at' => now()->subDay()->toDateTimeString()],
    ]);
    cs202Refund($this->order, 'RF2026010101', 20.00, Refund::STATUS_PENDING, '已核实');

    $snapshot = $this->service->orderSnapshot(cs202Ticket($this->buyer, $this->order), forStaff: true);

    expect($snapshot)->toBeArray()
        ->toHaveKeys(['order_id', 'order_no', 'status', 'status_label', 'pay_amount', 'created_at',
            'item_count', 'items', 'address', 'shipping', 'refunds', 'jump'])
        ->and($snapshot['order_no'])->toBe('CS20260917000001')
        ->and($snapshot['status'])->toBe(Order::STATUS_SHIPPED)
        ->and($snapshot['status_label'])->toBe('已发货')
        ->and($snapshot['pay_amount'])->toBe('258.00')
        ->and($snapshot['item_count'])->toBe(2);

    expect($snapshot['items'][0])->toHaveKeys([
        'product_id', 'sku_id', 'title', 'specs', 'image', 'price', 'quantity', 'total_amount', 'payable_amount',
    ]);
    expect($snapshot['address'])->toHaveKeys(['contact_name', 'phone_masked', 'full_address'])
        ->and($snapshot['address']['contact_name'])->toBe('张三');
    expect($snapshot['shipping'])->toHaveKeys([
        'company_code', 'company_name', 'tracking_no', 'trace_status', 'shipped_at', 'delivered_at', 'latest_trace',
    ]);
    expect($snapshot['refunds'][0])->toHaveKeys(['refund_no', 'amount', 'status', 'created_at', 'admin_remark']);
    expect($snapshot['jump'])->toHaveKeys(['order_id', 'order_no', 'latest_refund_no'])
        ->and($snapshot['jump']['latest_refund_no'])->toBe('RF2026010101');
});

// ---------- 2. 无订单 ----------

it('无关联订单的工单返回 null（不报错）', function () {
    $ticket = cs202Ticket($this->buyer, null);

    expect($ticket->order_id)->toBeNull()
        ->and($this->service->orderSnapshot($ticket))->toBeNull();
});

it('订单被物理删除后返回 null（工单仍可打开）', function () {
    $ticket = cs202Ticket($this->buyer, $this->order);
    $this->order->delete();

    expect($this->service->orderSnapshot($ticket->fresh()))->toBeNull();
});

// ---------- 3. 脱敏 ----------

it('手机号脱敏：11 位保留前 3 后 4，短号保守，空值空串，且明文绝不出现在快照中', function () {
    $cases = [
        '13800001234' => '138****1234',   // 11 位手机
        '07551234' => '07****34',         // 8 位固话
        '1234' => '****',                 // 4 位及以下全遮
        '' => '',
        null => '',
    ];

    $ticket = cs202Ticket($this->buyer, $this->order);

    foreach ($cases as $phone => $expected) {
        $this->order->update(['address_snapshot' => [
            'contact_name' => '张三',
            'contact_phone' => $phone,
            'full_address' => '地址',
        ]]);

        $snapshot = $this->service->orderSnapshot($ticket->fresh());
        $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE);

        expect($snapshot['address']['phone_masked'])->toBe($expected, "手机 {$phone} 脱敏结果不符");

        if ($phone !== null && $phone !== '' && $phone !== '1234') {
            expect($encoded)->not->toContain($phone, "明文手机 {$phone} 泄漏到快照中");
        }
    }
});

// ---------- 4. 物流 ----------

it('未发货（无发货记录）时 shipping 为 null，不报错', function () {
    cs202Item($this->order, '商品', 100.00, 1);

    $snapshot = $this->service->orderSnapshot(cs202Ticket($this->buyer, $this->order));

    expect($snapshot['shipping'])->toBeNull();
});

it('有发货记录但无轨迹时 shipping 有值且 latest_trace 为 null', function () {
    $shipping = cs202Shipping($this->order, []);

    $snapshot = $this->service->orderSnapshot(cs202Ticket($this->buyer, $this->order));

    expect($snapshot['shipping']['tracking_no'])->toBe($shipping->tracking_no)
        ->and($snapshot['shipping']['company_name'])->toBe('顺丰速运')
        ->and($snapshot['shipping']['latest_trace'])->toBeNull();
});

it('物流轨迹取最新一条（按 occurred_at 而非插入顺序）', function () {
    // 故意乱序插入：最新的一条先插
    cs202Shipping($this->order, [
        ['context' => '已签收', 'at' => now()->toDateTimeString()],
        ['context' => '已揽收', 'at' => now()->subDays(3)->toDateTimeString()],
        ['context' => '运输中', 'at' => now()->subDay()->toDateTimeString()],
    ]);

    $snapshot = $this->service->orderSnapshot(cs202Ticket($this->buyer, $this->order));

    expect($snapshot['shipping']['latest_trace']['context'])->toBe('已签收');
});

// ---------- 5. 退款 ----------

it('退款记录包含该订单全部退款单且按最新在前', function () {
    cs202Refund($this->order, 'RF-A', 10.00, Refund::STATUS_SUCCESS);
    cs202Refund($this->order, 'RF-B', 20.00, Refund::STATUS_REJECTED);

    $snapshot = $this->service->orderSnapshot(cs202Ticket($this->buyer, $this->order));

    expect($snapshot['refunds'])->toHaveCount(2)
        ->and($snapshot['refunds'][0]['refund_no'])->toBe('RF-B')
        ->and($snapshot['refunds'][1]['refund_no'])->toBe('RF-A');
});

it('买家端快照不含客服内部字段与后台跳转参数', function () {
    cs202Refund($this->order, 'RF-A', 10.00, Refund::STATUS_PENDING, '内部处理备注');

    $snapshot = $this->service->orderSnapshot(cs202Ticket($this->buyer, $this->order), forStaff: false);

    expect($snapshot)->not->toHaveKey('jump')
        ->and($snapshot['refunds'][0])->not->toHaveKey('admin_remark')
        ->and(json_encode($snapshot, JSON_UNESCAPED_UNICODE))->not->toContain('内部处理备注');
});

// ---------- 6. 行实付口径 ----------

it('行实付 = 单价×数量 − 券分摊 − 满减分摊（与退款口径一致）', function () {
    cs202Item($this->order, 'A', 100.00, 2, 15.00, 5.00);   // 200 - 15 - 5 = 180
    cs202Item($this->order, 'B', 50.00, 1);                // 50

    $snapshot = $this->service->orderSnapshot(cs202Ticket($this->buyer, $this->order));

    expect($snapshot['items'][0]['payable_amount'])->toBe(180.0)
        ->and($snapshot['items'][1]['payable_amount'])->toBe(50.0)
        ->and($snapshot['item_count'])->toBe(3);
});

// ---------- 7. 实时性（AC-202.4） ----------

it('快照实时读取：订单状态变更后立即反映，无冗余存储', function () {
    $ticket = cs202Ticket($this->buyer, $this->order);

    expect($this->service->orderSnapshot($ticket)['status'])->toBe(Order::STATUS_SHIPPED);

    $this->order->update(['status' => Order::STATUS_COMPLETED]);

    $after = $this->service->orderSnapshot($ticket->fresh());
    expect($after['status'])->toBe(Order::STATUS_COMPLETED)
        ->and($after['status_label'])->toBe('已完成');
});

// ---------- 8. 无 N+1（AC-202.5） ----------

it('查询次数受控：商品/轨迹/发货记录增长均不增加查询数（预加载生效，无 N+1）', function () {
    $ticket = cs202Ticket($this->buyer, $this->order);

    $measure = function (int $itemCount, int $traceCount, int $shippingCount) use ($ticket): int {
        OrderItem::query()->delete();
        ShippingTrace::query()->delete();
        Shipping::query()->delete();

        for ($i = 0; $i < $itemCount; $i++) {
            cs202Item($this->order, '商品'.$i, 10.00, 1);
        }

        for ($s = 0; $s < $shippingCount; $s++) {
            $traces = [];
            for ($i = 0; $i < $traceCount; $i++) {
                $traces[] = ['context' => '轨迹'.$i, 'at' => now()->subMinutes($i)->toDateTimeString()];
            }
            cs202Shipping($this->order, $traces);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service->orderSnapshot($ticket->fresh());
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $small = $measure(2, 2, 1);
    $large = $measure(12, 15, 3);

    expect($large)->toBe($small, "查询数随行数增长（{$small} → {$large}），存在 N+1");
});

it('快照不再查询 products 表（旧实现用 Product::find 逐项补首图，属额外查询）', function () {
    cs202Item($this->order, 'A', 10.00, 1);
    cs202Item($this->order, 'B', 20.00, 1);
    cs202Shipping($this->order, [['context' => '已揽收', 'at' => now()->toDateTimeString()]]);

    $ticket = cs202Ticket($this->buyer, $this->order);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->service->orderSnapshot($ticket);
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    foreach ($log as $entry) {
        expect(str_contains((string) $entry['query'], 'products'))
            ->toBeFalse('订单快照不应查询 products 表：'.$entry['query']);
    }

    // 商品图直接取订单明细快照（order_items.sku_image）；出口由 MediaPath cast 拼上域名
    expect($this->service->orderSnapshot($ticket)['items'][0]['image'])
        ->toBe(MediaUrl::to('/storage/sku/A.png'));
});
