<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 发货记录（T-042，E03）
 *
 * 一单一发货；重复发货在业务层（OrderService）拒绝。
 * `trace_status`：pending（未拉取）/ in_transit（运输中）/ delivered（已签收）/ failed（连续拉取失败）。
 */
class Shipping extends Model
{
    public const TRACE_PENDING = 'pending';

    public const TRACE_IN_TRANSIT = 'in_transit';

    public const TRACE_DELIVERED = 'delivered';

    public const TRACE_FAILED = 'failed';

    protected $fillable = [
        'order_id', 'company_code', 'company_name', 'tracking_no',
        'trace_status', 'pull_fail_count', 'last_fail_message', 'shipped_at', 'delivered_at',
    ];

    protected $casts = [
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function traces(): HasMany
    {
        return $this->hasMany(ShippingTrace::class)->orderByDesc('occurred_at');
    }
}
