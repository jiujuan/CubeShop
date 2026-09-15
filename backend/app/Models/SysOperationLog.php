<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SysOperationLog extends Model
{
    protected $table = 'sys_operation_log';

    /** 表只有 created_at，无 updated_at */
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'module',
        'action',
        'target_type',
        'target_id',
        'content',
        'ip',
        'user_agent',
    ];

    public function user()
    {
        return $this->belongsTo(SysUser::class, 'user_id');
    }
}
