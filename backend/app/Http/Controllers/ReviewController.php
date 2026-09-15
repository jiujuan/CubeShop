<?php

namespace App\Http\Controllers;

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
    public function productReviews(Request $request, int $id): JsonResponse
    {
        $paginator = $this->reviews->productReviews($id, $request->only(['rating', 'sort', 'has_image', 'page', 'page_size']));
        $paginator->through(fn (Review $r) => $this->format($r, forPublic: true));

        return $this->success([
            'summary' => $this->reviews->summary($id),
            'list' => $paginator->items(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
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
        $paginator->through(fn (Review $r) => $this->format($r) + [
            'product' => $r->product?->only(['id', 'title', 'main_image']),
        ]);

        return $this->paginated($paginator);
    }

    /** 修改评价 PUT /reviews/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $review = Review::find($id);
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

        return $this->success($this->format($review), '评价已更新');
    }

    /**
     * 评价序列化
     */
    private function format(Review $r, bool $forPublic = false): array
    {
        $base = [
            'id' => $r->id,
            'rating' => $r->rating,
            'content' => $r->content,
            'images' => $r->images ?? [],
            'is_anonymous' => (bool) $r->is_anonymous,
            'status' => $r->status,
            'status_label' => Review::STATUS_LABELS[$r->status] ?? $r->status,
            'reply_content' => $r->reply_content,
            'reply_at' => $r->reply_at?->format('Y-m-d H:i:s'),
            'edited_at' => $r->edited_at?->format('Y-m-d H:i:s'),
            'created_at' => $r->created_at?->format('Y-m-d H:i:s'),
            'nickname' => $r->displayName(),
            'avatar' => $r->is_anonymous ? null : $r->user?->avatar,
        ];

        if (! $forPublic) {
            $base['product_id'] = $r->product_id;
            $base['order_id'] = $r->order_id;
            $base['order_item_id'] = $r->order_item_id;
            $base['can_edit'] = $r->canEdit();
        }

        return $base;
    }
}
