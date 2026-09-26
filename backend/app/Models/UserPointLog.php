<?php

namespace App\Models;

use App\Support\Member\PointsRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 积分流水（会员成长计划 S1）：只增不改，记录可用与冻结两侧的 before/after
 *
 * 类型字典与记账方向见 App\Support\Member\PointsRules，本模型不重复定义，
 * 避免出现「模型里有常量、服务里另有一套」的双真源。
 */
class UserPointLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'user_point_logs';

    protected $fillable = [
        'user_id', 'type', 'points', 'frozen_points',
        'balance_before', 'balance_after', 'frozen_before', 'frozen_after',
        'related_type', 'related_id', 'biz_key', 'remark', 'created_by', 'created_at',
    ];

    protected $casts = [
        'points' => 'integer',
        'frozen_points' => 'integer',
        'balance_before' => 'integer',
        'balance_after' => 'integer',
        'frozen_before' => 'integer',
        'frozen_after' => 'integer',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getTypeLabelAttribute(): string
    {
        return PointsRules::typeLabel($this->type);
    }
}
