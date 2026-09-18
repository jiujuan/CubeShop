<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\Favorite\FavoriteService;
use App\Support\ApiResponse;
use App\Support\PublicId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 收藏与浏览足迹（V1.1 F05 / T-024）
 * 全部接口需登录；数据按 user_id 隔离。
 *
 * SEC-04-B：路由入参同时接受 public_id 与历史 int 主键，出口只给 public_id。
 */
class FavoriteController extends Controller
{
    use ApiResponse;

    public function __construct(private FavoriteService $favorites)
    {
    }

    /** 收藏 POST /products/{id}/favorite */
    public function store(Request $request, string $id): JsonResponse
    {
        $productId = $this->resolveProductId($id);
        $this->favorites->add($request->user()->id, $productId);

        return $this->success(['favorited' => true], '收藏成功');
    }

    /** 取消收藏 DELETE /products/{id}/favorite */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $productId = $this->resolveProductId($id);
        $this->favorites->remove($request->user()->id, $productId);

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

        // through() 返回新实例而非原地修改，必须回写，否则格式化不生效
        $paginator = $paginator->through(fn ($fav) => $this->brief($fav->product));

        return $this->paginated($paginator);
    }

    /** 批量取消收藏 POST /me/favorites/batch-remove */
    public function batchRemove(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
        ]);

        $ids = [];
        foreach ($data['product_ids'] as $value) {
            $resolved = PublicId::resolve(PublicId::SCOPE_PRODUCT, $value);
            if ($resolved !== null) {
                $ids[] = $resolved;
            }
        }

        if ($ids === []) {
            return $this->fail('缺少有效的商品标识', 40000);
        }

        $removed = $this->favorites->batchRemove($request->user()->id, $ids);

        return $this->success(['removed' => $removed], '已取消收藏');
    }

    /** 上报足迹 POST /products/{id}/track（幂等去重） */
    public function track(Request $request, string $id): JsonResponse
    {
        $productId = $this->resolveProductId($id);
        $this->favorites->track($request->user()->id, $productId);

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

        // through() 返回新实例，必须回写
        $paginator = $paginator->through(fn ($h) => $this->brief($h->product) + [
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

    /** 解析并校验商品标识（public_id 或历史 int 主键） */
    private function resolveProductId(string $id): int
    {
        $productId = PublicId::resolve(PublicId::SCOPE_PRODUCT, $id);

        if ($productId === null || ! Product::whereKey($productId)->exists()) {
            throw \App\Exceptions\BusinessException::notFound('商品不存在');
        }

        return $productId;
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
            // P2-11 终态：对外只给 public_id（ULID）
            'id' => $product->public_id,
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
