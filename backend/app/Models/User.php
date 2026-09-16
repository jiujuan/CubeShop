<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * 买家（前台注册用户）
 * 表：users
 *
 * 与后台管理员（App\Models\SysUser，表 sys_user）账号体系物理隔离：
 * - 买家不参与 spatie 权限体系，故不使用 HasRoles；
 * - 买家身份识别方式为「本表记录」而非角色；
 * - 业务表的 user_id 一律指向本表，列名无需改动。
 *
 * 方案文档：docs/design/CubeShop_UserTable_Split_Analysis.md
 */
class User extends Authenticatable
{
    use HasApiTokens, SoftDeletes;

    protected $table = 'users';

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

    /** 买家订单（后台用户管理统计用） */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'user_id');
    }
}
