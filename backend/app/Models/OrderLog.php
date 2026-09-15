<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 订单状态流水（V1.1 E02-A / T-001）
 *
 * 只写不更，单时间戳 created_at。
 */
class OrderLog extends Model
{
    public const OPERATOR_USER = 'user';
    public const OPERATOR_ADMIN = 'admin';
    public const OPERATOR_SYSTEM = 'system';

    /** 操作人类型中文名（后台展示） */
    public const OPERATOR_LABELS = [
        self::OPERATOR_USER => '用户',
        self::OPERATOR_ADMIN => '管理员',
        self::OPERATOR_SYSTEM => '系统',
    ];

    protected $table = 'order_logs';

    /** 仅 created_at，无 updated_at */
    public $timestamps = false;

    protected $fillable = [
        'order_id', 'from_status', 'to_status',
        'operator_type', 'operator_id', 'remark', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /** 变更后状态的中文名 */
    public function getToStatusLabelAttribute(): string
    {
        return Order::STATUS_LABELS[$this->to_status] ?? $this->to_status;
    }

    /** 变更前状态的中文名（创建订单时为空） */
    public function getFromStatusLabelAttribute(): ?string
    {
        return $this->from_status ? (Order::STATUS_LABELS[$this->from_status] ?? $this->from_status) : null;
    }

    public function getOperatorTypeLabelAttribute(): string
    {
        return self::OPERATOR_LABELS[$this->operator_type] ?? $this->operator_type;
    }
}
