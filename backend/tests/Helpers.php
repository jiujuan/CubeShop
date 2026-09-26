<?php

use App\Jobs\Wms\ProcessWmsCallbackJob;
use App\Jobs\Wms\PushOutboundJob;
use App\Jobs\Wms\PushReturnInboundJob;
use App\Models\Category;
use App\Models\FulfillmentOrder;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ReturnInboundOrder;
use App\Models\Shipping;
use App\Models\ShippingPackage;
use App\Models\UserAddress;
use App\Models\Warehouse;
use App\Models\WmsConfig;
use App\Models\WmsApiLog;
use App\Services\Order\OrderService;
use App\Services\Wms\Callback\CallbackDeduplicator;
use App\Services\Wms\Callback\CallbackDispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * 测试数据工厂辅助（TestPlan v1.0）
 *
 * sqlite :memory: + 外键约束开启，因此按 Category → Product → SKU → Inventory 链路创建。
 */
if (! function_exists('seedRoles')) {
    /** Feature 测试前置：角色与权限码种子（注册分配 customer 角色、后台接口校验权限） */
    function seedRoles(): void
    {
        test()->seed(\Database\Seeders\RolePermissionSeeder::class);
    }
}

if (! function_exists('seedDemoProducts')) {
    /** Feature 测试前置：演示分类与商品种子 */
    function seedDemoProducts(): void
    {
        test()->seed(\Database\Seeders\ProductSeeder::class);
    }
}

if (! function_exists('createTestSku')) {
    function createTestSku(int $stock = 10, string $price = '10.00', int $status = 1, int $productStatus = 1): ProductSku
    {
        $category = Category::create(['parent_id' => 0, 'name' => '测试分类'.uniqid(), 'sort' => 0, 'status' => 1]);
        $product = Product::create([
            'category_id' => $category->id,
            'title' => '测试商品'.uniqid(),
            'price' => $price,
            'status' => $productStatus,
        ]);
        $sku = ProductSku::create([
            'product_id' => $product->id,
            'sku_code' => 'SKU-'.uniqid(),
            'specs' => ['规格' => '标准'],
            'price' => $price,
            'status' => $status,
        ]);
        Inventory::create(['sku_id' => $sku->id, 'stock' => $stock, 'locked_stock' => 0]);

        return $sku;
    }
}

if (! function_exists('createPaidOrder')) {
    /** 已支付订单（含成功支付单，供原路退回使用） */
    function createPaidOrder(string $price = '100.00'): array
    {
        $user = createTestUser();
        $sku = createTestSku(stock: 20, price: $price);
        \App\Models\CartItem::create(['user_id' => $user->id, 'sku_id' => $sku->id, 'quantity' => 1]);
        $address = UserAddress::create([
            'user_id' => $user->id,
            'contact_name' => 'a', 'contact_phone' => 'b',
            'province' => 'p', 'city' => 'c', 'district' => 'd', 'detail_address' => 'e',
        ]);

        $service = app(OrderService::class);
        $order = $service->createFromCart($user->id, $address->id, null, null);
        $order = $service->transitionTo($order, Order::STATUS_PAID);

        // 真实「已支付」订单必须存在成功支付单（原路退回依据）；Phase 3 executeChannelRefund 依赖它
        \App\Models\Payment::create([
            'payment_no' => 'PAY'.strtoupper((string) \Illuminate\Support\Str::random(16)),
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'user_id' => $user->id,
            'channel' => \App\Models\Payment::CHANNEL_BALANCE,
            'amount' => $order->pay_amount,
            'status' => \App\Models\Payment::STATUS_SUCCESS,
            'biz_type' => \App\Models\Payment::BIZ_TYPE_ORDER,
            'biz_no' => $order->order_no,
            'paid_at' => now(),
        ]);

        return [$user, $sku, $order];
    }
}

