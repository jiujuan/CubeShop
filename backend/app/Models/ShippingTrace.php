<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 物流轨迹（T-042，F07）
 *
 * 去重口径（T-045）：`shipping_id + occurred_at + context` 唯一判断，避免重复拉取产生重复行。
 * `raw` 保存第三方原始报文，便于排查与渠道切换回填。
 */
class ShippingTrace extends Model
{
    protected $fillable = ['shipping_id', 'context', 'occurred_at', 'raw'];

    protected $casts = [
        'occurred_at' => 'datetime',
        'raw' => 'array',
    ];

    public function shipping(): BelongsTo
    {
        return $this->belongsTo(Shipping::class);
    }
}
