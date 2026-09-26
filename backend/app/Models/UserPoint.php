<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 积分账户（会员成长计划 S1）
 *
 * 每用户一行，与 UserBalance 同体例：这里存的是**余额冗余**，
 * 权威账本在 user_point_logs。二者由 PointsService 在同一事务内更新。
 *
 * 唯一写入口：App\Services\Member\PointsService —— 任何绕过它的直接改余额都会造成
 * 账户与流水不一致（与「余额只走 BalanceService」同款铁律）。
 */
class UserPoint extends Model
{
    protected $table = 'user_points';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = ['user_id', 'balance', 'frozen', 'total_earn', 'total_spend', 'version'];

    protected $casts = [
        'balance' => 'integer',
        'frozen' => 'integer',
        'total_earn' => 'integer',
        'total_spend' => 'integer',
        'version' => 'integer',
    ];

    protected $attributes = [
        'balance' => 0,
        'frozen' => 0,
        'total_earn' => 0,
        'total_spend' => 0,
        'version' => 0,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(UserPointLog::class, 'user_id', 'user_id');
    }

    /** 持有总额（含冻结） */
    public function getTotalAttribute(): int
    {
        return $this->balance + $this->frozen;
    }
}
