<?php

use App\Models\Order;
use App\Models\Shipping;
use App\Models\ShippingTrace;
use App\Support\Shipping\ShippingChannelInterface;
use App\Support\Shipping\TraceResult;
use App\Support\Shipping\TraceStage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * V1.1 T-045（E03/F07）：轨迹拉取 Job 与渠道适配器
 *
 * 覆盖：① 拉取成功写入轨迹并推导状态；② 重复拉取去重；③ 渠道异常不扩散；
 *       ④ 连续失败标 failed；⑤ 手动重试恢复；⑥ 未配置渠道 Job 安全跳过；
 *       ⑦ 阶段→状态映射单元；⑧ 分批与调用计数。
 */

/** 可编程 Fake 渠道：按需抛异常/返回失败/返回成功结果 */
class T045FakeChannel implements ShippingChannelInterface
{
    public array $calls = [];

    /** 每次调用收到的手机号（V1.1 三期：验证 phone 是否透传到渠道） */
    public array $phones = [];

    public ?Throwable $throw = null;

    public ?TraceResult $result = null;

    public function query(string $companyCode, string $trackingNo, ?string $phone = null): TraceResult
    {
        $this->calls[] = $companyCode.'|'.$trackingNo;
        $this->phones[] = $phone;

        if ($this->throw) {
            throw $this->throw;
        }

        return $this->result ?? TraceResult::fail('fake channel business error');
    }

    public function available(): bool
    {
        return true;
    }
}

function t045FakeChannel(): T045FakeChannel
{
    $fake = new T045FakeChannel;
    app()->instance(ShippingChannelInterface::class, $fake);

    return $fake;
}

/** 建一条指定状态的 shipping 记录 */
function t045Shipping(string $trackingNo, string $traceStatus = Shipping::TRACE_PENDING): Shipping
{
    $user = createTestUser('t045'.uniqid());
    $order = Order::create([
        'order_no' => 'CS045'.uniqid(),
        'user_id' => $user->id,
        'status' => Order::STATUS_SHIPPED,
        'total_amount' => '100.00',
        'freight_amount' => '0.00',
        'pay_amount' => '100.00',
        'address_snapshot' => ['name' => '测试', 'phone' => '13800000000'],
        'express_company' => '顺丰速运',
        'tracking_no' => $trackingNo,
    ]);

    return Shipping::create([
        'order_id' => $order->id,
        'company_code' => 'SF',
        'company_name' => '顺丰速运',
        'tracking_no' => $trackingNo,
        'trace_status' => $traceStatus,
        'shipped_at' => now()->subDay(),
    ]);
}

beforeEach(function () {
    seedRoles();
    config(['services.shipping.max_failures' => 3]);
});

test('TC-TRC-045-01 拉取成功写入轨迹并推导状态', function () {
    $fake = t045FakeChannel();
    $fake->result = TraceResult::ok([
        ['context' => '快件已揽收', 'occurred_at' => now()->subDays(2), 'stage' => TraceStage::PICKUP],
        ['context' => '运输中：深圳→北京', 'occurred_at' => now()->subDay(), 'stage' => TraceStage::IN_TRANSIT],
    ], ['channel' => 'fake']);

    $shipping = t045Shipping('SF045OKTEST01');
    $result = app(\App\Services\Shipping\TracePullService::class)->pull($shipping);

    expect($result)->toBe(\App\Services\Shipping\TracePullService::RESULT_PULLED)
        ->and(ShippingTrace::where('shipping_id', $shipping->id)->count())->toBe(2)
        // 时间倒序（最新在前）
        ->and($shipping->traces()->latest('occurred_at')->first()->context)->toBe('运输中：深圳→北京')
        // 状态推导：pickup/in_transit → in_transit；失败计数清零；raw 报文落库
        ->and($shipping->fresh()->trace_status)->toBe(Shipping::TRACE_IN_TRANSIT)
        ->and($shipping->fresh()->pull_fail_count)->toBe(0)
        ->and(ShippingTrace::first()->raw)->toBe(['channel' => 'fake']);
});

test('TC-TRC-045-02 重复拉取不产生重复轨迹', function () {
    $fake = t045FakeChannel();
    $fake->result = TraceResult::ok([
        ['context' => '快件已揽收', 'occurred_at' => now()->subDay()->startOfSecond(), 'stage' => TraceStage::PICKUP],
    ]);

    $shipping = t045Shipping('SF045OKTEST02');
    $service = app(\App\Services\Shipping\TracePullService::class);
    $service->pull($shipping);
    $service->pull($shipping->refresh());
    $service->pull($shipping->refresh());

    expect(ShippingTrace::where('shipping_id', $shipping->id)->count())->toBe(1);
});

