<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 满减活动（V1.1 F06 / T-031）
 *
 * `rules` 为多级梯度，如 `[{"min":100,"discount":10},{"min":200,"discount":25}]`。
 * 匹配时按订单命中金额取**最优梯度**（见 PromotionService）。
 */
class Promotion extends Model
{
    protected $table = 'promotions';

    public const SCOPE_ALL = 'all';
    public const SCOPE_CATEGORY = 'category';
    public const SCOPE_PRODUCT = 'product';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_STOPPED = 'stopped';

    protected $fillable = [
        'name', 'rules', 'scope', 'scope_refs', 'start_at', 'end_at', 'status',
    ];

    protected $casts = [
        'rules' => 'array',
        'scope_refs' => 'array',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    /** 是否在当前时间窗口内且启用 */
    public function isRunning(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && now()->between($this->start_at, $this->end_at);
    }
}
