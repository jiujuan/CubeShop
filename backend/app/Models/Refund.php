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

    protected $table = 'refunds';
    protected $fillable = [
        'refund_no', 'order_id', 'order_no', 'user_id', 'type', 'warehouse_id',
        'amount', 'reason', 'images', 'status', 'admin_remark', 'admin_images',
        'processed_by', 'processed_at',
        'refund_details',
        'return_tracking_no', 'return_express_company', 'return_status',
        'return_details', 'return_received_details', 'return_received_at', 'return_exception_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'refund_details' => 'array',
        'images' => 'array',
        'admin_images' => 'array',
        'return_details' => 'array',
        'return_received_details' => 'array',
        'return_received_at' => 'datetime',
        'processed_at' => 'datetime',
        'warehouse_id' => 'integer',
    ];

    /** 仅退款快捷判断 */
    public function isReturnRefund(): bool
    {
        return $this->type === self::TYPE_RETURN_REFUND;
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
}