test('TC-TRC-045-03 渠道抛异常不影响其他运单且状态不变', function () {
    $fake = t045FakeChannel();
    $fake->throw = new \RuntimeException('channel timeout');

    $a = t045Shipping('SF045ERR0001');
    $b = t045Shipping('SF045ERR0002', Shipping::TRACE_IN_TRANSIT);
    $service = app(\App\Services\Shipping\TracePullService::class);

    $resultA = $service->pull($a);

    expect($resultA)->toBe(\App\Services\Shipping\TracePullService::RESULT_FAILED)
        // 状态保持原状、轨迹无写入，仅失败计数 +1
        ->and($a->fresh()->trace_status)->toBe(Shipping::TRACE_PENDING)
        ->and($a->fresh()->pull_fail_count)->toBe(1)
        ->and(ShippingTrace::where('shipping_id', $a->id)->count())->toBe(0)
        // 异常不扩散：另一运单仍可正常拉取（失败也走业务失败路径，不抛出）
        ->and($service->pull($b))->toBe(\App\Services\Shipping\TracePullService::RESULT_FAILED)
        ->and($b->fresh()->trace_status)->toBe(Shipping::TRACE_IN_TRANSIT);
});

test('TC-TRC-045-04 连续失败达到上限标记 failed', function () {
    $fake = t045FakeChannel();
    $fake->result = TraceResult::fail('查无此单');
    $shipping = t045Shipping('SF045FAIL001');
    $service = app(\App\Services\Shipping\TracePullService::class);

    $service->pull($shipping->refresh());
    $service->pull($shipping->refresh());
    $service->pull($shipping->refresh());

    expect($shipping->fresh()->trace_status)->toBe(Shipping::TRACE_FAILED)
        ->and($shipping->fresh()->pull_fail_count)->toBe(3)
        // 失败原因固化，便于后台看板展示（T-047）
        ->and($shipping->fresh()->last_fail_message)->toBe('查无此单');
});

test('TC-TRC-045-05 手动重试成功后可恢复', function () {
    $fake = t045FakeChannel();

    // 先制造一条 failed 记录
    $fake->result = TraceResult::fail('查无此单');
    $shipping = t045Shipping('SF045RETRY01');
    $service = app(\App\Services\Shipping\TracePullService::class);
    $service->pull($shipping->refresh());
    $service->pull($shipping->refresh());
    $service->pull($shipping->refresh());
    expect($shipping->fresh()->trace_status)->toBe(Shipping::TRACE_FAILED);

    // 切换为成功（含签收）→ 接口手动重试恢复
    $fake->result = TraceResult::ok([
        ['context' => '快件已签收', 'occurred_at' => now()->subHours(2), 'stage' => TraceStage::DELIVERED],
    ]);
    $cap = app(\App\Services\Common\CaptchaService::class)->generate();
    $auth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    $res = $this->postJson("/api/admin/shippings/{$shipping->id}/pull", [], $auth);

    expect($res->json('code'))->toBe(0)
        ->and($res->json('data.result'))->toBe('pulled')
        ->and($res->json('data.trace_status'))->toBe(Shipping::TRACE_DELIVERED)
        ->and($res->json('data.pull_fail_count'))->toBe(0)
        // 签收回填 delivered_at，且不改变订单主状态
        ->and($shipping->fresh()->delivered_at)->not->toBeNull()
        ->and($shipping->fresh()->order->status)->toBe(Order::STATUS_SHIPPED);

    // 不存在 404
    $this->postJson('/api/admin/shippings/999999/pull', [], $auth)
        ->assertJson(['code' => 40004]);
});

test('TC-TRC-045-06 未配置渠道时 Job 安全跳过', function () {
    // 渠道留空 → NullChannel → available()=false
    config(['services.shipping.channel' => null]);
    app()->forgetInstance(ShippingChannelInterface::class);
    app()->bind(ShippingChannelInterface::class, fn () => new \App\Support\Shipping\NullChannel);

    $shipping = t045Shipping('SF045NULL001');

    $this->artisan('shipping:pull-traces')->expectsOutputToContain('未配置 SHIPPING_CHANNEL')->assertExitCode(0);

    expect($shipping->fresh()->trace_status)->toBe(Shipping::TRACE_PENDING)
        ->and($shipping->fresh()->pull_fail_count)->toBe(0);
});

