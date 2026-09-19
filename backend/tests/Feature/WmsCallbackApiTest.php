<?php

use App\Jobs\Wms\ProcessWmsCallbackJob;
use App\Models\FulfillmentOrder;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Shipping;
use App\Models\ShippingPackage;
use App\Models\SysOperationLog;
use App\Models\SysUser;
use App\Models\Warehouse;
use App\Models\WmsApiLog;
use App\Models\WmsCallbackDedup;
use App\Models\WmsConfig;
use App\Services\Common\CaptchaService;
use App\Services\Wms\Adapters\Cainiao\Signature;
use App\Services\Wms\Callback\CallbackDeduplicator;
use App\Services\Wms\Callback\CallbackDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * WMS 回调链路（WMS 计划 P3 / §4.1）
 *
 * 覆盖：公开入口安全层（provider 白名单 / JSON / 定位配置 / 验签 / IP / 防重放）、
 * inbound 留痕脱敏、异步层幂等、confirm 多包裹落档 + 订单发货、status 状态流转、
 * 单据不存在告警、Job 重试耗尽告警、命令补录。
 */

beforeEach(function () {
    seedRoles();
    config(['app.debug' => true, 'app.url' => 'https://shop.test']);
    Cache::flush();

    $this->seed(\Database\Seeders\ExpressCompanySeeder::class);

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    $this->user = createTestUser('cbusr');
    $this->userAuth = ['Authorization' => 'Bearer '.$this->user->createToken('cb')->plainTextToken];

    $this->sku = createTestSku(stock: 20, price: '50.00');
});

/** 建仓 + WMS 配置（app_key=TEST_KEY / app_secret=TEST_SECRET），返回发货单（行 SKU-FF × 2） */
function p3Prepare(string $status = FulfillmentOrder::STATUS_PUSHED, array $foAttrs = []): FulfillmentOrder
{
    $warehouse = Warehouse::create(['code' => 'WH_CB_'.uniqid(), 'name' => '回调测试仓', 'status' => 1]);

    $config = WmsConfig::create(array_merge([
        'warehouse_id' => $warehouse->id,
        'provider' => 'cainiao',
        'enabled' => true,
        'auto_push' => true,
        'auto_push_return' => false,
        'push_retry_times' => 3,
        'sku_mapping_mode' => 'same',
        'app_key' => 'TEST_KEY',
        'api_env' => 'sandbox',
        'warehouse_code' => 'CN-WH',
        'remark' => '',
        'callback_token' => 'ctoken'.bin2hex(random_bytes(12)),
    ]));
    $config->app_secret = 'TEST_SECRET';
    $config->save();

    $order = Order::create([
        'order_no' => 'CS'.now()->format('Ymd').random_int(1000000000, 9999999999),
        'user_id' => test()->user->id,
        'status' => Order::STATUS_PENDING_SHIP,
        'total_amount' => 100, 'pay_amount' => 100, 'discount_amount' => 0,
        'promotion_discount' => 0, 'freight_amount' => 0,
        'address_snapshot' => [
            'contact_name' => '张三', 'contact_phone' => '13800000000',
            'full_address' => '广东省深圳市南山区科技路 1 号',
        ],
        'warehouse_id' => $warehouse->id,
    ]);

    $fo = FulfillmentOrder::create(array_merge([
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'outbound_no' => 'FO'.now()->format('Ymd').random_int(1000000000, 9999999999),
        'warehouse_id' => $warehouse->id,
        'provider' => 'cainiao',
        'status' => $status,
        'buyer_info' => $order->address_snapshot,
        'extend' => [],
    ], $foAttrs));

    $fo->items()->create([
        'sku_id' => null,
        'platform_sku_code' => 'SKU-FF',
        'wms_sku_code' => 'W-FF',
        'product_name' => '回调测试商品',
        'qty' => 2,
        'shipped_qty' => 0,
    ]);

    return $fo->load('items');
}

