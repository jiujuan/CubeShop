<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 客服工单类型配置（CS-101 / CS-102）
 *
 * 可自定义类型名称与「是否必须关联订单」，由后台（二期 CS-207）维护。
 */
class CsTicketType extends Model
{
    protected $table = 'cs_ticket_type';

    protected $fillable = [
        'name', 'code', 'require_order', 'sort', 'is_active',
    ];

    protected $casts = [
        'require_order' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    /** 激活且按排序（用户端下拉用） */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort')->orderBy('id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(CsTicket::class, 'type_id');
    }
}