if (! function_exists('createTestUser')) {
    /** 创建买家（表 users；V1.1 用户表拆分后买家不再使用 SysUser） */
    function createTestUser(string $username = 'testuser'): \App\Models\User
    {
        return \App\Models\User::create([
            'username' => $username.uniqid(),
            'password' => \Illuminate\Support\Facades\Hash::make('Test@1234'),
            'nickname' => '测试用户',
            'status' => 1,
        ]);
    }
}

if (! function_exists('createTestCategory')) {
    /** 创建分类并返回 id（PG 序列不随事务回滚，禁止硬编码 category_id=1） */
    function createTestCategory(): int
    {
        return \App\Models\Category::create([
            'parent_id' => 0, 'name' => '分类'.uniqid(), 'sort' => 0, 'status' => 1,
        ])->id;
    }
}

/*
 * P2-11 测试辅助：对外 public_id（ULID）↔ 内部 int 主键解析。
 * PublicId::resolve 同时兼容历史 int 主键与 public_id，因此对任意入参包裹均安全。
 */
if (! function_exists('resolvePid')) {
    /** 把对外 public_id（或历史 int）解析为内部 int 主键；解析不出返回 null */
    function resolvePid(string $scope, $value): ?int
    {
        return \App\Support\PublicId::resolve($scope, $value);
    }
}

if (! function_exists('oid')) {
    function oid($value): ?int
    {
        return \App\Support\PublicId::resolve(\App\Support\PublicId::SCOPE_ORDER, $value);
    }
}

if (! function_exists('pid')) {
    function pid($value): ?int
    {
        return \App\Support\PublicId::resolve(\App\Support\PublicId::SCOPE_PRODUCT, $value);
    }
}

if (! function_exists('rid')) {
    function rid($value): ?int
    {
        return \App\Support\PublicId::resolve(\App\Support\PublicId::SCOPE_ORDER_ITEM, $value);
    }
}

if (! function_exists('rfid')) {
    /** 退款单：按 public_id / 历史 int 解析为内部 int 主键 */
    function rfid($value): ?int
    {
        return \App\Support\PublicId::resolve(\App\Support\PublicId::SCOPE_REFUND, $value);
    }
}

if (! function_exists('tid')) {
    /** 客服工单：按 public_id / 历史 int 解析为内部 int 主键 */
    function tid($value): ?int
    {
        return \App\Support\PublicId::resolve(\App\Support\PublicId::SCOPE_TICKET, $value);
    }
}

/*
 * WMS 计划 P7（联调/演练）辅助：构造「已签名」回调请求。
 *
 * 与 `WmsCallbackApiTest` 里的 p3CallbackUrl/p3Post 同构——sign 放 **query**，
 * body 全程不被 sign 污染（服务端「系统参数 = query 顶层标量 + body 顶层标量」两侧一致）。
 * 抽到 Helpers 是为了让 UAT 正向链路、异常演练、性能压测三个套件共用同一算法，
 * 避免「演练里签名算法和被测代码自己算的不一样」这类假绿。
 */
if (! function_exists('wmsSignedCallbackUrl')) {
    /**
     * @param  array<string, mixed>  $payload  报文（含 method/app_key 等顶层标量）
     * @param  string  $secret  WmsConfig.app_secret 明文
     * @param  array<string, scalar>  $extraQuery  额外 query（如 token）
     */
    function wmsSignedCallbackUrl(array $payload, string $secret, array $extraQuery = [], string $provider = 'cainiao'): string
    {
        $raw = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

        $params = $extraQuery;
        foreach ($payload as $k => $v) {
            if (is_scalar($v) && (string) $v !== '') {
                $params[$k] = (string) $v;
            }
        }

        $sign = app(\App\Services\Wms\Adapters\Cainiao\Signature::class)->sign($params, $secret, $raw, 'md5');

        return "/api/wms/callback/{$provider}?".http_build_query($extraQuery + ['sign' => $sign, 'sign_method' => 'md5']);
    }
}

