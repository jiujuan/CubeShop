<?php

namespace App\Services\Favorite;

use App\Models\BrowseHistory;
use App\Models\Favorite;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 收藏与浏览足迹（V1.1 F05 / T-024）
 */
class FavoriteService
{
    /** 足迹保留上限（超出删除最旧） */
    public const HISTORY_LIMIT = 90;

    /** 收藏：幂等新增 */
    public function add(int $userId, int $productId): void
    {
        Favorite::firstOrCreate([
            'user_id' => $userId,
            'product_id' => $productId,
        ]);
    }

    /** 取消收藏：不存在也视为成功（幂等） */
    public function remove(int $userId, int $productId): void
    {
        Favorite::where('user_id', $userId)->where('product_id', $productId)->delete();
    }

    /**
     * 批量取消收藏
     *
     * @param  int[]  $productIds
     * @return int 实际删除数量
     */
    public function batchRemove(int $userId, array $productIds): int
    {
        return Favorite::where('user_id', $userId)->whereIn('product_id', $productIds)->delete();
    }

    /** 收藏列表（分页，含商品快照与失效标记） */
    public function paginate(int $userId, int $page, int $pageSize): LengthAwarePaginator
    {
        return Favorite::where('user_id', $userId)
            ->with('product')
            ->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    /** 是否已收藏 */
    public function isFavorited(int $userId, int $productId): bool
    {
        return Favorite::where('user_id', $userId)->where('product_id', $productId)->exists();
    }

    /**
     * 上报浏览足迹：存在则仅更新 browsed_at（去重），并按上限清理最旧记录
     */
    public function track(int $userId, int $productId): void
    {
        BrowseHistory::updateOrCreate(
            ['user_id' => $userId, 'product_id' => $productId],
            ['browsed_at' => now()],
        );

        $this->cleanup($userId);
    }

    /** 清理：仅保留最近 N 条 */
    public function cleanup(int $userId, ?int $limit = null): int
    {
        $limit = $limit ?? self::HISTORY_LIMIT;
        $keepIds = BrowseHistory::where('user_id', $userId)
            ->orderByDesc('browsed_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id');

        return BrowseHistory::where('user_id', $userId)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }

    /** 足迹列表（分页，按最近浏览倒序） */
    public function paginateHistories(int $userId, int $page, int $pageSize): LengthAwarePaginator
    {
        return BrowseHistory::where('user_id', $userId)
            ->with('product')
            ->orderByDesc('browsed_at')
            ->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    /** 清空足迹 */
    public function clearHistories(int $userId): int
    {
        return BrowseHistory::where('user_id', $userId)->delete();
    }

    /**
     * 商品可用性（上架且有可售库存）
     *
     * @return array{is_available: bool, unavailable_reason: string|null}
     */
    public function availability(?Product $product): array
    {
        if (! $product) {
            return ['is_available' => false, 'unavailable_reason' => '商品已删除'];
        }
        if ((int) $product->status !== 1) {
            return ['is_available' => false, 'unavailable_reason' => '商品已下架'];
        }

        $stock = (int) $product->skus->filter(fn ($s) => (int) $s->status === 1)
            ->sum(fn ($s) => $s->inventory?->stock ?? 0);

        if ($stock <= 0) {
            return ['is_available' => false, 'unavailable_reason' => '商品已售罄'];
        }

        return ['is_available' => true, 'unavailable_reason' => null];
    }
}
