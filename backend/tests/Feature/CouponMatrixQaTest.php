<?php

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Refund;
use App\Models\UserCoupon;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * V1.1 T-041（F06 / 质量专项）：优惠券金额矩阵参数化测试
 *
 * 矩阵：{无券 / 满减 / 券 / 券+满减} × {单行 / 多行} × {整单取消 / 全额退款 / 部分退款}
 * 断言：应付金额、行分摊 Σ = 总优惠、退款金额、券最终状态。
 *
 * 另含门槛边界参数化：{券 / 券+满减} × {单行 / 多行} × {刚好达标 / 差 1 分}。
 *
 * 金额口径（dev/测试配置）：满 99 免运费 → 本矩阵 goods = 100.00，freight = 0.00；
 * 满减 min=100 discount=12；券 fixed 20 min_spend=100。
 * 应付 = goods − 满减 − 券 + 运费。
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

function t041Buyer(): array
{
    $user = createTestUser('t041'.uniqid());

    return ['user' => $user, 'auth' => ['Authorization' => 'Bearer '.$user->createToken('t041')->plainTextToken]];
}

function t041Address(array $auth): int
{
    $addr = test()->postJson('/api/user/addresses', [
        'contact_name' => '收件人', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '科技路 1 号',
    ], $auth)->json('data');

    return $addr['id'] ?? $addr;
}

function t041Cart(array $auth, int $skuId, int $qty): void
{
    test()->postJson('/api/cart', ['sku_id' => $skuId, 'quantity' => $qty], $auth)->assertOk();
}

function t041Coupon(array $o = []): Coupon
{
    return Coupon::create(array_merge([
        'name' => '矩阵券'.uniqid(),
        'type' => Coupon::TYPE_FIXED,
        'amount' => '20.00',
        'min_spend' => '100.00',
        'scope' => Coupon::SCOPE_ALL,
        'scope_refs' => [],
        'total_count' => 100,
        'issued_count' => 1,
        'used_count' => 0,
        'per_user_limit' => 1,
        'valid_type' => Coupon::VALID_RELATIVE,
        'valid_days' => 7,
        'status' => Coupon::STATUS_ACTIVE,
    ], $o));
}

function t041Promotion(): \App\Models\Promotion
{
    return \App\Models\Promotion::create([
        'name' => '矩阵活动'.uniqid(),
        'rules' => [['min' => 100, 'discount' => 12]],
        'scope' => \App\Models\Promotion::SCOPE_ALL,
        'scope_refs' => [],
        'start_at' => now()->subDay(),
        'end_at' => now()->addDay(),
        'status' => \App\Models\Promotion::STATUS_ACTIVE,
    ]);
}

/** 单行：100×1；多行：60×1 + 40×1，两种都是 goods = 100.00（免运费阈值 99 之上，freight = 0） */
function t041Skus(string $lines): array
{
    if ($lines === 'single') {
        return [createTestSku(stock: 10, price: '100.00')];
    }

    return [createTestSku(stock: 10, price: '60.00'), createTestSku(stock: 10, price: '40.00')];
}

/** 各模式预期优惠（goods 恒为 100.00；测试库未启用满额免运费配置 → 运费恒为 10.00） */
function t041Expected(string $mode): array
{
    return match ($mode) {
        'none' => ['promo' => 0.0, 'coupon' => 0.0, 'pay' => 110.0],
        'promo' => ['promo' => 12.0, 'coupon' => 0.0, 'pay' => 98.0],
        'coupon' => ['promo' => 0.0, 'coupon' => 20.0, 'pay' => 90.0],
        'both' => ['promo' => 12.0, 'coupon' => 20.0, 'pay' => 78.0],
    };
}

