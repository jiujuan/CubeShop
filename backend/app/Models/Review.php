<?php

namespace App\Models;

use App\Models\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 商品评价（V1.1 F01 / T-015）
 */
class Review extends Model
{
    use HasPublicId;

    protected $table = 'reviews';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => '待审核',
        self::STATUS_APPROVED => '已通过',
        self::STATUS_REJECTED => '已拒绝',
    ];

    /** 提交后允许修改的天数窗口 */
    public const EDIT_WINDOW_DAYS = 30;

    /** 单条评价最多图片数 */
    public const MAX_IMAGES = 9;

    /** 内容最大长度 */
    public const MAX_CONTENT = 500;

    protected $fillable = [
        'order_id', 'order_item_id', 'user_id', 'product_id', 'sku_id',
        'rating', 'content', 'images', 'is_anonymous', 'status', 'reject_reason',
        'is_hidden', 'reply_content', 'reply_at', 'edited_at',
    ];

    protected $casts = [
        'images' => 'array',
        'is_anonymous' => 'boolean',
        'is_hidden' => 'boolean',
        'rating' => 'integer',
        'reply_at' => 'datetime',
        'edited_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductSku::class, 'sku_id');
    }

    /** 前台可见范围（未被后台隐藏） */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_hidden', false);
    }

    /** 是否仍在可修改窗口内 */
    public function canEdit(): bool
    {
        return $this->edited_at === null
            && $this->created_at !== null
            && $this->created_at->diffInDays(now()) <= self::EDIT_WINDOW_DAYS;
    }

    /** 展示昵称（匿名脱敏） */
    public function displayName(): string
    {
        $name = $this->user?->nickname ?: $this->user?->username ?? '匿名用户';
        if (! $this->is_anonymous) {
            return $name;
        }

        return mb_substr($name, 0, 1).'**';
    }
}