test('TC-TRC-046-09 用户端订单物流接口（归属权/has_trace/倒序）', function () {
    $user = createTestUser('t046ship'.uniqid());
    $order = Order::create([
        'order_no' => 'CS046'.uniqid(),
        'user_id' => $user->id,
        'status' => Order::STATUS_SHIPPED,
        'total_amount' => '50.00',
        'freight_amount' => '0.00',
        'pay_amount' => '50.00',
        'address_snapshot' => ['name' => '测试', 'phone' => '13800000000'],
        'express_company' => '顺丰速运',
        'tracking_no' => 'SF046TEST09',
    ]);
    $shipping = Shipping::create([
        'order_id' => $order->id,
        'company_code' => 'SF',
        'company_name' => '顺丰速运',
        'tracking_no' => 'SF046TEST09',
        'trace_status' => Shipping::TRACE_IN_TRANSIT,
        'shipped_at' => now()->subDay(),
    ]);
    ShippingTrace::create(['shipping_id' => $shipping->id, 'context' => '轨迹新', 'occurred_at' => now()->subHour()]);
    ShippingTrace::create(['shipping_id' => $shipping->id, 'context' => '轨迹旧', 'occurred_at' => now()->subDay()]);

    $auth = ['Authorization' => 'Bearer '.$user->createToken('t046')->plainTextToken];
    $res = $this->getJson("/api/orders/{$order->id}/shipping", $auth);

    $data = $res->json('data');
    expect($res->json('code'))->toBe(0)
        ->and($data['express_company'])->toBe('顺丰速运')
        ->and($data['tracking_no'])->toBe('SF046TEST09')
        ->and($data['trace_status'])->toBe(Shipping::TRACE_IN_TRANSIT)
        ->and($data['has_trace'])->toBeTrue()
        // 倒序：最新在前
        ->and($data['traces'][0]['context'])->toBe('轨迹新')
        ->and(count($data['traces']))->toBe(2);

    // 他人订单不可见
    $other = createTestUser('t046other'.uniqid());
    $otherAuth = ['Authorization' => 'Bearer '.$other->createToken('x')->plainTextToken];
    $this->getJson("/api/orders/{$order->id}/shipping", $otherAuth)->assertJson(['code' => 40004]);

    // 未发货订单 → data=null
    $pending = Order::create([
        'order_no' => 'CS046P'.uniqid(),
        'user_id' => $user->id,
        'status' => Order::STATUS_PENDING_PAYMENT,
        'total_amount' => '10.00',
        'freight_amount' => '0.00',
        'pay_amount' => '10.00',
        'address_snapshot' => ['name' => '测试', 'phone' => '13800000000'],
    ]);
    $this->getJson("/api/orders/{$pending->id}/shipping", $auth)->assertJsonPath('data', null);
});

test('TC-TRC-045-07 阶段→状态映射（单元）', function () {
    expect(TraceResult::ok([['context' => 'x', 'occurred_at' => now(), 'stage' => TraceStage::PICKUP]])->toTraceStatus())->toBe('in_transit')
        ->and(TraceResult::ok([['context' => 'x', 'occurred_at' => now(), 'stage' => TraceStage::DELIVERING]])->toTraceStatus())->toBe('in_transit')
        ->and(TraceResult::ok([['context' => 'x', 'occurred_at' => now(), 'stage' => TraceStage::IN_TRANSIT], ['context' => 'y', 'occurred_at' => now(), 'stage' => TraceStage::DELIVERED]])->toTraceStatus())->toBe('delivered')
        ->and(TraceResult::fail('empty')->toTraceStatus())->toBeNull();
});

test('TC-TRC-045-08 Command 分批拉取与调用计数（mock 渠道）', function () {
    config(['services.shipping.channel' => 'mock', 'services.shipping.batch_size' => 5, 'services.shipping.batch_delay_ms' => 0]);

    // 计数装饰器包住 MockChannel（单例绑定，command 与断言共享实例）
    $inner = new \App\Support\Shipping\MockChannel;
    $counting = new class($inner) implements ShippingChannelInterface
    {
        public array $calls = [];

        public function __construct(private readonly ShippingChannelInterface $inner) {}

        public function query(string $companyCode, string $trackingNo, ?string $phone = null): TraceResult
        {
            $this->calls[] = $companyCode.'|'.$trackingNo;

            return $this->inner->query($companyCode, $trackingNo, $phone);
        }

        public function available(): bool
        {
            return $this->inner->available();
        }
    };
    app()->forgetInstance(ShippingChannelInterface::class);
    app()->instance(ShippingChannelInterface::class, $counting);

    for ($i = 1; $i <= 12; $i++) {
        t045Shipping('SF045BATCH'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
    }

    $this->artisan('shipping:pull-traces')->assertExitCode(0);

    // 每条运单调用一次渠道（12 条 = 12 次调用），全部 in_transit（mock 无签收轨迹）
    expect(count($counting->calls))->toBe(12)
        ->and(Shipping::where('trace_status', Shipping::TRACE_IN_TRANSIT)->count())->toBe(12)
        ->and(ShippingTrace::count())->toBe(36); // 12 × 3 条
});