/** 下单（按模式备券/备活动），返回现场 */
function t041RunLifecycle(string $mode, string $lines, array $buyer): array
{
    $test = test();
    $headers = $buyer['auth'];
    $addressId = t041Address($headers);
    $skus = t041Skus($lines);

    $userCoupon = null;
    if (in_array($mode, ['coupon', 'both'], true)) {
        $userCoupon = UserCoupon::create([
            'user_id' => $buyer['user']->id,
            'coupon_id' => t041Coupon()->id,
            'status' => UserCoupon::STATUS_UNUSED,
            'expire_at' => now()->addDays(7),
        ]);
    }
    if (in_array($mode, ['promo', 'both'], true)) {
        t041Promotion();
    }

    foreach ($skus as $sku) {
        t041Cart($headers, $sku->id, 1);
    }

    $extra = [];
    if ($userCoupon) {
        $extra['user_coupon_id'] = $userCoupon->id;
    }
    if ($mode === 'promo') {
        // promo 模式不依赖自动匹配，指定活动更稳；both 用自动匹配验证叠加
        $extra['promotion_id'] = \App\Models\Promotion::first()->id;
    }

    $created = $test->postJson('/api/orders', array_merge(['address_id' => $addressId], $extra), $headers)->json();

    return compact('userCoupon', 'created');
}

/* ------------------------------------------------------------------ */
/* 主矩阵：4 模式 × 2 行数 × 3 退态 = 24 例                               */
/* ------------------------------------------------------------------ */

it('金额矩阵 mode/lines/refund', function (string $mode, string $lines, string $refundState) {
    ['user' => $user, 'auth' => $auth] = t041Buyer();
    ['userCoupon' => $uc, 'created' => $created] = t041RunLifecycle($mode, $lines, compact('user', 'auth'));

    $exp = t041Expected($mode);

    // 1) 下单成功且应付金额正确
    expect($created['code'])->toBe(0)
        ->and((float) $created['data']['pay_amount'])->toBe($exp['pay'])
        ->and((float) $created['data']['discount_amount'])->toBe($exp['promo'] + $exp['coupon'])
        ->and((float) $created['data']['promotion_discount'])->toBe($exp['promo']);

    $orderId = $created['data']['order_id'];
    $order = Order::find($orderId);
    $details = $order->amount_details;

    // 2) 行分摊不变量：Σ coupon_share = 券优惠、Σ promotion_share = 满减优惠、Σ payable + freight = 应付
    $sumCoupon = array_sum(array_map(fn ($l) => (float) $l['coupon_share'], $details['lines']));
    $sumPromo = array_sum(array_map(fn ($l) => (float) $l['promotion_share'], $details['lines']));
    $sumPayable = array_sum(array_map(fn ($l) => (float) $l['payable'], $details['lines']));
    expect(round($sumCoupon, 2))->toBe($exp['coupon'])
        ->and(round($sumPromo, 2))->toBe($exp['promo'])
        ->and(round($sumPayable + (float) $details['freight_amount'], 2))->toBe($exp['pay'])
        ->and(count($details['lines']))->toBe($lines === 'single' ? 1 : 2);

    // 3) 退态流转 + 券最终状态
    if ($refundState === 'cancel') {
        $this->postJson("/api/orders/{$orderId}/cancel", ['reason' => '矩阵取消'], $auth)->assertOk();
        $order = $order->fresh();
        expect($order->status)->toBe(Order::STATUS_CANCELLED)
            ->and(Refund::where('order_id', $orderId)->count())->toBe(0);
        if ($uc) {
            $ucFresh = $uc->fresh();
            expect($ucFresh->status)->toBe(UserCoupon::STATUS_UNUSED)
                ->and($ucFresh->used_order_id)->toBeNull()
                ->and((int) $ucFresh->coupon->used_count)->toBe(0);
        }

        return;
    }

    // 支付成功进入可退款状态
    $payNo = $this->postJson('/api/payments', ['order_no' => $created['data']['order_no'], 'channel' => 'wechat'], $auth)
        ->json('data.pay_params.payment_no');
    $this->postJson("/api/payments/sandbox/{$payNo}", [], $auth)->assertOk();
    expect((string) Order::find($orderId)->pay_amount)->toBe(number_format($exp['pay'], 2, '.', ''));

    if ($refundState === 'full_refund') {
        $this->postJson("/api/orders/{$orderId}/refund", ['reason' => '矩阵全额退'], $auth)->assertOk();
        $refundId = Refund::where('order_id', $orderId)->latest('id')->first()->id;
        $this->postJson("/api/admin/refunds/{$refundId}/process", ['action' => 'approve'], $this->adminAuth)->assertOk();

        // 退款金额 = 订单实付
        expect((string) Refund::find($refundId)->amount)->toBe(number_format($exp['pay'], 2, '.', ''))
            ->and(Order::find($orderId)->status)->toBe(Order::STATUS_REFUNDED);
        if ($uc) {
            // 整单全额退款 → 券返还
            expect($uc->fresh()->status)->toBe(UserCoupon::STATUS_UNUSED)
                ->and((int) $uc->fresh()->coupon->used_count)->toBe(0);
        }

        return;
    }

    // partial_refund：固定退 10.00 → 券不返还（防资损）
    $this->postJson("/api/orders/{$orderId}/refund", ['reason' => '矩阵部分退', 'amount' => '10.00'], $auth)->assertOk();
    $refundId = Refund::where('order_id', $orderId)->latest('id')->first()->id;
    $this->postJson("/api/admin/refunds/{$refundId}/process", ['action' => 'approve'], $this->adminAuth)->assertOk();

    expect((string) Refund::find($refundId)->amount)->toBe('10.00')
        ->and(Order::find($orderId)->status)->toBe(Order::STATUS_REFUNDED);
    if ($uc) {
        expect($uc->fresh()->status)->toBe(UserCoupon::STATUS_USED)
            ->and((int) $uc->fresh()->coupon->used_count)->toBe(1);
    }
})->with(function () {
    foreach (['none', 'promo', 'coupon', 'both'] as $mode) {
        foreach (['single', 'multi'] as $lines) {
            foreach (['cancel', 'full_refund', 'partial_refund'] as $refundState) {
                yield "[$mode][$lines][$refundState]" => [$mode, $lines, $refundState];
            }
        }
    }
});