/**
 * 构造已签名的回调请求 URL（sign 放 **query**，与奇门同构——body 全程不被 sign 污染，
 * 服务端「系统参数 = query + body 顶层标量」两侧组装一致）。
 */
function p3CallbackUrl(array $payload, string $secret = 'TEST_SECRET'): string
{
    $raw = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

    $scalars = array_filter($payload, 'is_scalar');

    $sign = app(Signature::class)->sign($scalars, $secret, $raw, 'md5');

    return '/api/wms/callback/cainiao?'.http_build_query(['sign' => $sign, 'sign_method' => 'md5']);
}

/** POST 一条已签名回调，返回响应 JSON（内部已 assertOk） */
function p3Post(array $payload, string $secret = 'TEST_SECRET'): array
{
    $raw = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

    return test()->call('POST', p3CallbackUrl($payload, $secret), [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)
        ->assertOk()->json();
}

/** 构造 confirm 推送报文（双包裹，含行项目实发数量） */
function p3ConfirmPayload(FulfillmentOrder $fo): array
{
    return [
        'method' => 'taobao.qimen.deliveryorder.confirm',
        'timestamp' => '2026-09-20 10:00:00',
        'app_key' => 'TEST_KEY',
        'v' => '2.0',
        'sign_method' => 'md5',
        'customerId' => 'CID1',
        'deliveryOrder' => [
            'deliveryOrderCode' => $fo->outbound_no,
            'deliveryOrderId' => 'CN-OUT-1',
            'status' => 'SHIPPED',
            'receiverInfo' => ['name' => '张三', 'mobile' => '13800000000'],
            'packages' => ['package' => [
                [
                    'logisticsCode' => 'SF', 'logisticsName' => '顺丰速运', 'expressCode' => 'SF999', 'weight' => '1.500',
                    'items' => ['item' => [['itemCode' => 'SKU-FF', 'itemName' => '回调测试商品', 'quantity' => 2]]],
                ],
                ['logisticsCode' => 'YTO', 'expressCode' => 'YTO888', 'weight' => '0.800', 'items' => ['item' => []]],
            ]],
        ],
    ];
}

/** 驱动 Fake 队列里**全部**已入队的回调 Job（重复投递由业务幂等吸收，重复断言安全） */
function p3RunPushedJob(): void
{
    Queue::assertPushed(ProcessWmsCallbackJob::class, function ($j) {
        $j->handle(app(App\Services\Wms\Callback\CallbackDispatcher::class), app(CallbackDeduplicator::class));

        return true;
    });
}

// ==================== 同步层（安全校验） ====================

test('TC-CB-001 不支持的 provider 直接拒绝（不落配置查询）', function () {
    $res = $this->postJson('/api/wms/callback/jd_unknown', ['method' => 'x'])->assertOk()->json();

    expect($res['flag'])->toBe('failure')
        ->and($res['code'])->toBe('PROVIDER_UNSUPPORTED');
});

test('TC-CB-002 报文非 JSON → INVALID_JSON', function () {
    $res = $this->call('POST', '/api/wms/callback/cainiao', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not-json')
        ->assertOk()->json();

    expect($res['flag'])->toBe('failure')
        ->and($res['code'])->toBe('INVALID_JSON');
});

test('TC-CB-003 app_key 无法定位配置 → APP_KEY_NOT_FOUND', function () {
    $res = p3Post(['method' => 'taobao.qimen.deliveryorder.confirm', 'app_key' => 'NOPE']);

    expect($res['flag'])->toBe('failure')
        ->and($res['code'])->toBe('APP_KEY_NOT_FOUND');

    // 无配置也要留痕（纯文字痕迹，供安全审计）
    expect(WmsApiLog::where('direction', 'inbound')->where('error_msg', 'like', '%APP_KEY_NOT_FOUND%')->count())->toBe(1);
});

test('TC-CB-004 缺签名 → SIGN_MISSING；篡改参数 → SIGNATURE_INVALID', function () {
    $fo = p3Prepare();
    config(['wms.callback.providers' => ['cainiao']]);

    // 缺签名
    $noSign = ['method' => 'taobao.qimen.deliveryorder.confirm', 'app_key' => 'TEST_KEY',
        'deliveryOrder' => ['deliveryOrderCode' => $fo->outbound_no, 'status' => 'SHIPPED']];
    $res1 = $this->postJson('/api/wms/callback/cainiao', $noSign)->assertOk()->json();
    expect($res1['code'])->toBe('SIGN_MISSING');

    // 篡改参数（签名用错误 secret 生成）
    $res2 = p3Post(p3ConfirmPayload($fo), 'WRONG_SECRET');
    expect($res2['flag'])->toBe('failure')
        ->and($res2['code'])->toBe('SIGNATURE_INVALID');

    // 拒绝也要留痕
    expect(WmsApiLog::where('direction', 'inbound')->where('success', false)->count())->toBe(2);
});

test('TC-CB-005 IP 白名单：配置后白名单外来源拒绝，空白名单不限制', function () {
    $fo = p3Prepare();
    config(['wms.callback.ip_whitelist' => ['10.0.0.1']]);

    $payload = p3ConfirmPayload($fo);
    // 默认测试请求来自 127.0.0.1，不在白名单
    $res = p3Post($payload);
    expect($res['code'])->toBe('IP_FORBIDDEN');

    // 放开后通过（同 raw 此前被 IP 拒绝、未占防重放坑，可重放）
    config(['wms.callback.ip_whitelist' => []]);
    Queue::fake();
    $res2 = p3Post($payload);
    expect($res2['flag'])->toBe('success');
});

test('TC-CB-006 防重放：同一条原始报文短窗口内重复到达按 success 吞掉且不再入队', function () {
    $fo = p3Prepare();
    Queue::fake();

    $payload = p3ConfirmPayload($fo);
    p3Post($payload);
    p3Post($payload);

    Queue::assertPushed(ProcessWmsCallbackJob::class, 1);

    $log = WmsApiLog::where('direction', 'inbound')->where('api_name', 'callback_replay')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->response_body['remark'] ?? null)->toBe('replay_dropped');
});

test('TC-CB-007 合法 confirm 回调：success 响应、入队一条、inbound 留痕脱敏', function () {
    $fo = p3Prepare();
    Queue::fake();

    $res = p3Post(p3ConfirmPayload($fo));

    expect($res['flag'])->toBe('success')
        ->and($res['code'])->toBe('0');

    Queue::assertPushed(ProcessWmsCallbackJob::class, 1);

    $log = WmsApiLog::where('direction', 'inbound')->where('api_name', 'callback_receive')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->success)->toBeTrue()
        ->and($log->biz_no)->toBe($fo->outbound_no);

    // 脱敏（P2 设计）：收件人手机号保留后 4 位；app_key 有意不掩（排障标识，见 config 注释）
    $masked = json_encode($log->request_body, JSON_UNESCAPED_UNICODE);
    expect($masked)->not->toContain('13800000000')
        ->and($masked)->toContain('*******0000');
});

