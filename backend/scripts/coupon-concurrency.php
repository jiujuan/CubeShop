<?php

/**
 * V1.1 T-041（F06 / 质量专项）：优惠券并发场景脚本 A~D
 *
 * 用法（手动触发）：
 *   1. 启动多 worker dev server（保证真并发，单 worker 会退化为串行但断言仍应成立）：
 *        cd backend && PHP_CLI_SERVER_WORKERS=16 php artisan serve --port=8001
 *   2. 运行（另一终端）：
 *        php scripts/coupon-concurrency.php http://127.0.0.1:8001
 *
 * 场景：
 *   A 限量 10 的券，200 用户并发领取   → issued_count=10、user_coupons=10，无超发
 *   B 同一用户并发领 10 次（限领 1）   → 仅 1 条领取记录
 *   C 同一张券被 2 个订单并发使用      → 仅 1 单成功用券，另一单被拒
 *   D 券库存仅剩 1，2 用户并发领取     → 仅 1 人成功，issued 无负数
 *
 * 说明：脚本直接 bootstrap Laravel 造用户/券/令牌（不走注册接口），
 *       被测动作（领券/下单）全部走 HTTP 并发（curl_multi）。
 */

use App\Models\Coupon;
use App\Models\User;
use App\Models\UserCoupon;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');

/* ---------- 造数助手 ---------- */

function mkUser(string $tag): array
{
    $user = User::create([
        'username' => $tag.uniqid(),
        'password' => 'Test@123456',
        'nickname' => $tag,
        'status' => 1,
    ]);

    return ['user' => $user, 'token' => $user->createToken('conc')->plainTextToken];
}

function mkCoupon(array $o = []): Coupon
{
    return Coupon::create(array_merge([
        'name' => '并发券'.uniqid(),
        'type' => Coupon::TYPE_FIXED,
        'amount' => '5.00',
        'min_spend' => '0.00',
        'scope' => Coupon::SCOPE_ALL,
        'scope_refs' => [],
        'total_count' => 100,
        'issued_count' => 0,
        'used_count' => 0,
        'per_user_limit' => 1,
        'valid_type' => Coupon::VALID_RELATIVE,
        'valid_days' => 7,
        'status' => Coupon::STATUS_ACTIVE,
    ], $o));
}

/* ---------- HTTP 并发助手（curl_multi） ---------- */

/** 并行发起多个请求；返回与输入顺序一致的 [http_status, body_array] */
function parallelRequests(string $base, array $specs): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($specs as $i => $spec) {
        $ch = curl_init($base.$spec['path']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($spec['body'] ?? []),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => array_merge(
                ['Content-Type: application/json', 'Accept: application/json'],
                isset($spec['token']) ? ['Authorization: Bearer '.$spec['token']] : []
            ),
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }

    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 0.1);
        }
    } while ($active && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $i => $ch) {
        $results[$i] = [curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string) curl_multi_getcontent($ch), true)];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $results;
}

/* ---------- 结果记录 ---------- */

$report = [];

/* ---------- 场景 A：限量 10，200 用户并发领取 ---------- */

$report['A'] = (function () use ($base, &$report) {
    $coupon = mkCoupon(['total_count' => 10]);
    $users = [];
    $specs = [];
    for ($i = 0; $i < 200; $i++) {
        $u = mkUser('cona');
        $users[] = $u;
        $specs[] = ['path' => "/api/coupons/{$coupon->id}/receive", 'token' => $u['token']];
    }
    $results = parallelRequests($base, $specs);

    $ok = 0;
    $reasons = [];
    foreach ($results as $i => [$http, $body]) {
        // 连接层失败（http_0，built-in server 并发能力限制）重试一次：
        // 重试仍走同一限量券领取接口，不影响「无超发」结论，反而让全部 200 个请求都到达业务层
        if ($http === 0 || $body === null) {
            [$http, $body] = parallelRequests($base, [$specs[$i]])[0];
        }
        if (($body['code'] ?? -1) === 0) {
            $ok++;
        } else {
            $key = ($body['message'] ?? 'http_'.$http);
            $reasons[$key] = ($reasons[$key] ?? 0) + 1;
        }
    }
    $coupon->refresh();
    $received = DB::table('user_coupons')->where('coupon_id', $coupon->id)->count();

    return [
        '并发数' => 200,
        '成功领取' => $ok,
        '失败原因分布' => $reasons,
        'issued_count' => $coupon->issued_count,
        'user_coupons 行数' => $received,
        '断言' => $ok === 10 && $coupon->issued_count === 10 && $received === 10 ? 'PASS' : 'FAIL',
    ];
})();

/* ---------- 场景 B：同一用户并发领 10 次（限领 1） ---------- */

