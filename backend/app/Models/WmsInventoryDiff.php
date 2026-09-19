<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WMS 库存差异（WMS 计划 P5 / F3、F4）
 *
 * 生命周期：`pending` → `resolved`（按 WMS 值校准）/ `ignored`（人工忽略）。
 * 同一仓库同一 SKU 同时至多一条 `pending`（部分唯一索引保证），
 * 因此每日对账天然幂等——重复对账不会给同一差异重复开单。
 */
class WmsInventoryDiff extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_IGNORED = 'ignored';

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => '待处理',
        self::STATUS_RESOLVED => '已校准',
        self::STATUS_IGNORED => '已忽略',
    ];

    protected $fillable = [
        'warehouse_id', 'sku_id', 'wms_sku_code',
        'platform_qty', 'wms_qty', 'diff', 'status',
        'handled_by', 'handled_at', 'remark',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'sku_id' => 'integer',
        'platform_qty' => 'integer',
        'wms_qty' => 'integer',
        'diff' => 'integer',
        'handled_by' => 'integer',
        'handled_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductSku::class, 'sku_id');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** 是否仍处于待处理（只有 pending 可被 resolve） */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