// ==================== 异步层（业务处理） ====================

test('TC-CB-008 confirm 多包裹：订单发货、主表取首包裹、全量包裹落档', function () {
    $fo = p3Prepare();
    Queue::fake();
    p3Post(p3ConfirmPayload($fo));
    p3RunPushedJob();

    $fo->refresh();
    $order = $fo->order()->first();

    expect($fo->status)->toBe(FulfillmentOrder::STATUS_SHIPPED)
        ->and($fo->tracking_no)->toBe('SF999')
        ->and($fo->carrier_code)->toBe('SF')
        ->and($order->status)->toBe(Order::STATUS_SHIPPED)
        ->and($order->tracking_no)->toBe('SF999');

    // 主表只有一条 Shipping（首包裹）；全量包裹落档两行
    expect(Shipping::where('order_id', $order->id)->count())->toBe(1)
        ->and(ShippingPackage::count())->toBe(2);

    $packages = ShippingPackage::orderBy('sort')->get();
    expect($packages[0]->tracking_no)->toBe('SF999')
        ->and($packages[0]->carrier_name)->toBe('顺丰速运')
        ->and((string) $packages[0]->weight)->toBe('1.500')
        ->and($packages[0]->items[0]['platform_sku_code'])->toBe('SKU-FF')
        ->and($packages[1]->tracking_no)->toBe('YTO888')
        ->and($packages[1]->sort)->toBe(1);

    // 实发数量按回传 items 归集（行 2 件全部实发）
    expect((int) $fo->items()->first()->shipped_qty)->toBe(2);

    // 订单发货走唯一入口：操作日志（status_shipped）操作人为 system → 归 admin 桶
    expect(SysOperationLog::where('module', 'order')->where('action', 'status_shipped')->exists())->toBeTrue();
});

