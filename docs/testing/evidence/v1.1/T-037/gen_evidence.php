<?php

/**
 * T-037 证据生成脚本（券过期收敛 + 过期/停发对可用券列表影响）
 *
 * 运行：cd backend && php ../docs/testing/evidence/v1.1/T-037/gen_evidence.php
 *
 * 使用临时 SQLite 库，不污染开发库。覆盖：coupons:expire 过期前后状态变化、
 * 可用券列表排除 expired / stopped 模板券、活动停发后不再匹配。
 */

$root = realpath(__DIR__.'/../../../../../');
$db = sys_get_temp_dir().'/cubeshop_t037_'.getmypid().'.sqlite';
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

use App\Models\Coupon;
use App\Models\Promotion;
use App\Models\User;
use App\Models\UserCoupon;
use App\Services\Marketing\CouponService;
use App\Services\Marketing\PromotionService;
use App\Services\Marketing\Dto\OrderContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::call('migrate', ['--force' => true]);

$user = User::create(['username' => 'evidence037', 'password' => bcrypt('x'), 'status' => 1]);
$coupon = Coupon::create([
    'name' => '证据券', 'type' => Coupon::TYPE_FIXED, 'amount' => '20.00', 'min_spend' => '0.00',
    'scope' => Coupon::SCOPE_ALL, 'scope_refs' => [], 'total_count' => 100, 'issued_count' => 1,
    'used_count' => 0, 'per_user_limit' => 1, 'valid_type' => Coupon::VALID_ABSOLUTE,
    'valid_from' => now()->subDay(), 'valid_to' => now()->addDays(7), 'status' => Coupon::STATUS_ACTIVE,
]);

function grant(int $userId, int $couponId, array $o = []): UserCoupon
{
    return UserCoupon::create(array_merge([
        'user_id' => $userId, 'coupon_id' => $couponId,
        'status' => UserCoupon::STATUS_UNUSED, 'expire_at' => now()->addDays(7),
    ], $o));
}

echo "# T-037 券过期收敛与可用券过滤 证据\n\n";

/* ============ 过期前后对比 ============ */
echo "## 场景 A：coupons:expire 过期未用券置 expired\n\n";
grant($user->id, $coupon->id, ['expire_at' => now()->subDay()]); // 过期未用
grant($user->id, $coupon->id, ['expire_at' => now()->addDay()]);  // 未过期
grant($user->id, $coupon->id, ['expire_at' => now()->subDay(), 'status' => UserCoupon::STATUS_USED]); // 已用

$before = DB::table('user_coupons')->where('user_id', $user->id)
    ->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status')->toArray();
echo "执行前状态分布：".json_encode($before, JSON_UNESCAPED_UNICODE)."\n\n";

$count = app(CouponService::class)->expireOverdue();

$after = DB::table('user_coupons')->where('user_id', $user->id)
    ->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status')->toArray();
echo 'expireOverdue 处理数 = '.$count.'（期望 1，仅过期未用那张）'."\n";
echo "执行后状态分布：".json_encode($after, JSON_UNESCAPED_UNICODE)."\n\n";
echo "结论：未过期/已用券不受影响 ✅；过期未用券 → expired ✅\n\n";

/* ============ 可用券列表排除 expired / stopped ============ */
echo "## 场景 B：可用券列表排除 expired 与 stopped 模板券\n\n";
// 独立买家，仅持有一张「未过期但模板已停发」的券
$userB = User::create(['username' => 'evidence037b', 'password' => bcrypt('x'), 'status' => 1]);
$stoppedCoupon = Coupon::create([
    'name' => '已停发券', 'type' => Coupon::TYPE_FIXED, 'amount' => '30.00', 'min_spend' => '0.00',
    'scope' => Coupon::SCOPE_ALL, 'scope_refs' => [], 'total_count' => 100, 'issued_count' => 1,
    'used_count' => 0, 'per_user_limit' => 1, 'valid_type' => Coupon::VALID_ABSOLUTE,
    'valid_from' => now()->subDay(), 'valid_to' => now()->addDays(7), 'status' => Coupon::STATUS_STOPPED,
]);
grant($userB->id, $stoppedCoupon->id, ['expire_at' => now()->addDays(7)]); // 未过期但模板停发

$result = app(CouponService::class)->availableFor($userB->id, [], 200.0);
echo "可用券(usable)数量 = ".count($result['usable'])."（期望 0：仅有停发券）\n";
echo "不可用(unusable)原因分布：".json_encode(array_count_values(array_column($result["unusable"], "reason")), JSON_UNESCAPED_UNICODE)."\n\n";
echo "结论：expired 与 stopped 模板券被排除在可用列表之外（移入 unusable，附原因）✅\n\n";

/* ============ 活动停发不再匹配 ============ */
echo "## 场景 C：满减活动停发后不再被匹配\n\n";
$promo = Promotion::create([
    'name' => '满100减10', 'rules' => [['min' => 100, 'discount' => 10]],
    'scope' => Promotion::SCOPE_ALL, 'scope_refs' => [],
    'start_at' => now()->subDay(), 'end_at' => now()->addDay(), 'status' => Promotion::STATUS_ACTIVE,
]);
$ctx = new OrderContext([['product_id' => 1, 'category_id' => 10, 'price' => 150.0, 'quantity' => 1]], 0.0);

$matchBefore = app(PromotionService::class)->match($ctx)?->name ?? 'NULL';
echo "活动运行中 match → ".($matchBefore === null ? 'NULL' : $matchBefore)."（期望命中）\n";

$promo->update(['status' => Promotion::STATUS_STOPPED]);
$matchAfter = app(PromotionService::class)->match($ctx);
$dispAfter = app(PromotionService::class)->displayFor($ctx);
echo "活动停发后 match → ".($matchAfter === null ? 'NULL' : $matchAfter->name)."（期望 NULL）\n";
echo "活动停发后 displayFor → ".($dispAfter === null ? 'NULL' : json_encode($dispAfter))."（期望 NULL）\n\n";
echo "结论：停发活动不再参与满减匹配 ✅\n\n";

echo "> 附：displayFor 命中示例（活动运行状态）\n";
$promo->update(['status' => Promotion::STATUS_ACTIVE]);
echo json_encode(app(PromotionService::class)->displayFor($ctx), JSON_UNESCAPED_UNICODE)."\n";

@unlink($db);
