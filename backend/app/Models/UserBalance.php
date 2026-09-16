<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 余额账户（收银台方案 §4.1(2)）：每用户一行，version 为乐观锁
 */
class UserBalance extends Model
{
    protected $table = 'user_balances';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = ['user_id', 'balance', 'frozen', 'total_recharge', 'total_consume', 'version'];

    protected $casts = [
        'balance' => 'decimal:2',
        'frozen' => 'decimal:2',
        'total_recharge' => 'decimal:2',
        'total_consume' => 'decimal:2',
        'version' => 'integer',
    ];

    protected $attributes = [
        'balance' => 0,
        'frozen' => 0,
        'total_recharge' => 0,
        'total_consume' => 0,
        'version' => 0,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(UserBalanceLog::class, 'user_id', 'user_id');
    }
}
