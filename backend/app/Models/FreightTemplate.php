<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 运费模板（T-042，E03-7，可选启用）
 *
 * `mode`：fixed / weight / region；`rules` 规则数组。
 * 本版仅建表与模型，业务接入见 Phase4（T-053）。
 */
class FreightTemplate extends Model
{
    protected $fillable = ['name', 'mode', 'rules', 'status'];

    protected $casts = [
        'rules' => 'array',
    ];
}
