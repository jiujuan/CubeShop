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
        'order_id', 'company_code', 'company_name', 'tracking_no', 'phone',
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

    /**
     * 取轨迹查询所需的收件人手机号（V1.1 三期）
     *
     * 优先用发货时冗余的 `phone`；历史运单（000110 迁移前）为空时
     * 回落到订单收货快照 `address_snapshot.contact_phone`，避免旧数据查不了顺丰/中通。
     * 调用方建议 `with('order')` 预加载，避免 N+1。
     */
    public function resolvePhone(): ?string
    {
        $phone = trim((string) $this->phone);
        if ($phone !== '') {
            return $phone;
        }

        $snapshot = $this->order?->address_snapshot;
        $fallback = is_array($snapshot) ? trim((string) ($snapshot['contact_phone'] ?? '')) : '';

        return $fallback !== '' ? $fallback : null;
    }
}
