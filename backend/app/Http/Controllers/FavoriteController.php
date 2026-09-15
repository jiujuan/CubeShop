<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\Favorite\FavoriteService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 收藏与浏览足迹（V1.1 F05 / T-024）
 * 全部接口需登录；数据按 user_id 隔离。
 */
class FavoriteController extends Controller
{
    use ApiResponse;

    public function __construct(private FavoriteService $favorites)
    {
    }

    /** 收藏 POST /products/{id}/favorite */
    public function store(Request $request, int $id): JsonResponse
    {
        $this->assertProductExists($id);
        $this->favorites->add($request->user()->id, $id);

        return $this->success(['favorited' => true], '收藏成功');
    }

    /** 取消收藏 DELETE /products/{id}/favorite */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->favorites->remove($request->user()->id, $id);

        return $this->success(['favorited' => false], '已取消收藏');
    }

    /** 收藏列表 GET /me/favorites */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->favorites->paginate(
            $request->user()->id,
            $data['page'] ?? 1,
            min($data['page_size'] ?? 20, 100),
        );

        $paginator->through(fn ($fav) => $this->brief($fav->product));

        return $this->paginated($paginator);
    }

    /** 批量取消收藏 POST /me/favorites/batch-remove */
    public function batchRemove(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
        ]);

        $removed = $this->favorites->batchRemove($request->user()->id, $data['product_ids']);

        return $this->success(['removed' => $removed], '已取消收藏');
    }

    /** 上报足迹 POST /products/{id}/track（幂等去重） */
    public function track(Request $request, int $id): JsonResponse
    {
        $this->assertProductExists($id);
        $this->favorites->track($request->user()->id, $id);

        return $this->success(null, '已记录');
    }

    /** 足迹列表 GET /me/histories */
    public function histories(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->favorites->paginateHistories(
            $request->user()->id,
            $data['page'] ?? 1,
            min($data['page_size'] ?? 20, 100),
        );

        $paginator->through(fn ($h) => $this->brief($h->product) + [
            'browsed_at' => $h->browsed_at?->format('Y-m-d H:i:s'),
        ]);

        return $this->paginated($paginator);
    }

    /** 清空足迹 DELETE /me/histories */
    public function clearHistories(Request $request): JsonResponse
    {
        $removed = $this->favorites->clearHistories($request->user()->id);

        return $this->success(['removed' => $removed], '已清空足迹');
    }

    // ---------- internals ----------

    private function assertProductExists(int $id): void
    {
        if (! Product::whereKey($id)->exists()) {
            throw \App\Exceptions\BusinessException::notFound('商品不存在');
        }
    }

    /** 商品简要结构 + 失效标记 */
    private function brief(?Product $product): array
    {
        if (! $product) {
            return [
                'id' => null, 'title' => '商品已删除', 'subtitle' => null, 'main_image' => null,
                'price' => '0.00', 'sales_count' => 0, 'status' => 0,
                'is_available' => false, 'unavailable_reason' => '商品已删除',
            ];
        }

        $product->loadMissing(['skus.inventory']);

        return [
            'id' => $product->id,
            'title' => $product->title,
            'subtitle' => $product->subtitle,
            'main_image' => $product->main_image,
            'price' => (string) $product->price,
            'sales_count' => (int) $product->sales_count,
            'status' => (int) $product->status,
            ...$this->favorites->availability($product),
        ];
    }
}