$report['B'] = (function () use ($base) {
    $coupon = mkCoupon(['total_count' => 100]);
    $u = mkUser('conb');
    $specs = [];
    for ($i = 0; $i < 10; $i++) {
        $specs[] = ['path' => "/api/coupons/{$coupon->id}/receive", 'token' => $u['token']];
    }
    $results = parallelRequests($base, $specs);

    $ok = 0;
    $reasons = [];
    foreach ($results as [, $body]) {
        if (($body['code'] ?? -1) === 0) {
            $ok++;
        } else {
            $key = $body['message'] ?? '?';
            $reasons[$key] = ($reasons[$key] ?? 0) + 1;
        }
    }
    $received = DB::table('user_coupons')->where('coupon_id', $coupon->id)->where('user_id', $u['user']->id)->count();
    $coupon->refresh();

    return [
        '并发数' => 10,
        '成功领取' => $ok,
        '失败原因分布' => $reasons,
        'user_coupons 行数' => $received,
        'issued_count' => $coupon->issued_count,
        '断言' => $received === 1 && $coupon->issued_count === 1 ? 'PASS' : 'FAIL',
    ];
})();

/* ---------- 场景 C：同一张券被 2 个订单并发使用 ---------- */

$report['C'] = (function () use ($base) {
    // 备货：sku + 地址 + 购物车
    $category = \App\Models\Category::create(['parent_id' => 0, 'name' => '并发C分类'.uniqid(), 'sort' => 0, 'status' => 1]);
    $product = \App\Models\Product::create(['category_id' => $category->id, 'title' => '并发C商品', 'price' => '50.00', 'status' => 1]);
    $sku = \App\Models\ProductSku::create(['product_id' => $product->id, 'sku_code' => 'SKU-'.uniqid(), 'specs' => ['规格' => '标准'], 'price' => '50.00', 'status' => 1]);
    \App\Models\Inventory::create(['sku_id' => $sku->id, 'stock' => 100, 'locked_stock' => 0]);

    $u = mkUser('conc');
    $token = $u['token'];

    $mk = function (string $path, array $body, string $tok) use ($base) {
        return parallelRequests($base, [['path' => $path, 'token' => $tok, 'body' => $body]])[0];
    };
    [, $addrRes] = $mk('/api/user/addresses', [
        'contact_name' => '并发C', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '科技路 1 号',
    ], $token);
    $addressId = $addrRes['data']['id'] ?? null;

    [, $cartRes] = $mk('/api/cart', ['sku_id' => $sku->id, 'quantity' => 1], $token);

    $coupon = mkCoupon(['total_count' => 100, 'issued_count' => 1]);
    $uc = UserCoupon::create([
        'user_id' => $u['user']->id,
        'coupon_id' => $coupon->id,
        'status' => UserCoupon::STATUS_UNUSED,
        'expire_at' => now()->addDays(7),
    ]);

    $results = parallelRequests($base, [
        ['path' => '/api/orders', 'token' => $token, 'body' => ['address_id' => $addressId, 'user_coupon_id' => $uc->id]],
        ['path' => '/api/orders', 'token' => $token, 'body' => ['address_id' => $addressId, 'user_coupon_id' => $uc->id]],
    ]);

    $successCount = 0;
    $couponOrders = [];
    foreach ($results as [, $b]) {
        if (($b['code'] ?? -1) === 0) {
            $successCount++;
            $couponOrders[] = $b['data']['coupon_id'];
        }
    }
    $uc->refresh();
    $usedOrdersWithCoupon = \App\Models\Order::where('coupon_id', $coupon->id)->count();

    return [
        '并发数' => 2,
        '成功下单' => $successCount,
        'coupon_id 非空订单数' => $usedOrdersWithCoupon,
        'uc 状态' => $uc->status,
        'uc.used_order_id' => $uc->used_order_id,
        '断言' => $usedOrdersWithCoupon === 1 && $uc->status === UserCoupon::STATUS_USED
            && (int) $uc->used_order_id > 0 ? 'PASS' : 'FAIL',
    ];
})();

/* ---------- 场景 D：券库存仅剩 1，2 用户并发领取 ---------- */

$report['D'] = (function () use ($base) {
    $coupon = mkCoupon(['total_count' => 5, 'issued_count' => 4]); // 仅剩 1
    $users = [mkUser('cond1'), mkUser('cond2')];
    $results = parallelRequests($base, array_map(fn ($u) => [
        'path' => "/api/coupons/{$coupon->id}/receive", 'token' => $u['token'],
    ], $users));

    $ok = 0;
    $reasons = [];
    foreach ($results as [, $body]) {
        if (($body['code'] ?? -1) === 0) {
            $ok++;
        } else {
            $key = $body['message'] ?? '?';
            $reasons[$key] = ($reasons[$key] ?? 0) + 1;
        }
    }
    $coupon->refresh();
    $received = DB::table('user_coupons')->where('coupon_id', $coupon->id)->count();

    return [
        '并发数' => 2,
        '成功领取' => $ok,
        '失败原因分布' => $reasons,
        'issued_count' => $coupon->issued_count,
        'user_coupons 行数' => $received,
        '断言' => $ok === 1 && $coupon->issued_count === 5 && $received === 1 ? 'PASS' : 'FAIL',
    ];
})();

/* ---------- 输出 ---------- */

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;

$allPass = true;
foreach ($report as $r) {
    if (($r['断言'] ?? 'FAIL') !== 'PASS') {
        $allPass = false;
    }
}
echo $allPass ? 'ALL PASS' : 'HAS FAILURES', PHP_EOL;