if (! function_exists('wmsPostCallback')) {
    /**
     * 投递一条已签名回调，返回响应 JSON（HTTP 恒 200，语义看 flag/code）。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    function wmsPostCallback(array $payload, string $secret = 'TEST_SECRET', array $extraQuery = [], string $provider = 'cainiao'): array
    {
        $raw = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        $url = wmsSignedCallbackUrl($payload, $secret, $extraQuery, $provider);

        $res = test()->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw);

        // 限流命中时是 429，语义不同于业务 failure，交给用例自己断言
        if ($res->getStatusCode() !== 200) {
            return ['http_status' => $res->getStatusCode(), 'flag' => 'failure', 'code' => 'HTTP_'.$res->getStatusCode()];
        }

        return (array) $res->json();
    }
}

if (! function_exists('fid')) {
    /** 评价没有 SCOPE 常量，直接按 public_id / 历史 int 解析 */
    function fid($value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value) || $value === '') {
            return null;
        }

        return \App\Models\Review::query()->where('public_id', $value)
            ->when(ctype_digit($value) && strlen($value) <= 19, fn ($q) => $q->orWhere('id', (int) $value))
            ->first()?->id;
    }
}

/*
 * WMS 计划 P7（沙箱联调 + 异常演练）公共脚手架。
 *
 * 正向链路（WmsUatForwardFlowTest）与异常演练（WmsExceptionDrillTest）共用同一套
 * 「建仓 → 下单支付 → 推送 → 回传」helper，保证演练注入的异常与联调跑的是同一条链路。
 * 沙箱凭证到位后，只需把 wmsUatGatewaySuccess() 换成真实网关即可整体重跑。
 */
define('WMS_UAT_APP_KEY', 'UAT_KEY');
define('WMS_UAT_APP_SECRET', 'UAT_SECRET');

/** 建仓 + 凭证齐备的菜鸟配置（联调口径：enabled + auto_push + auto_push_return） */
function wmsUatConfig(array $attrs = []): WmsConfig
{
    $warehouse = Warehouse::create(['code' => 'WH_UAT_'.uniqid(), 'name' => '联调仓', 'status' => 1]);

    $config = WmsConfig::create(array_merge([
        'warehouse_id' => $warehouse->id,
        'provider' => 'cainiao',
        'enabled' => true,
        'auto_push' => true,
        'auto_push_return' => true,
        'push_retry_times' => 3,
        'sku_mapping_mode' => 'same',
        'app_key' => WMS_UAT_APP_KEY,
        'customer_id' => 'CUBE_OWNER',
        'api_env' => 'sandbox',
        'warehouse_code' => 'CN-WH-UAT',
        'remark' => '',
        'callback_token' => 'uatoken'.bin2hex(random_bytes(10)),
    ], $attrs));
    $config->app_secret = WMS_UAT_APP_SECRET;
    $config->save();

    return $config->refresh();
}

/** 买家 + 令牌 */
function wmsUatBuyer(): array
{
    $user = createTestUser('uat');

    return ['user' => $user, 'auth' => ['Authorization' => 'Bearer '.$user->createToken('uat')->plainTextToken]];
}

