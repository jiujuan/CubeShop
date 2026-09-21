<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 认证日志（登录 / 注册 / 登出）
 *
 * 承载认证全生命周期留痕，支撑排障（谁、什么时间、什么标识、成功/失败、失败原因、设备、IP）。
 * 仅 created_at，无 updated_at（日志不可变）。
 */
class AuthLog extends Model
{
    public const ACTOR_ADMIN = 'admin';

    public const ACTOR_CUSTOMER = 'customer';

    public const EVENT_LOGIN = 'login';

    public const EVENT_REGISTER = 'register';

    public const EVENT_LOGOUT = 'logout';

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'actor_type', 'identifier', 'event', 'success',
        'fail_reason', 'ip', 'user_agent', 'device_id', 'token_id', 'detail',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'success' => 'boolean',
        'device_id' => 'integer',
        'detail' => 'array',
        'created_at' => 'datetime',
    ];

    /** 身份来源：admin=sys_user，customer=users（按 actor_type 自动选表） */
    public function admin()
    {
        return $this->belongsTo(SysUser::class, 'user_id');
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