/* ------------------------------------------------------------------ */
/* 门槛边界：{券 / 券+满减} × {单行 / 多行} × {刚好达标 / 差 1 分} = 8 例      */
/* ------------------------------------------------------------------ */

it('门槛边界 mode/lines/boundary', function (string $mode, string $lines, string $boundary) {
    ['user' => $user, 'auth' => $auth] = t041Buyer();
    $addressId = t041Address($auth);

    // 差 1 分：单行 99.99；多行 59.99 + 40.00 = 99.99（均 < 100 门槛）
    $skus = $boundary === 'exact'
        ? t041Skus($lines)
        : ($lines === 'single'
            ? [createTestSku(stock: 10, price: '99.99')]
            : [createTestSku(stock: 10, price: '59.99'), createTestSku(stock: 10, price: '40.00')]);

    $coupon = t041Coupon(); // min_spend = 100.00
    $uc = UserCoupon::create([
        'user_id' => $user->id,
        'coupon_id' => $coupon->id,
        'status' => UserCoupon::STATUS_UNUSED,
        'expire_at' => now()->addDays(7),
    ]);
    if ($mode === 'both') {
        t041Promotion(); // min = 100，差 1 分时同样不命中
    }

    foreach ($skus as $sku) {
        t041Cart($auth, $sku->id, 1);
    }

    $res = $this->postJson('/api/orders', ['address_id' => $addressId, 'user_coupon_id' => $uc->id], $auth);

    if ($boundary === 'exact') {
        $exp = t041Expected($mode);
        expect($res->json('code'))->toBe(0)
            ->and((float) $res->json('data.pay_amount'))->toBe($exp['pay'])
            ->and((float) $res->json('data.discount_amount'))->toBe($exp['promo'] + $exp['coupon']);
        $details = Order::find($res->json('data.order_id'))->amount_details;
        $sumCoupon = array_sum(array_map(fn ($l) => (float) $l['coupon_share'], $details['lines']));
        $sumPromo = array_sum(array_map(fn ($l) => (float) $l['promotion_share'], $details['lines']));
        expect(round($sumCoupon, 2))->toBe($exp['coupon'])
            ->and(round($sumPromo, 2))->toBe($exp['promo']);
    } else {
        // 差 1 分被拒（先券校验），无订单产生、券未被占用
        $res->assertStatus(409)->assertJsonPath('code', 40009);
        expect($res->json('message'))->toContain('门槛')
            ->and(Order::count())->toBe(0)
            ->and($uc->fresh()->status)->toBe(UserCoupon::STATUS_UNUSED);
    }
})->with(function () {
    foreach (['coupon', 'both'] as $mode) {
        foreach (['single', 'multi'] as $lines) {
            foreach (['exact', 'short1fen'] as $boundary) {
                yield "[$mode][$lines][$boundary]" => [$mode, $lines, $boundary];
            }
        }
    }
});
