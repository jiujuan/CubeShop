<?php

namespace App\Services\Marketing;

use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\UserCoupon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * 优惠券服务（V1.1 二期 F06 / T-033，T-034 扩展金额分摊）
 *
 * 关键点：
 *  - 领取采用 **条件 UPDATE 原子防超发**：`issued_count = issued_count + 1 WHERE issued_count < total_count`，
 *    受影响行数为 0 即判定「已领完」；限领校验与之处于**同一事务**，避免并发绕过。
 *  - `expire_at` 在领取时按 `valid_type` 计算并固化，后续改券模板不影响已领券。
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

            $base = $this->scopeBaseAmount($coupon, $items, $totalAmount);

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
        if ($base <= 0) {
            return 0.0;
        }

        if ($coupon->type === Coupon::TYPE_FIXED) {
            return round(min((float) $coupon->amount, $base), 2);
        }

        $discount = round($base * (100 - (int) $coupon->percent) / 100, 2);
        if ($coupon->max_discount !== null) {
            $discount = min($discount, (float) $coupon->max_discount);
        }

        return round(min($discount, $base), 2);
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
     * 券适用范围命中金额
     *
     * @param  array<int, array{product_id?: int, category_id?: int, price: float|string, quantity: int}>  $items
     */
    private function scopeBaseAmount(Coupon $coupon, array $items, float $totalAmount): float
    {
        // 归一化行金额
        $lines = array_map(fn ($i) => [
            'product_id' => isset($i['product_id']) ? (int) $i['product_id'] : null,
            'price' => (float) ($i['price'] ?? 0),
            'quantity' => (int) ($i['quantity'] ?? 1),
        ], $items);

        if ($coupon->scope === Coupon::SCOPE_ALL) {
            return $lines !== []
                ? round(array_sum(array_map(fn ($l) => $l['price'] * $l['quantity'], $lines)), 2)
                : round($totalAmount, 2);
        }

        $refs = array_map('intval', $coupon->scope_refs ?? []);
        if ($coupon->scope === Coupon::SCOPE_PRODUCT) {
            $matched = array_filter($lines, fn ($l) => $l['product_id'] !== null && in_array($l['product_id'], $refs, true));
        } else {
            // category：按商品归类判定（缺省 category_id 时回库查询）
            $categoryOf = $this->resolveCategoryMap($lines, $items);
            $matched = array_filter($lines, function ($l) use ($refs, $categoryOf) {
                if ($l['product_id'] === null) {
                    return false;
                }
                $cid = $categoryOf[$l['product_id']] ?? null;

                return $cid !== null && in_array((int) $cid, $refs, true);
            });
        }

        if ($matched === []) {
            return 0.0;
        }

        return round(array_sum(array_map(fn ($l) => $l['price'] * $l['quantity'], $matched)), 2);
    }

    /**
     * 解析 items 中商品 → 分类 id（优先使用入参 category_id，缺失则查库）
     *
     * @return array<int, int>
     */
    private function resolveCategoryMap(array $lines, array $rawItems): array
    {
        $map = [];
        $needLookup = [];

        foreach ($lines as $idx => $l) {
            if ($l['product_id'] === null) {
                continue;
            }
            $inline = $rawItems[$idx]['category_id'] ?? null;
            if ($inline !== null) {
                $map[$l['product_id']] = (int) $inline;
            } else {
                $needLookup[] = $l['product_id'];
            }
        }

        if ($needLookup !== []) {
            $rows = DB::table('products')->whereIn('id', array_unique($needLookup))->pluck('category_id', 'id');
            foreach ($rows as $pid => $cid) {
                $map[(int) $pid] = (int) $cid;
            }
        }

        return $map;
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
