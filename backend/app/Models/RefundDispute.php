<?php

namespace App\Models;

use App\Casts\MediaPathList;
use App\Models\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 退款纠纷/申诉
 */
class RefundDispute extends Model
{
    use HasPublicId;

    public const STATUS_OPENED = 'opened';
    public const STATUS_PLATFORM_INVOLVED = 'platform_involved';
    public const STATUS_RESOLVED_REFUND = 'resolved_refund';
    public const STATUS_RESOLVED_REJECT = 'resolved_reject';
    public const STATUS_CLOSED = 'closed';

    public const REASON_REFUND_REJECTED = 'refund_rejected';
    public const REASON_GOODS_DAMAGED = 'goods_damaged_dispute';
    public const REASON_TIMEOUT = 'timeout_no_process';
    public const REASON_AMOUNT = 'amount_mismatch';
    public const REASON_NOT_RECEIVED = 'not_received_return';
    public const REASON_OTHER = 'other';

    public const RESOLUTION_REFUND = 'resolved_refund';
    public const RESOLUTION_REJECT = 'resolved_reject';

    public const ACTION_RE_OPEN = 're_open_refund';
    public const ACTION_APPROVE = 'approve_refund';
    public const ACTION_FORCE_RECEIVE = 'force_receive';
    public const ACTION_NONE = 'none';

    public const STATUS_LABELS = [
        self::STATUS_OPENED => '待介入',
        self::STATUS_PLATFORM_INVOLVED => '已介入',
        self::STATUS_RESOLVED_REFUND => '支持买家',
        self::STATUS_RESOLVED_REJECT => '支持商家',
        self::STATUS_CLOSED => '已关闭',
    ];

    public const REASON_LABELS = [
        self::REASON_REFUND_REJECTED => '商家拒绝退款',
        self::REASON_GOODS_DAMAGED => '退货商品争议',
        self::REASON_TIMEOUT => '超时未处理',
        self::REASON_AMOUNT => '退款金额争议',
        self::REASON_NOT_RECEIVED => '未收到退货/已退未收',
        self::REASON_OTHER => '其他',
    ];

    public const RESOLUTION_LABELS = [
        self::RESOLUTION_REFUND => '支持买家（触发退款动作）',
        self::RESOLUTION_REJECT => '支持商家（维持原结论）',
    ];

    public const ACTION_LABELS = [
        self::ACTION_RE_OPEN => '重新发起审核',
        self::ACTION_APPROVE => '同意退款',
        self::ACTION_FORCE_RECEIVE => '强制收货(良品全收)',
        self::ACTION_NONE => '仅记录裁决',
    ];

    protected $table = 'refund_disputes';

    protected $fillable = [
        'public_id', 'refund_id', 'order_id', 'user_id', 'reason_code', 'description',
        'evidence', 'status', 'assigned_admin_id', 'resolution', 'resolution_note',
        'refund_action', 'resolved_by', 'resolved_at', 'closed_at',
    ];

    protected $casts = [
        'evidence' => MediaPathList::class,
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class, 'refund_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(SysUser::class, 'assigned_admin_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(SysUser::class, 'resolved_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(RefundDisputeMessage::class, 'dispute_id');
    }

    public function scopeOpened($query)
    {
        return $query->whereIn('status', [self::STATUS_OPENED, self::STATUS_PLATFORM_INVOLVED]);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
