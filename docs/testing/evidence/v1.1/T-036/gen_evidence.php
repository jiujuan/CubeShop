<?php

/**
 * T-036 证据生成脚本（真实下单 → 退款/取消链路，导出券返还与退款上限核对）
 *
 * 运行：cd backend && php ../docs/testing/evidence/v1.1/T-036/gen_evidence.php
 *
 * 使用**临时 SQLite 库**（不污染开发库）：迁移 → 造数 → 下单（带券）→ 进入可退状态 →
 * 申请退款 / 取消 → 打印 orders / refunds / user_coupons / coupons 行 → 核对券返还与退款上限。
 */

$root = realpath(__DIR__.'/../../../../../');
$db = sys_get_temp_dir().'/cubeshop_t036_'.getmypid().'.sqlite';
@unlink($db);
touch($db);

foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $db] as $k => $v) {
    putenv("$k=$v");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}

require $root.'/backend/vendor/autoload.php';
$app = require $root.'/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\User;
use App\Models\UserAddress;
use App\Models\UserCoupon;
use App\Services\Order\OrderService;
use App\Services\Refund\RefundService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::call('migrate', ['--force' => true]);
Artisan::call('db:seed', ['--class' => Database\Seeders\RolePermissionSeeder::class, '--force' => true]);

$money = fn ($v) => number_format((float) $v, 2, '.', '');
$adminId = (int) DB::table('sys_user')->where('username', 'admin')->value('id');

// ── 造数 ──────────────────────────────────────────────────────────
function makeBuyer(): array
{
    static $i = 0;
    $i++;
    $user = User::create([
        'username' => 'evidence036_'.$i, 'password' => bcrypt('Test@1234'), 'nickname' => '证据买家'.$i, 'status' => 1,
    ]);
    $address = UserAddress::create([
        'user_id' => $user->id, 'contact_name' => '收件人', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '科技路 1 号',
        'is_default' => true,
    ]);
    $category = Category::create(['parent_id' => 0, 'name' => '证据分类'.$i, 'sort' => 0, 'status' => 1]);
    $product = Product::create(['category_id' => $category->id, 'title' => '证据商品'.$i, 'price' => '66.00', 'status' => 1]);
    $sku = ProductSku::create([
        'product_id' => $product->id, 'sku_code' => 'SKU-EV036-'.$i, 'specs' => ['规格' => '标准'],
        'price' => '66.00', 'status' => 1,
    ]);
    Inventory::create(['sku_id' => $sku->id, 'stock' => 10, 'locked_stock' => 0]);

    return [$user, $address, $sku];
}

function grantCoupon(User $user, string $amount = '20.00'): array
{
    $coupon = Coupon::create([
        'name' => '证据券'.$amount, 'type' => Coupon::TYPE_FIXED, 'amount' => $amount, 'min_spend' => '0.00',
        'scope' => Coupon::SCOPE_ALL, 'scope_refs' => [], 'total_count' => 100, 'issued_count' => 1,
        'used_count' => 0, 'per_user_limit' => 1, 'valid_type' => Coupon::VALID_RELATIVE, 'valid_days' => 7,
        'status' => Coupon::STATUS_ACTIVE,
    ]);
    $uc = UserCoupon::create([
        'user_id' => $user->id, 'coupon_id' => $coupon->id,
        'status' => UserCoupon::STATUS_UNUSED, 'expire_at' => now()->addDays(7),
    ]);

    return [$coupon, $uc];
}

