<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 快递公司字典（T-042，E03）
 *
 * `code` 系统内部编码（发货录入/校验用，`shippings.company_code` 唯一允许的取值口径）；
 * `carrier_codes` 多渠道承运商编码映射 {"kuaidi100":"shunfeng","cainiao":"SF","jd_cloud":"JD"}。
 *
 * ⚠️ 编码互转一律走 {@see \App\Support\CarrierCode}，禁止在本模型上直接 where 取值——
 * 会绕过回落优先级。`channel_code` 仅为快递100 的历史兼容列（upgrade 迁移 000113 已回填
 * 到 carrier_codes.kuaidi100），新代码不要读它。
 */
class ExpressCompany extends Model
{
    protected $fillable = ['code', 'name', 'channel_code', 'carrier_codes', 'sort', 'status'];

    protected $casts = [
        'carrier_codes' => 'array',
        'status' => 'integer',
        'sort' => 'integer',
    ];

    public function scopeEnabled($query)
    {
        return $query->where('status', 1)->orderBy('sort');
    }

    /** 运单记录（删除保护用，T-047） */
    public function shippings(): HasMany
    {
        return $this->hasMany(Shipping::class, 'company_code', 'code');
    }
}
