<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\UserCoupon;
use App\Services\Marketing\CouponService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 前台优惠券（V1.1 二期 F06 / T-033）
 *
 * - 领券中心与「我的券」需要登录（领取需归属）；
 * - 领券中心对未登录用户也返回券数据（仅不含个人领取状态），便于引导登录后领取。
 */
class CouponController extends Controller
{
    use ApiResponse;

    public function __construct(private CouponService $coupons)
    {
    }

    /** 领券中心 GET /coupons（登录可选：未登录仅返回券面信息，不含个人领取状态） */
    public function center(Request $request): JsonResponse
    {
        $userId = auth('sanctum')->id();

        return $this->success([
            'list' => $this->coupons->receivableCoupons($userId ? (int) $userId : null),
        ]);
    }

    /** 领取 POST /coupons/{id}/receive */
    public function receive(Request $request, int $id): JsonResponse
    {
        $userCoupon = $this->coupons->receive($request->user()->id, $id);

        return $this->success([
            'user_coupon_id' => $userCoupon->id,
            'coupon_id' => $userCoupon->coupon_id,
            'expire_at' => $userCoupon->expire_at->format('Y-m-d H:i:s'),
        ], '领取成功');
    }

    /** 我的券 GET /me/coupons */
    public function my(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', array_keys(UserCoupon::STATUS_LABELS))],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $paginator = $this->coupons->myCoupons(
            $request->user()->id,
            $data['status'] ?? null,
            min($data['page_size'] ?? 15, 50),
            $data['page'] ?? 1,
        );

        $paginator->through(fn (UserCoupon $uc) => $this->formatUserCoupon($uc));

        return $this->paginated($paginator);
    }

    /** 结算可用券 GET /coupons/available */
    public function available(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['nullable', 'integer', 'min:1'],
            'items.*.category_id' => ['nullable', 'integer', 'min:1'],
            'items.*.price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
        ]);

        $result = $this->coupons->availableFor(
            $request->user()->id,
            $data['items'] ?? [],
            (float) $data['amount'],
        );

        return $this->success($result);
    }

    /** @return array<string, mixed> */
    private function formatUserCoupon(UserCoupon $uc): array
    {
        $coupon = $uc->coupon;
        $expiredUnused = $uc->status === UserCoupon::STATUS_UNUSED && $uc->expire_at->lt(now());
        $status = $expiredUnused ? UserCoupon::STATUS_EXPIRED : $uc->status;

        return [
            'id' => $uc->id,
            'coupon_id' => $uc->coupon_id,
            'name' => $coupon?->name,
            'type' => $coupon?->type,
            'type_label' => $coupon ? (Coupon::TYPE_LABELS[$coupon->type] ?? $coupon->type) : null,
            'amount' => $coupon?->amount !== null ? (float) $coupon->amount : null,
            'percent' => $coupon?->percent,
            'max_discount' => $coupon?->max_discount !== null ? (float) $coupon->max_discount : null,
            'min_spend' => $coupon ? (float) $coupon->min_spend : 0.0,
            'scope' => $coupon?->scope,
            'scope_label' => $coupon ? (Coupon::SCOPE_LABELS[$coupon->scope] ?? $coupon->scope) : null,
            'status' => $status,
            'status_label' => UserCoupon::STATUS_LABELS[$status] ?? $status,
            'expire_at' => $uc->expire_at->format('Y-m-d H:i:s'),
            'near_expiry' => $uc->isNearExpiry(),
            'used_order_id' => $uc->used_order_id,
            'used_at' => $uc->used_at?->format('Y-m-d H:i:s'),
        ];
    }
}
