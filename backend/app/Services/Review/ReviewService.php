<?php

namespace App\Services\Review;

use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Review;
use App\Services\Common\ConfigService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 商品评价服务（V1.1 F01 / T-015）
 *
 * - 仅「已完成」订单的行项目可评价，且每行项目仅一次；
 * - 提交后 30 天内可修改一次，修改后重新进入审核；
 * - 评分汇总带缓存，评价写操作后失效。
 */
class ReviewService
{
    private const CACHE_PREFIX = 'product_review_summary:';

    public function __construct(private ConfigService $config)
    {
    }

    /** 是否开启先审后显 */
    public function auditMode(): bool
    {
        return $this->config->get('review.audit_mode', '0') === '1';
    }

    /**
     * 提交评价
     *
     * @param  array{rating:int, content?:string, images?:array<int,string>, is_anonymous?:bool}  $data
     */
    public function submit(Order $order, OrderItem $item, int $userId, array $data): Review
    {
        if ($order->user_id !== $userId) {
            throw BusinessException::forbidden('订单不存在');
        }
        if ($order->status !== Order::STATUS_COMPLETED) {
            throw BusinessException::conflict('订单完成后才能评价');
        }
        if ($item->order_id !== $order->id) {
            throw BusinessException::notFound('订单行项目不存在');
        }
        if (Review::where('order_item_id', $item->id)->exists()) {
            throw BusinessException::conflict('该商品已评价，可在 30 天内修改一次');
        }

        $this->validatePayload($data);

        $review = Review::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'user_id' => $userId,
            'product_id' => $item->product_id,
            'sku_id' => $item->sku_id,
            'rating' => (int) $data['rating'],
            'content' => $data['content'] ?? null,
            'images' => $data['images'] ?? [],
            'is_anonymous' => (bool) ($data['is_anonymous'] ?? false),
            'status' => $this->auditMode() ? Review::STATUS_PENDING : Review::STATUS_APPROVED,
        ]);

        $this->flushSummary($review->product_id);

        return $review;
    }

    /** 修改评价（30 天内且未修改过，修改后重新进入审核） */
    public function update(Review $review, int $userId, array $data): Review
    {
        if ($review->user_id !== $userId) {
            throw BusinessException::forbidden('无权修改该评价');
        }
        if (! $review->canEdit()) {
            throw BusinessException::conflict('已超过可修改期限或已修改过');
        }

        $this->validatePayload($data);

        $review->fill([
            'rating' => (int) $data['rating'],
            'content' => $data['content'] ?? null,
            'images' => $data['images'] ?? [],
            'is_anonymous' => (bool) ($data['is_anonymous'] ?? false),
            'edited_at' => now(),
            'status' => $this->auditMode() ? Review::STATUS_PENDING : Review::STATUS_APPROVED,
        ])->save();

        $this->flushSummary($review->product_id);

        return $review;
    }

    /**
     * 商品评分汇总（缓存）：平均分、总数、各星级占比、好评率
     *
     * @return array{avg: float, total: int, star_counts: array<int,int>, good_rate: float}
     */
    public function summary(int $productId): array
    {
        return Cache::remember(self::CACHE_PREFIX.$productId, $this->summaryTtl(), function () use ($productId) {
            $rows = Review::where('product_id', $productId)
                ->where('status', Review::STATUS_APPROVED)
                ->where('is_hidden', false)
                ->select('rating', DB::raw('count(*) as cnt'))
                ->groupBy('rating')
                ->pluck('cnt', 'rating')
                ->all();

            $total = array_sum($rows);
            $starCounts = [];
            for ($star = 1; $star <= 5; $star++) {
                $starCounts[$star] = (int) ($rows[$star] ?? 0);
            }
            $sum = 0;
            foreach ($starCounts as $star => $cnt) {
                $sum += $star * $cnt;
            }
            $good = $starCounts[4] + $starCounts[5];

            return [
                'avg' => $total > 0 ? round($sum / $total, 1) : 0.0,
                'total' => $total,
                'star_counts' => $starCounts,
                'good_rate' => $total > 0 ? round($good / $total * 100, 1) : 0.0,
            ];
        });
    }

    /** 商品评价列表（仅已通过，支持排序与评分筛选） */
    public function productReviews(int $productId, array $filters): LengthAwarePaginator
    {
        $q = Review::with(['user:id,username,nickname,avatar'])
            ->where('product_id', $productId)
            ->where('status', Review::STATUS_APPROVED)
            ->where('is_hidden', false);

        if (! empty($filters['rating'])) {
            $q->where('rating', (int) $filters['rating']);
        }
        if (! empty($filters['has_image'])) {
            $q->whereNotNull('images')->where('images', '!=', '[]');
        }

        match ($filters['sort'] ?? 'newest') {
            'rating_desc' => $q->orderByDesc('rating')->orderByDesc('id'),
            'rating_asc' => $q->orderBy('rating')->orderByDesc('id'),
            default => $q->orderByDesc('id'),
        };

        return $q->paginate(min((int) ($filters['page_size'] ?? 10), 50), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    /** 我的评价（被后台隐藏的同样不展示） */
    public function myReviews(int $userId, int $page = 1, int $pageSize = 10): LengthAwarePaginator
    {
        // P2-11：本人场景需回传订单/行项目 public_id（前端据此定位行项目），一并预加载避免 N+1
        return Review::with(['product:id,title,main_image,public_id', 'order:id,public_id', 'orderItem:id,public_id'])
            ->where('user_id', $userId)
            ->where('is_hidden', false)
            ->orderByDesc('id')
            ->paginate(min($pageSize, 50), ['*'], 'page', $page);
    }

    // ---------- 后台 ----------

    public function adminList(array $filters): LengthAwarePaginator
    {
        $q = Review::with(['user:id,username,nickname', 'product:id,title']);

        if (! empty($filters['keyword'])) {
            $kw = $filters['keyword'];
            $q->where(function ($sub) use ($kw) {
                $sub->where('content', 'like', "%{$kw}%")
                    ->orWhereHas('product', fn ($p) => $p->where('title', 'like', "%{$kw}%"));
            });
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (isset($filters['hidden'])) {
            $q->where('is_hidden', (bool) $filters['hidden']);
        }
        if (! empty($filters['rating'])) {
            $q->where('rating', (int) $filters['rating']);
        }

        // 待审核优先，其次最新
        $q->orderByRaw("case when status = 'pending' then 0 else 1 end")->orderByDesc('id');

        return $q->paginate(min((int) ($filters['page_size'] ?? 20), 100), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function approve(Review $review): Review
    {
        $review->status = Review::STATUS_APPROVED;
        $review->reject_reason = null;
        $review->save();
        $this->flushSummary($review->product_id);

        return $review;
    }

    public function reject(Review $review, string $reason): Review
    {
        $review->status = Review::STATUS_REJECTED;
        $review->reject_reason = $reason;
        $review->save();
        $this->flushSummary($review->product_id);

        return $review;
    }

    /** 商家回复（覆盖语义），并触发 ReviewReplied 事件 */
    public function reply(Review $review, string $content): Review
    {
        $review->reply_content = $content;
        $review->reply_at = now();
        $review->save();

        event(new \App\Events\ReviewReplied($review));

        return $review;
    }

    /**
     * 隐藏 / 显示评价（V1.2：后台评价管理）
     *
     * 隐藏后前台列表不再展示、也不计入评分汇总；评价数据保留，可随时恢复。
     */
    public function setHidden(Review $review, bool $hidden): Review
    {
        $review->is_hidden = $hidden;
        $review->save();

        // 评分汇总需同步（隐藏的评价不计分）
        $this->flushSummary($review->product_id);

        return $review;
    }

    public function delete(Review $review): void
    {
        $productId = $review->product_id;
        $review->delete();
        $this->flushSummary($productId);
    }

    /** 后台统计小卡 */
    public function adminStats(): array
    {
        return [
            'pending' => Review::where('status', Review::STATUS_PENDING)->count(),
            'today' => Review::whereDate('created_at', now()->toDateString())->count(),
            'total' => Review::count(),
            'hidden' => Review::where('is_hidden', true)->count(),
            'avg' => round((float) Review::where('status', Review::STATUS_APPROVED)->where('is_hidden', false)->avg('rating'), 1),
        ];
    }

    // ---------- internals ----------

    private function validatePayload(array $data): void
    {
        $rating = (int) ($data['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            throw BusinessException::badRequest('评分需在 1~5 之间');
        }

        $content = (string) ($data['content'] ?? '');
        if (mb_strlen($content) > Review::MAX_CONTENT) {
            throw BusinessException::badRequest('评价内容不能超过 '.Review::MAX_CONTENT.' 字');
        }

        $images = (array) ($data['images'] ?? []);
        if (count($images) > Review::MAX_IMAGES) {
            throw BusinessException::badRequest('最多上传 '.Review::MAX_IMAGES.' 张图片');
        }

        if ($content !== '' && $this->containsSensitive($content)) {
            throw BusinessException::badRequest('评价内容包含敏感词，请修改后重试');
        }
    }

    /** 敏感词命中（词表可配置 review.sensitive_words，JSON 数组） */
    private function containsSensitive(string $content): bool
    {
        $raw = $this->config->get('review.sensitive_words', '["违禁","刷单","代购"]');
        $words = json_decode((string) $raw, true);
        if (! is_array($words)) {
            return false;
        }
        foreach ($words as $word) {
            if (is_string($word) && $word !== '' && mb_strpos($content, $word) !== false) {
                return true;
            }
        }

        return false;
    }

    private function summaryTtl(): int
    {
        return $this->config->getInt('review.summary_cache_ttl', 300);
    }

    private function flushSummary(int $productId): void
    {
        Cache::forget(self::CACHE_PREFIX.$productId);
    }
}
