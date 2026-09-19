<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 退货入库单（WMS 计划 P4 / 设计文档 §2.4、§4.1）
 *
 * 退货闭环的载体：退款审核通过（return_refund）→ 建退货入库单 → 推送 WMS →
 * 收货回传（returnorder.confirm）→ 按实收正品恢复库存 → 退款 success + 订单 refunded。
 *
 * 两条铁律（与发货单同源）：
 * 1. **状态流转唯一执行点** `ReturnInboundOrderService::transitionTo()`；
 * 2. **退款/订单侧一律复用既有收尾**（`OrderService::transitionTo()` → refunded），
 *    本表自身不写 `orders.status`。
 */
class ReturnInboundOrder extends Model
{
    // ---- 状态 ----
    public const STATUS_CREATED = 'created';

    public const STATUS_PENDING_PUSH = 'pending_push';

    public const STATUS_PUSHING = 'pushing';

    public const STATUS_PUSHED = 'pushed';

    public const STATUS_RECEIVING = 'receiving';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXCEPTION = 'exception';

    public const STATUS_PUSH_FAILED = 'push_failed';

    /**
     * 合法流转矩阵（唯一真源）。
     *
     * 与发货单同构的三类边：
     * - `pushing → pending_push`：推送中断/重试重入；
     * - `pending_push → push_failed`：推送前就发现不可推（配置被删等）；
     * - `created / exception / push_failed → pending_push`：人工推送、异常修复后重推。
     * - `pushed → received`：简式回传（无拣收过程事件）直接确认收货。
     */
    public const TRANSITIONS = [
        self::STATUS_CREATED => [self::STATUS_PENDING_PUSH, self::STATUS_CANCELLED],
        self::STATUS_PENDING_PUSH => [self::STATUS_PUSHING, self::STATUS_PUSH_FAILED, self::STATUS_EXCEPTION, self::STATUS_CANCELLED],
        self::STATUS_PUSHING => [self::STATUS_PUSHED, self::STATUS_PUSH_FAILED, self::STATUS_PENDING_PUSH, self::STATUS_EXCEPTION, self::STATUS_CANCELLED],
        self::STATUS_PUSHED => [self::STATUS_RECEIVING, self::STATUS_RECEIVED, self::STATUS_EXCEPTION, self::STATUS_CANCELLED],
        self::STATUS_RECEIVING => [self::STATUS_RECEIVED, self::STATUS_EXCEPTION],
        self::STATUS_RECEIVED => [self::STATUS_COMPLETED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
        // exception → received：超收/异常经人工核实后按实情标记收货（后台兜底）
        self::STATUS_EXCEPTION => [self::STATUS_PENDING_PUSH, self::STATUS_CANCELLED, self::STATUS_RECEIVED],
        self::STATUS_PUSH_FAILED => [self::STATUS_PENDING_PUSH, self::STATUS_CANCELLED],
    ];

    public const STATUS_LABELS = [
        self::STATUS_CREATED => '已创建',
        self::STATUS_PENDING_PUSH => '待推送',
        self::STATUS_PUSHING => '推送中',
        self::STATUS_PUSHED => '已推送',
        self::STATUS_RECEIVING => '收货中',
        self::STATUS_RECEIVED => '已收货',
        self::STATUS_COMPLETED => '已完成',
        self::STATUS_CANCELLED => '已取消',
        self::STATUS_EXCEPTION => '异常',
        self::STATUS_PUSH_FAILED => '推送失败',
    ];

    /**
     * 可（重）推送状态（后台操作策略，与发货单同口径，刻意不从 TRANSITIONS 推导）。
     */
    public const PUSHABLE = [
        self::STATUS_CREATED,
        self::STATUS_PENDING_PUSH,
        self::STATUS_PUSH_FAILED,
        self::STATUS_EXCEPTION,
    ];

    /** 实收质检类型：正品（可回可售库存）/ 残次（不回可售，转人工） */
    public const INVENTORY_TYPE_GOOD = 'ZP';
    public const INVENTORY_TYPE_DEFECTIVE = 'CC';

    protected $table = 'return_inbound_orders';

    protected $fillable = [
        'refund_id', 'refund_no', 'order_id', 'order_no', 'inbound_no',
        'warehouse_id', 'provider', 'status', 'wms_inbound_no',
        'push_request_id', 'push_times', 'last_push_at', 'last_push_error',
        'received_at', 'cancelled_at', 'exception_reason', 'return_reason', 'extend',
    ];

    protected $casts = [
        'push_times' => 'integer',
        'last_push_at' => 'datetime',
        'received_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'extend' => 'array',
        'warehouse_id' => 'integer',
        'refund_id' => 'integer',
        'order_id' => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ReturnInboundOrderItem::class, 'return_inbound_order_id');
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class, 'refund_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /** 状态流转是否合法 */
    public function canTransitTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /** 状态中文名 */
    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
