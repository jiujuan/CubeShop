<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Casts\MediaPath;
use Illuminate\Database\Eloquent\Model;

/**
 * 支付对账差异（A7-支付渠道对账）
 *
 * 生命周期：
 * - pending    ：待处理（每日对账发现，可重复对账不重复开单）
 * - processing ：运营已接手核查中（可选中间态）
 * - resolved   ：已处置（补单 / 调账 / 确认无误）
 * - ignored    ：人工认下差异，关单不处理
 *
 * 同一 (reconcile_date, channel, payment_no|'', channel_trade_no|'', diff_type)
 * 同时至多一条 pending（部分唯一索引），每日重跑天然幂等。
 */
class PaymentReconciliationDiff extends Model
{
    // 差异类型
    public const TYPE_MISSING_LOCAL = 'MISSING_LOCAL';        // 渠道账单有、本地无对应支付单（漏单 / 渠道长款）
    public const TYPE_MISSING_CHANNEL = 'MISSING_CHANNEL';    // 本地 success、渠道侧无记录或状态非成功（本地短款 / 资金风险）
    public const TYPE_AMOUNT_MISMATCH = 'AMOUNT_MISMATCH';    // 双方都有但金额不一致（长短款）
    public const TYPE_DUPLICATE_CALLBACK = 'DUPLICATE_CALLBACK'; // 同一渠道交易号多次成功回调（重复回调）
    public const TYPE_UNKNOWN = 'UNKNOWN';

    // 退款对账差异（Phase 5 引入，与支付对账共用本表）
    public const TYPE_REFUND_STATUS_MISMATCH = 'REFUND_STATUS_MISMATCH'; // 本地与渠道退款状态不一致（本地 processing 但渠道已成功 / 本地 success 但渠道失败）
    public const TYPE_REFUND_CHANNEL_MISSING = 'REFUND_CHANNEL_MISSING'; // 本地有退款单但渠道侧已关闭/异常/查无（疑似漏退 / 渠道长款）
    public const TYPE_REFUND_LOCAL_MISSING = 'REFUND_LOCAL_MISSING';     // 渠道有退款但本地无对应（queryRefund 模式一般不触发，留作账单模式扩展）

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_MISSING_LOCAL => '漏单（渠道有本地无）',
        self::TYPE_MISSING_CHANNEL => '本地成功·渠道无记录',
        self::TYPE_AMOUNT_MISMATCH => '金额不一致',
        self::TYPE_DUPLICATE_CALLBACK => '重复回调',
        self::TYPE_UNKNOWN => '未知差异',
        self::TYPE_REFUND_STATUS_MISMATCH => '退款状态不一致',
        self::TYPE_REFUND_CHANNEL_MISSING => '本地有·渠道无退款记录',
        self::TYPE_REFUND_LOCAL_MISSING => '渠道有·本地无退款',
    ];

    // 处置状态
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_IGNORED = 'ignored';

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => '待处理',
        self::STATUS_PROCESSING => '核查中',
        self::STATUS_RESOLVED => '已处置',
        self::STATUS_IGNORED => '已忽略',
    ];

    protected $table = 'payment_reconciliation_diffs';

    protected $fillable = [
        'run_id', 'reconcile_date', 'channel', 'platform', 'diff_type',
        'payment_no', 'channel_trade_no', 'order_no',
        'local_amount', 'channel_amount', 'local_status', 'channel_status',
        'detail', 'status', 'handled_by', 'handled_at', 'handle_remark',
    ];

    protected $casts = [
        'reconcile_date' => DateOnly::class,
        'local_amount' => 'decimal:2',
        'channel_amount' => 'decimal:2',
        'handled_by' => 'integer',
        'handled_at' => 'datetime',
    ];

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->diff_type] ?? $this->diff_type;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** 是否仍处于待处理（只有 pending 可被处置） */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
