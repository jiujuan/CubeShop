<?php

/**
 * T-035 证据生成脚本（真实下单 → 取消链路，导出金额与分摊核对）
 *
 * 运行：cd backend && php ../docs/testing/evidence/v1.1/T-035/gen_evidence.php
 *
 * 使用**临时 SQLite 库**（不污染开发库）：迁移 → 造数 → OrderService::createFromCart
 * （带券 + 自动满减）→ 打印 orders / order_items / user_coupons / coupons 行 → 取消 → 再打印。
 */

$root = realpath(__DIR__.'/../../../../../');
$db = sys_get_temp_dir().'/cubeshop_t035_'.getmypid().'.sqlite';
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

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Promotion;
use App\Models\User;
use App\Models\UserAddress;
use App\Models\UserCoupon;
use App\Services\Order\OrderService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::call('migrate', ['--force' => true]);
Artisan::call('db:seed', ['--class' => Database\Seeders\RolePermissionSeeder::class, '--force' => true]);

$money = fn ($v) => number_format((float) $v, 2, '.', '');

// ── 造数 ──────────────────────────────────────────────────────────
$user = User::create([
    'username' => 'evidence035', 'password' => bcrypt('Test@1234'), 'nickname' => '证据买家', 'status' => 1,
]);
$address = UserAddress::create([
    'user_id' => $user->id, 'contact_name' => '收件人', 'contact_phone' => '13800000000',
    'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '科技路 1 号',
    'is_default' => true,
]);
$category = Category::create(['parent_id' => 0, 'name' => '证据分类', 'sort' => 0, 'status' => 1]);
$product = Product::create([
    'category_id' => $category->id, 'title' => '证据商品', 'price' => '66.00', 'status' => 1,
]);
$sku = ProductSku::create([
    'product_id' => $product->id, 'sku_code' => 'SKU-EV035', 'specs' => ['规格' => '标准'],
    'price' => '66.00', 'status' => 1,
]);
Inventory::create(['sku_id' => $sku->id, 'stock' => 10, 'locked_stock' => 0]);

$coupon = Coupon::create([
    'name' => '证据满减券 20', 'type' => Coupon::TYPE_FIXED, 'amount' => '20.00', 'min_spend' => '100.00',
    'scope' => Coupon::SCOPE_ALL, 'scope_refs' => [], 'total_count' => 100, 'issued_count' => 1,
    'used_count' => 0, 'per_user_limit' => 1, 'valid_type' => Coupon::VALID_RELATIVE, 'valid_days' => 7,
    'status' => Coupon::STATUS_ACTIVE,
]);
$uc = UserCoupon::create([
    'user_id' => $user->id, 'coupon_id' => $coupon->id,
    'status' => UserCoupon::STATUS_UNUSED, 'expire_at' => now()->addDays(7),
]);
// 满减：满 100 减 12（会被自动匹配）
Promotion::create([
    'name' => '证据满减 100-12', 'rules' => [['min' => 100, 'discount' => 12]],
    'scope' => Promotion::SCOPE_ALL, 'scope_refs' => [],
    'start_at' => now()->subDay(), 'end_at' => now()->addDay(), 'status' => Promotion::STATUS_ACTIVE,
]);

// ── 下单（带券 + 自动满减）不经过购物车：直接构造 OrderService 需要的购物车项 ──
DB::table('cart_items')->insert([
    'user_id' => $user->id, 'sku_id' => $sku->id, 'quantity' => 2,
    'created_at' => now(), 'updated_at' => now(),
]);

$order = app(OrderService::class)->createFromCart(
    userId: $user->id,
    addressId: $address->id,
    cartItemIds: null,
    remark: 'T-035 证据',
    userCouponId: $uc->id,
    promotionId: null,
);

echo "## 场景：商品 66.00 × 2 + 运费 10.00，满减 100-12（自动匹配）+ 券 20.00\n\n";

echo "### orders 行（下单后）\n\n";
$row = DB::table('orders')->where('id', $order->id)->first();
printf("| 字段 | 值 |\n|---|---|\n");
foreach (['total_amount', 'freight_amount', 'promotion_discount', 'discount_amount', 'pay_amount', 'coupon_id', 'status'] as $f) {
    printf("| %s | %s |\n", $f, $row->$f ?? 'NULL');
}

echo "\n### amount_details（分摊快照）\n\n```json\n";
echo json_encode($order->fresh()->amount_details, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
echo "\n```\n\n";

echo "### order_items 行级分摊\n\n";
echo "| id | price | quantity | total_amount | promotion_share | coupon_share | 行实付 |\n|---|---|---|---|---|---|---|\n";
foreach (DB::table('order_items')->where('order_id', $order->id)->get() as $it) {
    $payable = $money($it->price * $it->quantity - $it->promotion_share - $it->coupon_share);
    printf("| %d | %s | %d | %s | %s | %s | %s |\n", $it->id, $it->price, $it->quantity, $it->total_amount, $it->promotion_share, $it->coupon_share, $payable);
}

echo "\n### 券占用状态（下单即核销）\n\n";
$ucRow = DB::table('user_coupons')->where('id', $uc->id)->first();
printf("| user_coupons.status | used_order_id | used_at |\n|---|---|---|\n| %s | %s | %s |\n\n",
    $ucRow->status, $ucRow->used_order_id ?? 'NULL', $ucRow->used_at ?? 'NULL');
printf("| coupons.used_count |\n|---|\n| %d |\n\n", DB::table('coupons')->where('id', $coupon->id)->value('used_count'));

// ── 校验（与手算对照）────────────────────────────────────────────
$d = $order->fresh()->amount_details;
$expect = ['goods_amount' => '132.00', 'freight_amount' => '10.00', 'promotion_discount' => '12.00',
    'discount_amount' => '32.00', 'pay_amount' => '110.00'];
echo "### 期望值核对（手算：132 − 12 − 20 + 10 = 110）\n\n";
echo "| 字段 | 期望 | 实测 | 结论 |\n|---|---|---|---|\n";
foreach ($expect as $k => $v) {
    printf("| %s | %s | %s | %s |\n", $k, $v, $d[$k], $d[$k] === $v ? '✅' : '❌');
}
$sumCoupon = array_sum(array_column($d['lines'], 'coupon_share'));
$sumPromotion = array_sum(array_column($d['lines'], 'promotion_share'));
printf("| Σ 行 promotion_share = 满减总额 | 12.00 | %s | %s |\n", $money($sumPromotion),
    bccomp($money($sumPromotion), $d['promotion_discount'], 2) === 0 ? '✅' : '❌');

// ── 取消订单 → 券返还 ─────────────────────────────────────────────
app(OrderService::class)->cancel($order, $user->id, '证据：取消验证券返还');

echo "\n### 取消待支付订单后（券返还 + used_count 回退）\n\n";
$ucRow2 = DB::table('user_coupons')->where('id', $uc->id)->first();
printf("| user_coupons.status | used_order_id | used_at | coupons.used_count |\n|---|---|---|---|\n| %s | %s | %s | %d |\n\n",
    $ucRow2->status, $ucRow2->used_order_id ?? 'NULL', $ucRow2->used_at ?? 'NULL',
    DB::table('coupons')->where('id', $coupon->id)->value('used_count'));
printf("| 订单 status | 券 status | 结论 |\n|---|---|---|\n| %s | %s | %s |\n",
    DB::table('orders')->where('id', $order->id)->value('status'), $ucRow2->status,
    $ucRow2->status === 'unused' ? '✅ 已返还可用' : '❌');

@unlink($db);
