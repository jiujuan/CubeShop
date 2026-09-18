<?php

namespace App\Http\Controllers;

use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Services\Review\ReviewService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 商品评价（前台，V1.1 F01 / T-015）
 */
class ReviewController extends Controller
{
    use ApiResponse;

    public function __construct(private ReviewService $reviews)
    {
    }

    /** 商品评价列表与汇总（匿名可访问） GET /products/{id}/reviews */
    public function productReviews(Request $request, string $id): JsonResponse
    {
        $productId = \App\Support\PublicId::resolve(\App\Support\PublicId::SCOPE_PRODUCT, $id);

        if ($productId === null) {
            throw \App\Exceptions\BusinessException::notFound('商品不存在');
        }

        $paginator = $this->reviews->productReviews($productId, $request->only(['rating', 'sort', 'has_image', 'page', 'page_size']));
        // P2-11：公开场景走 ReviewResource（隐藏 user_id / order_id / order_item_id）
        $paginator = $paginator->through(fn (Review $r) => new ReviewResource($r));

        // SEC-04：评价总数属平台经营指标，非本人资源，对外隐藏精确值
        $exposeTotal = $this->shouldExposeTotal();

        return $this->success([
            'summary' => $this->reviews->summary($productId),
            'list' => $paginator->items(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $exposeTotal ? $paginator->total() : null,
                'total_pages' => $exposeTotal ? $paginator->lastPage() : null,
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    /** 我的评价 GET /me/reviews */
    public function myReviews(Request $request): JsonResponse
    {
        $paginator = $this->reviews->myReviews(
            $request->user()->id,
            (int) $request->query('page', 1),
            (int) $request->query('page_size', 10),
        );
        // 本人场景：补充商品引用（public_id）与 can_edit
        $paginator->through(function (Review $r) {
            $r->for_self = true;
            $r->loadMissing('product');

            return new ReviewResource($r);
        });

        return $this->paginated($paginator);
    }

    /** 修改评价 PUT /reviews/{id} */
    public function update(Request $request, string $id): JsonResponse
    {
        // P2-11 终态：评价 id 对外为 public_id，兼容历史 int 主键
        $review = Review::resolvePublicId($id);

        if (! $review) {
            return $this->fail('评价不存在', 40004, null, 404);
        }

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'content' => ['nullable', 'string', 'max:500'],
            'images' => ['nullable', 'array', 'max:9'],
            'images.*' => ['string', 'max:512'],
            'is_anonymous' => ['nullable', 'boolean'],
        ]);

        $review = $this->reviews->update($review, $request->user()->id, $data);

        $review->for_self = true;
        $review->loadMissing('product');

        return $this->success(new ReviewResource($review), '评价已更新');
    }
}