test('TC-CB-009 业务幂等：同单同事件的重复回传（不同 raw）被 dedup 占坑吞掉，不重复发货', function () {
    $fo = p3Prepare();
    Queue::fake();

    // 第一次：timestamp T1
    $p1 = p3ConfirmPayload($fo);
    $p1['timestamp'] = '2026-09-20 10:00:01';
    p3Post($p1);
    p3RunPushedJob();

    // 第二次：同一事件但 timestamp 不同（绕过 raw 防重放），业务幂等应兜住
    $p2 = p3ConfirmPayload($fo);
    $p2['timestamp'] = '2026-09-20 10:05:00';
    p3Post($p2);
    Queue::assertPushed(ProcessWmsCallbackJob::class, 2);
    p3RunPushedJob();

    expect(Shipping::count())->toBe(1)
        ->and(ShippingPackage::count())->toBe(2)
        ->and(WmsCallbackDedup::count())->toBe(1);
});

test('TC-CB-010 status 回传：PICKING/PACKED 流转、EXCEPTION 转人工', function () {
    $fo = p3Prepare(FulfillmentOrder::STATUS_PUSHED);
    Queue::fake();

    $send = function (string $status, ?string $detail = null) use ($fo) {
        $payload = [
            'method' => 'taobao.qimen.deliveryorder.status',
            'timestamp' => '2026-09-20 11:00:00',
            'app_key' => 'TEST_KEY',
            'v' => '2.0',
            'sign_method' => 'md5',
            'deliveryOrderCode' => $fo->outbound_no,
            'status' => $status,
        ];
        if ($detail !== null) {
            $payload['statusDetail'] = $detail;
        }

        p3Post($payload);
    };

    $send('PICKING');
    p3RunPushedJob();
    expect($fo->refresh()->status)->toBe(FulfillmentOrder::STATUS_PICKING);

    $send('PACKED');
    p3RunPushedJob();
    expect($fo->refresh()->status)->toBe(FulfillmentOrder::STATUS_PACKED);

    $send('EXCEPTION', '仓库缺货');
    p3RunPushedJob();
    expect($fo->refresh()->status)->toBe(FulfillmentOrder::STATUS_EXCEPTION)
        ->and($fo->exception_reason)->toBe('仓库缺货');
});

test('TC-CB-011 回传找不到发货单：告警（审计 + 站内信）且按已消费处理不抛错', function () {
    p3Prepare();   // 配置存在（app_key=TEST_KEY 可定位），但单据号不存在
    Queue::fake();

    $payload = [
        'method' => 'taobao.qimen.deliveryorder.confirm',
        'timestamp' => '2026-09-20 12:00:00',
        'app_key' => 'TEST_KEY',
        'v' => '2.0',
        'sign_method' => 'md5',
        'deliveryOrder' => ['deliveryOrderCode' => 'FO-NOT-EXIST', 'status' => 'SHIPPED'],
    ];
    p3Post($payload);
    p3RunPushedJob();

    // 审计告警
    expect(SysOperationLog::where('module', 'wms')->where('action', 'callback_alert')->count())->toBe(1);

    // 站内告警投给持有 wms.order.view 的全部后台账号（P0 起 admin 与 operator 均持有 → 2 条）
    expect(Notification::where('type', 'wms_alert')->where('receiver_type', Notification::RECEIVER_ADMIN)->count())->toBe(2);

    // 单据不存在不可恢复：不占幂等坑（万一稍后单据真的建出来还能处理）
    expect(WmsCallbackDedup::count())->toBe(0);
});

