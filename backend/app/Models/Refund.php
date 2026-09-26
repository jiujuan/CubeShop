<?php

namespace App\Models;

use App\Casts\MediaPathList;
use App\Models\Traits\HasPublicId;
use App\Models\Traits\ReleasesMediaOnDelete;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 退款申请（数据库设计 2.8）
 */
class Refund extends Model
{
    use HasPublicId, ReleasesMediaOnDelete;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PROCESSING = 'processing'; // 渠道已受理，等待异步确认

    /** 退款类型：仅退款 / 退货退款（WMS 退货闭环基础，P4 / D3） */
    public const TYPE_REFUND = 'refund';
    public const TYPE_RETURN_REFUND = 'return_refund';

    /** 退货状态（仅 return_refund 使用）：待退货 / 退货中 / 已收货 / 异常 */
    public const RETURN_STATUS_WAITING_RETURN = 'waiting_return';
    public const RETURN_STATUS_SHIPPING = 'shipping';
    public const RETURN_STATUS_RECEIVED = 'received';
    public const RETURN_STATUS_EXCEPTION = 'exception';

    /** 实收明细商品状态：正品 / 残次 */
    public const RETURN_CONDITION_GOOD = 'good';
    public const RETURN_CONDITION_DEFECTIVE = 'defective';

    /** 状态中文名（后台展示，G7 资金视图复用） */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => '待审核',
        self::STATUS_APPROVED => '已同意',
        self::STATUS_REJECTED => '已拒绝',
        self::STATUS_SUCCESS => '退款成功',
        self::STATUS_PROCESSING => '退款处理中',
        self::STATUS_FAILED => '退款失败',
    ];

    /** 类型中文名（后台/导出展示） */
    public const TYPE_LABELS = [
        self::TYPE_REFUND => '仅退款',
        self::TYPE_RETURN_REFUND => '退货退款',
    ];

    /** 退货状态中文名（后台/导出展示） */
    public const RETURN_STATUS_LABELS = [
        self::RETURN_STATUS_WAITING_RETURN => '待退货',
        self::RETURN_STATUS_SHIPPING => '退货中',
        self::RETURN_STATUS_RECEIVED => '已收货',
        self::RETURN_STATUS_EXCEPTION => '异常',
    ];

    protected $table = 'refunds';
    protected $fillable = [
        'refund_no', 'order_id', 'order_no', 'user_id', 'type', 'warehouse_id',
        'amount', 'reason', 'images', 'status', 'admin_remark', 'admin_images',
        'processed_by', 'processed_at',
        'refund_details',
        'return_tracking_no', 'return_express_company', 'return_status',
        'return_details', 'return_received_details', 'return_received_at', 'return_exception_reason',
        'channel', 'payment_no', 'out_refund_no', 'channel_refund_no', 'refund_status',
        'channel_raw', 'failed_reason', 'refunded_at', 'retry_count',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'refund_details' => 'array',
        'images' => MediaPathList::class,
        'admin_images' => MediaPathList::class,
        'return_details' => 'array',
        'return_received_details' => 'array',
        'return_received_at' => 'datetime',
        'processed_at' => 'datetime',
        'warehouse_id' => 'integer',
        'channel_raw' => 'array',
        'refunded_at' => 'datetime',
        'retry_count' => 'integer',
    ];

    /** 仅退款快捷判断 */
    public function isReturnRefund(): bool
    {
        return $this->type === self::TYPE_RETURN_REFUND;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** 处理人（后台管理员 sys_user） */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(SysUser::class, 'processed_by');
    }

    /** 退款事件日志（refund_logs，append-only） */
    public function refundLogs(): HasMany
    {
        return $this->hasMany(RefundLog::class, 'refund_id');
    }
}
