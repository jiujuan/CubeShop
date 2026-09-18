<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 运费模板（T-042 建表，T-053 Stage1 接入管理）
 *
 * `mode`：fixed / weight / region；`rules` 规则数组（结构见 FreightRuleValidator）。
 * 商品绑定（products.freight_template_id）与下单接线见 Stage 2。
 */
class FreightTemplate extends Model
{
    public const MODE_FIXED = 'fixed';

    public const MODE_WEIGHT = 'weight';

    public const MODE_REGION = 'region';

    protected $fillable = ['name', 'mode', 'rules', 'status'];

    protected $casts = [
        'rules' => 'array',
        'status' => 'integer',
    ];

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('status', 1);
    }

    public function modeLabel(): string
    {
        return match ((string) $this->mode) {
            self::MODE_FIXED => '固定运费',
            self::MODE_WEIGHT => '按重量',
            self::MODE_REGION => '按地区',
            default => (string) $this->mode,
        };
    }
}
