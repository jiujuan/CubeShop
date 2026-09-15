<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * 系统用户（买家 + 管理员）
 * 表：sys_user
 */
class SysUser extends Authenticatable
{
    use HasApiTokens, HasRoles, SoftDeletes;

    protected $table = 'sys_user';

    protected $fillable = [
        'username',
        'email',
        'phone',
        'password',
        'nickname',
        'avatar',
        'status',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** 用户订单（后台用户管理统计用） */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'user_id');
    }
}