test('TC-CB-012 Job 处理抛异常：释放幂等占坑并向上抛（队列重试）', function () {
    $fo = p3Prepare(FulfillmentOrder::STATUS_PUSHED);
    // 状态机拒绝：已推送的订单已被人为发货且运单号不一致 → markShipped 抛 409
    $fo->order()->first()->update(['status' => Order::STATUS_SHIPPED, 'tracking_no' => 'OTHER999']);

    $payload = p3ConfirmPayload($fo);
    $payload['timestamp'] = '2026-09-20 13:00:00';

    Queue::fake();
    p3Post($payload);
    Queue::assertPushed(ProcessWmsCallbackJob::class, 1);

    $job = null;
    Queue::assertPushed(ProcessWmsCallbackJob::class, function ($j) use (&$job) {
        $job = $j;

        return true;
    });

    expect(fn () => $job->handle(app(CallbackDispatcher::class), app(CallbackDeduplicator::class)))
        ->toThrow(RuntimeException::class);

    // 占坑已释放：对方重推仍可再次处理
    expect(WmsCallbackDedup::count())->toBe(0);
});

test('TC-CB-013 wms:prune-callbacks：清理过期幂等登记，未过期保留', function () {
    WmsCallbackDedup::create([
        'provider' => 'cainiao', 'biz_no' => 'FO-OLD', 'msg_type' => 'confirm',
        'status_key' => 'shipped', 'received_at' => now()->subDays(120),
    ]);
    WmsCallbackDedup::create([
        'provider' => 'cainiao', 'biz_no' => 'FO-NEW', 'msg_type' => 'confirm',
        'status_key' => 'shipped', 'received_at' => now()->subDay(),
    ]);

    $this->artisan('wms:prune-callbacks')->assertSuccessful();

    expect(WmsCallbackDedup::where('biz_no', 'FO-OLD')->exists())->toBeFalse()
        ->and(WmsCallbackDedup::where('biz_no', 'FO-NEW')->exists())->toBeTrue();
});

test('TC-CB-014 后台订单详情输出 wms_fulfillment 只读摘要；无发货单为 null', function () {
    $fo = p3Prepare(FulfillmentOrder::STATUS_PUSH_FAILED, [
        'push_times' => 3,
        'last_push_error' => '连接超时',
        'wms_outbound_no' => null,
    ]);

    $detail = $this->getJson("/api/admin/orders/{$fo->order_id}", $this->adminAuth)->json('data');

    expect($detail['wms_fulfillment'])->toBeArray()
        ->and($detail['wms_fulfillment']['outbound_no'])->toBe($fo->outbound_no)
        ->and($detail['wms_fulfillment']['status'])->toBe('push_failed')
        ->and($detail['wms_fulfillment']['status_label'])->toBe('推送失败')
        ->and($detail['wms_fulfillment']['push_times'])->toBe(3)
        ->and($detail['wms_fulfillment']['last_push_error'])->toBe('连接超时');

    // 无发货单的订单 → null
    $order2 = Order::create([
        'order_no' => 'CS'.now()->format('Ymd').random_int(1000000000, 9999999999),
        'user_id' => $this->user->id,
        'status' => Order::STATUS_PENDING_SHIP,
        'total_amount' => 10, 'pay_amount' => 10, 'discount_amount' => 0,
        'promotion_discount' => 0, 'freight_amount' => 0,
        'address_snapshot' => [], 'warehouse_id' => null,
    ]);
    $detail2 = $this->getJson("/api/admin/orders/{$order2->id}", $this->adminAuth)->json('data');
    expect($detail2['wms_fulfillment'])->toBeNull();
});
