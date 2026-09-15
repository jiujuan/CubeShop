<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 收货地址
 */
class UserAddress extends Model
{
    protected $table = 'user_addresses';
    protected $fillable = [
        'user_id', 'contact_name', 'contact_phone',
        'province', 'city', 'district', 'detail_address', 'label', 'is_default',
        'used_count', 'last_used_at',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'used_count' => 'integer',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(SysUser::class, 'user_id');
    }
}
