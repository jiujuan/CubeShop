<?php

namespace App\Models;

use App\Models\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 退款申请（数据库设计 2.8）
 */
class Refund extends Model
{
    use HasPublicId;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $table = 'refunds';
    protected $fillable = [
        'refund_no', 'order_id', 'order_no', 'user_id',
        'amount', 'reason', 'status', 'admin_remark', 'processed_by', 'processed_at',
        'refund_details',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'refund_details' => 'array',
        'processed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
