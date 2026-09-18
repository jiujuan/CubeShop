<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 快递公司字典（T-042，E03）
 *
 * `code` 系统内部编码（发货录入/校验用），`channel_code` 第三方查询渠道编码（T-045）。
 */
class ExpressCompany extends Model
{
    protected $fillable = ['code', 'name', 'channel_code', 'sort', 'status'];

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
