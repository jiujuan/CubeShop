<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 余额流水（收银台方案 §4.1(3)）：只增不改，记录 before/after
 */
class UserBalanceLog extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_RECHARGE = 'recharge';
    public const TYPE_CONSUME = 'consume';
    public const TYPE_REFUND = 'refund';
    public const TYPE_ADMIN_ADJUST = 'admin_adjust';

    public const TYPE_LABELS = [
        self::TYPE_RECHARGE => '充值',
        self::TYPE_CONSUME => '消费',
        self::TYPE_REFUND => '退款退回',
        self::TYPE_ADMIN_ADJUST => '后台调整',
    ];

    protected $table = 'user_balance_logs';

    protected $fillable = [
        'user_id', 'type', 'amount', 'balance_before', 'balance_after',
        'related_type', 'related_id', 'remark', 'created_by', 'created_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }
}
