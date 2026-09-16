<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Marketing\CouponService;
use App\Services\Marketing\PromotionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 前台满减活动（V1.1 二期 F06 / T-039）
 *
 * 结算页/详情页的满减预览：入参口径与 `POST /orders` 的行项目一致
 * （缺 category_id 由 `CouponService::buildContext` 回库补全），
 * 返回 `PromotionService::displayFor()` 的展示结构（无命中为 null）。
 */
class PromotionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private PromotionService $promotions,
        private CouponService $coupons,
    ) {
    }

    /** 满减预览 GET /promotions/preview（行项目口径与下单一致，满减只按行项目原始金额命中） */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', 'min:1'],
            'items.*.category_id' => ['nullable', 'integer', 'min:1'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $ctx = $this->coupons->buildContext($data['items']);

        return $this->success([
            'promotion' => $this->promotions->displayFor($ctx),
        ]);
    }
}