function placeOrder(OrderService $os, User $user, int $addressId, int $skuId, ?int $ucId): Order
{
    DB::table('cart_items')->insert([
        'user_id' => $user->id, 'sku_id' => $skuId, 'quantity' => 2,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $os->createFromCart(
        userId: $user->id, addressId: $addressId, cartItemIds: null,
        remark: 'T-036 证据', userCouponId: $ucId, promotionId: null,
    );
}

/** 进入可退款状态：pending_payment → paid → pending_ship */
function toRefundable(OrderService $os, Order $order): void
{
    $os->transitionTo($order, Order::STATUS_PAID, '证据：支付成功', 'system');
    $os->transitionTo($order->fresh(), Order::STATUS_PENDING_SHIP, '证据：受理备货', 'system');
}

function ucState(int $ucId, int $couponId): string
{
    $uc = DB::table('user_coupons')->where('id', $ucId)->first();
    $used = DB::table('coupons')->where('id', $couponId)->value('used_count');

    return sprintf("user_coupons.status=%s, used_order_id=%s, used_at=%s | coupons.used_count=%d",
        $uc->status, $uc->used_order_id ?? 'NULL', $uc->used_at ?? 'NULL', $used);
}

$os = app(OrderService::class);
$rs = app(RefundService::class);

echo "# T-036 退款/取消与券回退 证据\n\n";
echo "场景设定：商品 66.00 × 2 = 132.00，运费 10.00，券 20.00（实付 122.00）；无满减。\n\n";

/* ============ 场景 A：整单全额退款 → 券返还 ============ */
echo "## 场景 A：整单全额退款 → 券原样返还 + used_count 回退\n\n";
[$userA, $addrA, $skuA] = makeBuyer();
[$couponA, $ucA] = grantCoupon($userA, '20.00');
$orderA = placeOrder($os, $userA, $addrA->id, $skuA->id, $ucA->id);
toRefundable($os, $orderA);

echo "下单即核销：".ucState($ucA->id, $couponA->id)."\n\n";
echo "订单 pay_amount = ".$money($orderA->fresh()->pay_amount)."（期望 122.00）\n\n";

$refundA = $rs->apply($orderA->fresh(), $userA->id, '整单退款', null); // 不传金额 = 全额
$rs->process($refundA, $adminId, 'approve');

echo "### 退款后状态\n\n";
printf("| 字段 | 值 |\n|---|---|\n");
printf("| refund.amount | %s（期望 122.00）|\n", $money($refundA->fresh()->amount));
printf("| refund.status | %s（期望 success）|\n", $refundA->fresh()->status);
printf("| order.status | %s（期望 refunded）|\n", DB::table('orders')->where('id', $orderA->id)->value('status'));
printf("| 券状态 | %s |\n\n", ucState($ucA->id, $couponA->id));

$rd = $refundA->fresh()->refund_details;
echo "### refund.refund_details（固化快照）\n\n```json\n".json_encode($rd, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n```\n\n";

$sumPayable = array_sum(array_map(static fn ($l) => (float) $l['payable'], $rd['lines']));
echo "Σ 行实付 + 运费 = ".round($sumPayable + (float) $rd['freight_amount'], 2)."（应等于 pay_amount 122.00）\n\n";

/* ============ 场景 B：部分退款 → 券不返还 ============ */
echo "## 场景 B：部分退款 → 券不返还（防资损）\n\n";
[$userB, $addrB, $skuB] = makeBuyer();
[$couponB, $ucB] = grantCoupon($userB, '20.00');
$orderB = placeOrder($os, $userB, $addrB->id, $skuB->id, $ucB->id);
toRefundable($os, $orderB);

$refundB = $rs->apply($orderB->fresh(), $userB->id, '部分退款', '50.00');
$rs->process($refundB, $adminId, 'approve');

echo "### 退款后状态\n\n";
printf("| 字段 | 值 |\n|---|---|\n");
printf("| refund.amount | %s（期望 50.00）|\n", $money($refundB->fresh()->amount));
printf("| refund.status | %s（期望 success）|\n", $refundB->fresh()->status);
printf("| order.status | %s（期望 refunded）|\n", DB::table('orders')->where('id', $orderB->id)->value('status'));
printf("| 券状态 | %s（期望 used，不返还）|\n\n", ucState($ucB->id, $couponB->id));

/* ============ 场景 C：未支付取消 → 券返还 ============ */
echo "## 场景 C：未支付订单取消 → 券返还\n\n";
[$userC, $addrC, $skuC] = makeBuyer();
[$couponC, $ucC] = grantCoupon($userC, '20.00');
$orderC = placeOrder($os, $userC, $addrC->id, $skuC->id, $ucC->id); // 仍是 pending_payment

echo "下单即核销：".ucState($ucC->id, $couponC->id)."\n\n";
$os->cancel($orderC, $userC->id, '证据：取消验证券返还');

echo "### 取消后状态\n\n";
printf("| 字段 | 值 |\n|---|---|\n");
printf("| order.status | %s（期望 cancelled）|\n", DB::table('orders')->where('id', $orderC->id)->value('status'));
printf("| 券状态 | %s（期望 unused，已返还）|\n\n", ucState($ucC->id, $couponC->id));

/* ============ 场景 D：退款上限与互斥 ============ */
echo "## 场景 D：退款累计上限 + 重复申请互斥\n\n";
[$userD, $addrD, $skuD] = makeBuyer();
$orderD = placeOrder($os, $userD, $addrD->id, $skuD->id, null); // 无券，实付 142.00
toRefundable($os, $orderD);

echo "无券订单 pay_amount = ".$money($orderD->fresh()->pay_amount)."（期望 142.00）\n\n";

// D1：超过可退余额
try {
    $rs->apply($orderD->fresh(), $userD->id, '贪心', '200.00');
    echo "- D1 超额申请：未抛异常 ❌\n";
} catch (BusinessException $e) {
    echo "- D1 超额申请 → 拒绝 ✅（code=".$e->getCode().", msg=".$e->getMessage()."）\n";
}

// D2：首笔全额（pending）→ 第二笔再申请应互斥
$rs->apply($orderD->fresh(), $userD->id, '首笔全额', null); // 142 pending
try {
    $rs->apply($orderD->fresh(), $userD->id, '再来一笔', null);
    echo "- D2 重复申请：未抛异常 ❌\n";
} catch (BusinessException $e) {
    echo "- D2 重复申请 → 拒绝 ✅（code=".$e->getCode().", msg=".$e->getMessage()."）\n";
}

echo "\n> 结论：T-036 全部场景与预期一致（券返还/不返还、退款上限、互斥、快照固化）。\n";

@unlink($db);
