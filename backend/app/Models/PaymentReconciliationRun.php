<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 支付渠道对账批次（A7-支付渠道对账）
 *
 * 每个渠道每个对账日一条。记录本地侧与渠道侧（账单）的笔数、金额汇总与结论。
 * 每日重跑时更新同一条记录，不新建。
 */
class PaymentReconciliationRun extends Model
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_RUNNING => '对账中',
        self::STATUS_DONE => '一致',
        self::STATUS_PARTIAL => '存在差异',
        self::STATUS_FAILED => '账单拉取失败',
    ];

    protected $table = 'payment_reconciliation_runs';

    protected $fillable = [
        'reconcile_date', 'channel', 'status',
        'local_count', 'channel_count', 'matched_count', 'diff_count',
        'local_amount', 'channel_amount', 'note', 'created_by',
        'started_at', 'finished_at',
    ];

    protected $casts = [
        'reconcile_date' => DateOnly::class,
        'local_count' => 'integer',
        'channel_count' => 'integer',
        'matched_count' => 'integer',
        'diff_count' => 'integer',
        'local_amount' => 'decimal:2',
        'channel_amount' => 'decimal:2',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function diffs(): HasMany
    {
        return $this->hasMany(PaymentReconciliationDiff::class, 'run_id');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