/** Step 1 数据集：2 个收货地址（华南 / 华北，回传时用不同承运商） */
function wmsUatAddress(array $auth, string $region = 'south'): int
{
    $addr = $region === 'south'
        ? ['province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '科技路 1 号']
        : ['province' => '北京市', 'city' => '北京市', 'district' => '朝阳区', 'detail_address' => '建国路 88 号'];

    $data = test()->postJson('/api/user/addresses', array_merge([
        'contact_name' => '联调收件人', 'contact_phone' => '13800000000',
    ], $addr), $auth)->json('data');

    return $data['id'] ?? $data;
}

/**
 * 真实下单：购物车 → 下单 → 沙箱支付（支付成功即触发履约建单 + 推送入队）
 *
 * @param  list<array{0: ProductSku, 1: int}>  $lines  [SKU, 数量]
 */
function wmsUatPlaceAndPay(array $auth, array $lines, string $region = 'south'): Order
{
    foreach ($lines as [$sku, $qty]) {
        test()->postJson('/api/cart', ['sku_id' => $sku->id, 'quantity' => $qty], $auth)->assertOk();
    }

    $data = test()->postJson('/api/orders', ['address_id' => wmsUatAddress($auth, $region)], $auth)->json('data');
    $order = Order::findOrFail(oid($data['order_id']));

    $payNo = test()->postJson('/api/payments', ['order_no' => $order->order_no, 'channel' => 'wechat'], $auth)
        ->json('data.pay_params.payment_no');
    test()->postJson("/api/payments/sandbox/{$payNo}", [], $auth)->assertOk();

    return $order->fresh();
}

/** 菜鸟网关：成功回执（也可注入异常，见异常演练套件） */
function wmsUatGatewaySuccess(string $wmsNo = 'CN-UAT-1'): void
{
    Http::fake(['*' => Http::response(json_encode([
        'response' => ['flag' => 'success', 'code' => '0', 'deliveryOrderId' => $wmsNo, 'returnOrderId' => $wmsNo],
    ]), 200)]);
}

/** 驱动队列里的出库推送作业 */
function wmsUatRunPushOutbound(): void
{
    Queue::assertPushed(PushOutboundJob::class, function ($job) {
        $job->handle();

        return true;
    });
}

/** 驱动队列里的退货推送作业 */
function wmsUatRunPushReturn(): void
{
    Queue::assertPushed(PushReturnInboundJob::class, function ($job) {
        $job->handle();

        return true;
    });
}

/** 驱动队列里的回调处理作业 */
function wmsUatRunCallbacks(): void
{
    Queue::assertPushed(ProcessWmsCallbackJob::class, function ($job) {
        $job->handle(app(CallbackDispatcher::class), app(CallbackDeduplicator::class));

        return true;
    });
}

/** 订单下全部包裹档（多包裹落 `shipping_packages`，挂在 shippings 之下） */
function wmsUatPackages(Order $order): \Illuminate\Database\Eloquent\Collection
{
    $shippingIds = Shipping::where('order_id', $order->id)->pluck('id');

    return ShippingPackage::whereIn('shipping_id', $shippingIds)->orderBy('sort')->get();
}

/**
 * 构造 deliveryorder.confirm 报文（可多包裹）。
 *
 * @param  list<array<string, mixed>>  $packages
 */
function wmsUatConfirmPayload(FulfillmentOrder $fo, array $packages, string $timestamp = '2026-09-20 10:00:00'): array
{
    return [
        'method' => 'taobao.qimen.deliveryorder.confirm',
        'timestamp' => $timestamp,
        'app_key' => WMS_UAT_APP_KEY,
        'v' => '2.0',
        'sign_method' => 'md5',
        'customerId' => 'CUBE_OWNER',
        'deliveryOrder' => [
            'deliveryOrderCode' => $fo->outbound_no,
            'deliveryOrderId' => 'CN-UAT-OUT',
            'status' => 'SHIPPED',
            'receiverInfo' => ['name' => '联调收件人', 'mobile' => '13800000000'],
            'packages' => ['package' => $packages],
        ],
    ];
}

/** 构造 returnorder.confirm 报文 */
function wmsUatReturnConfirmPayload(ReturnInboundOrder $rio, string $itemCode, int $qty, string $type = 'ZP', string $timestamp = '2026-09-20 15:00:00'): array
{
    return [
        'method' => 'taobao.qimen.returnorder.confirm',
        'timestamp' => $timestamp,
        'app_key' => WMS_UAT_APP_KEY,
        'v' => '2.0',
        'sign_method' => 'md5',
        'returnOrder' => [
            'returnOrderCode' => $rio->inbound_no,
            'returnOrderId' => 'CN-UAT-RET',
            'orderConfirmTime' => $timestamp,
            'orderLines' => ['orderLine' => [
                ['itemCode' => $itemCode, 'actualQty' => $qty, 'inventoryType' => $type],
            ]],
        ],
    ];
}
