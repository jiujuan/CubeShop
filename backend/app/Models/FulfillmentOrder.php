<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 发货单（WMS 计划 P1 / 设计文档 §2.3、§4.1）
 *
 * 平台履约的唯一载体。状态机是本表的核心：
 *
 * ```
 * created ──► pending_push ──► pushing ──┬─► pushed ──► picking ──► packed ──► shipped ──► completed
 *    │             │              │      └─► push_failed ──┐
 *    └─────────────┴──────────────┴────────────────────────┴─► cancelled
 *                                              exception ─────┘（人工修复后回 pending_push）
 * ```
 *
 * - 状态流转唯一入口是 `FulfillmentOrderService::transitionTo()`（事务 + 行锁 + 校验）；
 * - `outbound_no` 是发往 WMS 的业务单号，也是幂等键载体；
 * - 与订单的关系：发货单**只能**通过 `OrderService::shipForShipment()` 影响订单状态，
 *   本模型自身不碰 `orders.status`。
 */
class FulfillmentOrder extends Model
{
    // ---- 状态 ----
    public const STATUS_CREATED = 'created';

    public const STATUS_PENDING_PUSH = 'pending_push';

    public const STATUS_PUSHING = 'pushing';

    public const STATUS_PUSHED = 'pushed';

    public const STATUS_PICKING = 'picking';

    public const STATUS_PACKED = 'packed';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXCEPTION = 'exception';

    public const STATUS_PUSH_FAILED = 'push_failed';

    /**
     * 合法流转矩阵（唯一真源）
     *
     * 三处「非直觉但必要」的边：
     * - `pushing → pending_push`：推送中断/失败待重试时回到队列口，重试作业得以重入
     *   （否则重试第二次会因 `pushing → pushing` 自环被拒）；
     * - `pending_push → push_failed`：推送前就发现不可推（如配置被删）直接判失败，不必先假进 pushing；
     * - `created / exception / push_failed → pending_push`：未开自动推送时的人工推送、异常修复后重推。
     */
    public const TRANSITIONS = [
        self::STATUS_CREATED => [self::STATUS_PENDING_PUSH, self::STATUS_CANCELLED],
        self::STATUS_PENDING_PUSH => [self::STATUS_PUSHING, self::STATUS_PUSH_FAILED, self::STATUS_EXCEPTION, self::STATUS_CANCELLED],
        self::STATUS_PUSHING => [self::STATUS_PUSHED, self::STATUS_PUSH_FAILED, self::STATUS_PENDING_PUSH, self::STATUS_EXCEPTION, self::STATUS_CANCELLED],
        self::STATUS_PUSHED => [self::STATUS_PICKING, self::STATUS_SHIPPED, self::STATUS_EXCEPTION, self::STATUS_CANCELLED],
        self::STATUS_PICKING => [self::STATUS_PACKED, self::STATUS_SHIPPED, self::STATUS_EXCEPTION, self::STATUS_CANCELLED],
        self::STATUS_PACKED => [self::STATUS_SHIPPED, self::STATUS_EXCEPTION],
        self::STATUS_SHIPPED => [self::STATUS_COMPLETED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_EXCEPTION => [self::STATUS_PENDING_PUSH, self::STATUS_CANCELLED],
        self::STATUS_PUSH_FAILED => [self::STATUS_PENDING_PUSH, self::STATUS_CANCELLED],
    ];

    public const STATUS_LABELS = [
        self::STATUS_CREATED => '已创建',
        self::STATUS_PENDING_PUSH => '待推送',
        self::STATUS_PUSHING => '推送中',
        self::STATUS_PUSHED => '已推送',
        self::STATUS_PICKING => '拣货中',
        self::STATUS_PACKED => '已打包',
        self::STATUS_SHIPPED => '已发货',
        self::STATUS_COMPLETED => '已完成',
        self::STATUS_CANCELLED => '已取消',
        self::STATUS_EXCEPTION => '异常',
        self::STATUS_PUSH_FAILED => '推送失败',
    ];

    /**
     * 可取消状态（WMS 计划 P1 / F7：出库前才能取消）
     *
     * **由 {@see self::TRANSITIONS} 推导**，不手工维护——两者曾各写一份导致漂移：
     * `push_failed` / `exception` 在流转矩阵里都允许 → `cancelled`，白名单却漏了它们，
     * 于是「推送失败的单在后台取消不了」。改为推导后，只要矩阵允许就一定能取消。
     *
     * 出库之后（`packed` 起）矩阵不再含 `cancelled`，自然被排除，只能走退货流程。
     *
     * @return list<string>
     */
    public static function cancellableStates(): array
    {
        $states = [];
        foreach (self::TRANSITIONS as $from => $targets) {
            if (in_array(self::STATUS_CANCELLED, $targets, true)) {
                $states[] = $from;
            }
        }

        return $states;
    }

    /**
     * 可（重）推送状态：新建待推 / 推送失败 / 建单异常修复后。
     *
     * ⚠️ 这份是**后台操作策略**，刻意不从 `TRANSITIONS` 推导：
     * 矩阵里 `pushing → pending_push` 是给「重试作业重入」用的内部边，
     * 若一并推导，会把在途的 `pushing` 也当成可让运营点「推送」的状态。
     */
    public const PUSHABLE = [
        self::STATUS_CREATED,
        self::STATUS_PENDING_PUSH,
        self::STATUS_PUSH_FAILED,
        self::STATUS_EXCEPTION,
    ];

    protected $table = 'fulfillment_orders';

    protected $fillable = [
        'order_id', 'order_no', 'outbound_no', 'warehouse_id', 'provider', 'status',
        'wms_outbound_no', 'tracking_no', 'carrier_code', 'carrier_name',
        'push_request_id', 'push_times', 'last_push_at', 'last_push_error',
        'shipped_at', 'cancelled_at', 'exception_reason',
        'buyer_info', 'shipping_info', 'extend',
    ];

    protected $casts = [
        'push_times' => 'integer',
        'last_push_at' => 'datetime',
        'shipped_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'buyer_info' => 'array',
        'shipping_info' => 'array',
        'extend' => 'array',
        'warehouse_id' => 'integer',
        'order_id' => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(FulfillmentOrderItem::class, 'fulfillment_order_id');
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

    /** 是否已出库（打包之后不允许取消，计划 F7） */
    public function isOutbounded(): bool
    {
        return in_array($this->status, [
            self::STATUS_PACKED, self::STATUS_SHIPPED, self::STATUS_COMPLETED,
        ], true);
    }

    /** 状态中文名 */
    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
