<?php

namespace App\Services\Marketing;

use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\Promotion;
use App\Models\UserCoupon;
use App\Services\Marketing\Dto\OrderContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * 优惠券服务（V1.1 二期 F06 / T-033 领券，T-034 用券校验与金额分摊）
 *
 * 关键点：
 *  - 领取采用 **条件 UPDATE 原子防超发**：`issued_count = issued_count + 1 WHERE issued_count < total_count`，
 *    受影响行数为 0 即判定「已领完」；限领校验与之处于**同一事务**，避免并发绕过。
 *  - `expire_at` 在领取时按 `valid_type` 计算并固化，后续改券模板不影响已领券。
 *  - **金额计算为纯函数**（`PricingCalculator` / `AmountAllocator`），本服务只负责
 *    「模型 → 参数」「DB 边界（分类回库）」与「抛业务异常」，保证口径单一、可单测。
 */
class CouponService
{
    /**
     * 领券中心：可领券列表（含当前用户领取状态）
     *
     * @return array<int, array<string, mixed>>
     */
    public function receivableCoupons(?int $userId, int $limit = 50): array
    {
        $now = now();

        $coupons = Coupon::query()
            ->where('status', Coupon::STATUS_ACTIVE)
            ->whereColumn('issued_count', '<', 'total_count')
            ->where(function ($q) use ($now) {
                // absolute：必须在有效窗口内；relative：领取后才计算，不受窗口限制
                $q->where('valid_type', Coupon::VALID_RELATIVE)
                    ->orWhere(function ($q2) use ($now) {
                        $q2->where('valid_type', Coupon::VALID_ABSOLUTE)
                            ->where(fn ($q3) => $q3->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
                            ->where(fn ($q3) => $q3->whereNull('valid_to')->orWhere('valid_to', '>=', $now));
                    });
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $myCounts = $userId
            ? UserCoupon::where('user_id', $userId)
                ->whereIn('coupon_id', $coupons->pluck('id'))
                ->selectRaw('coupon_id, COUNT(*) as c')
                ->groupBy('coupon_id')
                ->pluck('c', 'coupon_id')
            : collect();

        return $coupons->map(function (Coupon $c) use ($myCounts) {
            $mine = (int) ($myCounts[$c->id] ?? 0);

            return [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type,
                'type_label' => Coupon::TYPE_LABELS[$c->type] ?? $c->type,
                'amount' => $c->amount !== null ? (float) $c->amount : null,
                'percent' => $c->percent,
                'max_discount' => $c->max_discount !== null ? (float) $c->max_discount : null,
                'min_spend' => (float) $c->min_spend,
                'scope' => $c->scope,
                'scope_label' => Coupon::SCOPE_LABELS[$c->scope] ?? $c->scope,
                'valid_type' => $c->valid_type,
                'valid_to' => $c->valid_to?->format('Y-m-d H:i:s'),
                'valid_days' => $c->valid_days,
                'total_count' => $c->total_count,
                'remaining' => max(0, $c->total_count - $c->issued_count),
                'received_by_me' => $mine,
                'can_receive' => $mine < $c->per_user_limit,
            ];
        })->all();
    }

    /**
     * 领取优惠券（原子防超发 + 同事务限领校验）
     */
    public function receive(int $userId, int $couponId): UserCoupon
    {
        $coupon = Coupon::find($couponId);
        if (! $coupon) {
            throw BusinessException::notFound('优惠券不存在');
        }

        return DB::transaction(function () use ($userId, $coupon) {
            // 1. 每人限领（与条件更新同事务，防并发绕过）
            $mine = UserCoupon::where('user_id', $userId)->where('coupon_id', $coupon->id)->count();
            if ($mine >= $coupon->per_user_limit) {
                throw BusinessException::conflict('已达每人限领数量');
            }

            // 2. 原子防超发：条件更新（状态 + 余量），受影响行数为 0 即失败
            $affected = DB::table('coupons')
                ->where('id', $coupon->id)
                ->where('status', Coupon::STATUS_ACTIVE)
                ->whereColumn('issued_count', '<', 'total_count')
                ->update(['issued_count' => DB::raw('issued_count + 1')]);

            if ($affected === 0) {
                throw BusinessException::conflict('优惠券已领完或已停止发放');
            }

            // 3. 计算并固化 expire_at
            $expireAt = $this->resolveExpireAt($coupon);

            return UserCoupon::create([
                'user_id' => $userId,
                'coupon_id' => $coupon->id,
                'status' => UserCoupon::STATUS_UNUSED,
                'expire_at' => $expireAt,
            ]);
        });
    }

    /**
     * 我的券（按状态分页）
     *
     * status 语义：
     *  - unused   → status=unused 且未过期
     *  - used     → status=used
     *  - expired  → status=expired，或 status=unused 但已过期（定时任务尚未收敛）
     *  - returned → status=returned
     *  - null     → 全部
     */
    public function myCoupons(int $userId, ?string $status, int $perPage = 15, int $page = 1): LengthAwarePaginator
    {
        $now = now();

        $query = UserCoupon::with('coupon')->where('user_id', $userId);

        $query = match ($status) {
            UserCoupon::STATUS_UNUSED => $query->where('status', UserCoupon::STATUS_UNUSED)->where('expire_at', '>=', $now),
            UserCoupon::STATUS_EXPIRED => $query->where(fn ($q) => $q
                ->where('status', UserCoupon::STATUS_EXPIRED)
                ->orWhere(fn ($q2) => $q2->where('status', UserCoupon::STATUS_UNUSED)->where('expire_at', '<', $now))),
            UserCoupon::STATUS_USED, UserCoupon::STATUS_RETURNED => $query->where('status', $status),
            default => $query,
        };

        return $query->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * 结算可用券：返回可用券与不可用原因
     *
     * @param  array<int, array{product_id?: int, category_id?: int, price: float|string, quantity: int}>  $items
     * @return array{usable: array<int, array<string, mixed>>, unusable: array<int, array<string, mixed>>}
     */
    public function availableFor(int $userId, array $items, float $totalAmount): array
    {
        $now = now();
        $ctx = $this->buildContext($items);
        $fallbackTotal = round($totalAmount, 2);

        $userCoupons = UserCoupon::with('coupon')
            ->where('user_id', $userId)
            ->where('status', UserCoupon::STATUS_UNUSED)
            ->orderBy('expire_at')
            ->get();

        $usable = [];
        $unusable = [];

        foreach ($userCoupons as $uc) {
            $coupon = $uc->coupon;
            if (! $coupon) {
                continue;
            }

            if ($uc->expire_at->lt($now)) {
                $unusable[] = $this->briefUnusable($uc, '已过期');

                continue;
            }

            // 券模板已停发（status != active）的未用券不再可用（先移入不可用，避免列表出现「可用过期/停用券」）
            if ($coupon->status !== Coupon::STATUS_ACTIVE) {
                $unusable[] = $this->briefUnusable($uc, '优惠券已停止使用');

                continue;
            }

            // 门店上下文优先按行项目命中金额；无行项目时对全场券回退到总金额
            $base = ($ctx->lines === [] && $coupon->scope === Coupon::SCOPE_ALL)
                ? $fallbackTotal
                : $ctx->scopeBaseAmount($coupon->scope, $coupon->scope_refs ?? []);

            if ($coupon->scope !== Coupon::SCOPE_ALL && $base <= 0) {
                $unusable[] = $this->briefUnusable($uc, '适用范围不符');

                continue;
            }

            if ($base < (float) $coupon->min_spend) {
                $unusable[] = $this->briefUnusable($uc, '未满使用门槛');

                continue;
            }

            $usable[] = [
                'user_coupon_id' => $uc->id,
                'coupon_id' => $coupon->id,
                'name' => $coupon->name,
                'type' => $coupon->type,
                'type_label' => Coupon::TYPE_LABELS[$coupon->type] ?? $coupon->type,
                'amount' => $coupon->amount !== null ? (float) $coupon->amount : null,
                'percent' => $coupon->percent,
                'max_discount' => $coupon->max_discount !== null ? (float) $coupon->max_discount : null,
                'min_spend' => (float) $coupon->min_spend,
                'scope' => $coupon->scope,
                'scope_label' => Coupon::SCOPE_LABELS[$coupon->scope] ?? $coupon->scope,
                'expire_at' => $uc->expire_at->format('Y-m-d H:i:s'),
                'near_expiry' => $uc->isNearExpiry(),
                'discount' => $this->couponDiscount($coupon, $base),
            ];
        }

        return ['usable' => $usable, 'unusable' => $unusable];
    }

    /**
     * 券优惠额（fixed 直减；percent 按比例并受 max_discount 封顶）
     *
     * T-034 的分摊入口会复用此方法，保证「预览」与「下单」口径一致。
     */
    public function couponDiscount(Coupon $coupon, float $base): float
    {
        return PricingCalculator::couponDiscount($this->couponModelParams($coupon), $base);
    }

    /**
     * 满减优惠额（按活动命中金额取最优梯度）
     *
     * @param  float  $base  活动命中范围的原始金额
     */
    public function promotionDiscount(Promotion $promotion, float $base): float
    {
        return PricingCalculator::promotionDiscount($promotion->rules ?? [], $base);
    }

    /**
     * 校验用户券可用于给定订单上下文（T-034）
     *
     * 校验项：状态 unused、未过期、券模板仍启用、门槛满足、适用范围命中。
     * 任一不满足抛出业务冲突（40009 / HTTP 409），message 即为不可用原因。
     *
     * @throws BusinessException
     */
    public function validateUse(UserCoupon $uc, OrderContext $ctx): void
    {
        $reason = $this->usabilityReason($uc, $ctx);
        if ($reason !== null) {
            throw BusinessException::conflict($reason);
        }
    }

    /**
     * 返回不可用原因（null = 可用）；供可用券列表与下单校验共用同一口径
     */
    public function usabilityReason(UserCoupon $uc, OrderContext $ctx): ?string
    {
        if ($uc->status !== UserCoupon::STATUS_UNUSED) {
            return '优惠券已使用或不可用';
        }
        if ($uc->isExpired()) {
            return '优惠券已过期';
        }

        $coupon = $uc->coupon;
        if (! $coupon) {
            return '优惠券不存在或已失效';
        }
        if ($coupon->status !== Coupon::STATUS_ACTIVE) {
            return '优惠券已停止使用';
        }

        $base = $ctx->scopeBaseAmount($coupon->scope, $coupon->scope_refs ?? []);
        if ($coupon->scope !== Coupon::SCOPE_ALL && $base <= 0) {
            return '订单中没有适用该优惠券的商品';
        }
        if (round($base, 2) < round((float) $coupon->min_spend, 2)) {
            return '未达到优惠券使用门槛';
        }

        return null;
    }

    /**
     * 计算订单金额明细（T-034）：券 + 满减 → 分摊 → `orders.amount_details` 结构
     *
     * **纯计算**，不落库；落库与状态流转由 T-035 在下单事务内完成。
     *
     * @return array<string, mixed>
     */
    public function priceOrder(OrderContext $ctx, ?UserCoupon $uc = null, ?Promotion $promotion = null): array
    {
        return PricingCalculator::price($ctx, $this->couponParams($uc), $this->promotionParams($promotion));
    }

    /**
     * 用券中心/结算上下文构造：把缺失的分类 id 回库补全（DB 边界），其余为纯计算
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function buildContext(array $items, float|string $freightAmount = 0.0): OrderContext
    {
        $normalized = [];
        $needLookup = [];

        foreach (array_values($items) as $i => $item) {
            $pid = isset($item['product_id']) ? (int) $item['product_id'] : null;
            $normalized[$i] = [
                'product_id' => $pid,
                'sku_id' => isset($item['sku_id']) ? (int) $item['sku_id'] : null,
                'category_id' => isset($item['category_id']) ? (int) $item['category_id'] : null,
                'price' => $item['price'] ?? 0,
                'quantity' => $item['quantity'] ?? 0,
            ];

            if ($normalized[$i]['category_id'] === null && $pid !== null) {
                $needLookup[] = $pid;
            }
        }

        if ($needLookup !== []) {
            $map = DB::table('products')->whereIn('id', array_unique($needLookup))->pluck('category_id', 'id');
            foreach ($normalized as $i => $row) {
                if ($row['category_id'] === null && $row['product_id'] !== null) {
                    $cid = $map->get($row['product_id']);
                    if ($cid !== null) {
                        $normalized[$i]['category_id'] = (int) $cid;
                    }
                }
            }
        }

        return new OrderContext($normalized, $freightAmount);
    }

    /** 批量过期：unused 且 expire_at < now → expired（分批） */
    public function expireOverdue(int $chunkSize = 500): int
    {
        $total = 0;

        UserCoupon::query()
            ->where('status', UserCoupon::STATUS_UNUSED)
            ->where('expire_at', '<', now())
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use (&$total) {
                $ids = $rows->pluck('id')->all();
                $total += DB::table('user_coupons')->whereIn('id', $ids)->update(['status' => UserCoupon::STATUS_EXPIRED]);
            });

        return $total;
    }

    // ---------------- 内部 ----------------

    /** 计算并固化领取后的到期时间 */
    private function resolveExpireAt(Coupon $coupon): \Illuminate\Support\Carbon
    {
        if ($coupon->valid_type === Coupon::VALID_RELATIVE) {
            $days = max(1, (int) ($coupon->valid_days ?? 1));

            return now()->addDays($days);
        }

        // absolute：必须有有效窗口且未过期
        if ($coupon->valid_to === null) {
            throw BusinessException::conflict('优惠券有效期配置有误');
        }
        if ($coupon->valid_to->lt(now())) {
            throw BusinessException::conflict('优惠券已过期');
        }

        return $coupon->valid_to->copy();
    }

    /**
     * 用户券 → 计算器券参数（含 user_coupon_id）；券模板已删则返回 null
     *
     * @return array<string, mixed>|null
     */
    public function couponParams(?UserCoupon $uc): ?array
    {
        if (! $uc || ! $uc->coupon) {
            return null;
        }

        return $this->couponModelParams($uc->coupon) + ['user_coupon_id' => $uc->id];
    }

    /** 券模板 → 计算器券参数 */
    private function couponModelParams(Coupon $coupon): array
    {
        return [
            'id' => $coupon->id,
            'type' => $coupon->type,
            'amount' => $coupon->amount !== null ? (float) $coupon->amount : null,
            'percent' => $coupon->percent !== null ? (int) $coupon->percent : null,
            'max_discount' => $coupon->max_discount !== null ? (float) $coupon->max_discount : null,
            'min_spend' => (float) $coupon->min_spend,
            'scope' => $coupon->scope,
            'scope_refs' => $coupon->scope_refs ?? [],
        ];
    }

    /**
     * 满减活动 → 计算器活动参数
     *
     * @return array<string, mixed>|null
     */
    public function promotionParams(?Promotion $promotion): ?array
    {
        if (! $promotion) {
            return null;
        }

        return [
            'id' => $promotion->id,
            'rules' => $promotion->rules ?? [],
            'scope' => $promotion->scope,
            'scope_refs' => $promotion->scope_refs ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function briefUnusable(UserCoupon $uc, string $reason): array
    {
        $coupon = $uc->coupon;

        return [
            'user_coupon_id' => $uc->id,
            'coupon_id' => $coupon?->id,
            'name' => $coupon?->name,
            'min_spend' => $coupon ? (float) $coupon->min_spend : 0.0,
            'expire_at' => $uc->expire_at?->format('Y-m-d H:i:s'),
            'reason' => $reason,
        ];
    }
}
