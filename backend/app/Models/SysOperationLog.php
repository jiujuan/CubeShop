<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 操作日志
 * 表：sys_operation_log
 *
 * 身份语义（V1.1 用户表拆分后）：
 * - `user_id` 为**混合语义**列：后台管理员与买家都会写入
 * - `actor_type` 明确归属：`admin`（后台管理员，指向 sys_user）/ `customer`（买家，指向 users）
 * - 历史数据（actor_type 为 null）一律按 admin 解释
 */
class SysOperationLog extends Model
{
    protected $table = 'sys_operation_log';

    /** 表只有 created_at，无 updated_at */
    const UPDATED_AT = null;

    public const ACTOR_ADMIN = 'admin';

    public const ACTOR_CUSTOMER = 'customer';

    protected $fillable = [
        'user_id',
        'actor_type',
        'module',
        'action',
        'target_type',
        'target_id',
        'content',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'actor_type' => 'string',
    ];

    /** 操作人（后台管理员） */
    public function admin()
    {
        return $this->belongsTo(SysUser::class, 'user_id');
    }

    /** 操作人（买家） */
    public function customer()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 操作人（按 actor_type 自动选择来源表）
     *
     * 保留该方法以兼容既有调用；新代码建议直接用 admin() / customer()。
     */
    public function user()
    {
        return $this->actor_type === self::ACTOR_CUSTOMER ? $this->customer() : $this->admin();
    }

    /** 按操作人类型筛选 */
    public function scopeActor($query, ?string $actorType)
    {
        return $actorType ? $query->where('actor_type', $actorType) : $query;
    }
}
