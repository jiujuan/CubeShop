<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;

/**
 * 用户签到记录（会员成长计划 S2）
 *
 * 一行 = 某用户在某天的签到。同一天不可重复（uk_user_checkin_date）。
 *
 * ⚠️ `checkin_date` 必须走 {@see DateOnly}：SQLite 下 Laravel 默认 `date` cast 会写成
 *    `Y-m-d 00:00:00`，令 `where('checkin_date', '2026-09-27')` 永远查不到，
 *    同日幂等判定全线失效。详见该 cast 的注释。
 *
 * 写入口：App\Services\Member\CheckinService —— 它负责算 streak、发积分、防重复。
 */
class UserCheckin extends Model
{
    protected $table = 'user_checkins';

    protected $fillable = [
        'user_id',
        'checkin_date',
        'streak',
        'points',
        'is_backfill',
        'created_by',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'streak' => 'integer',
        'points' => 'integer',
        'is_backfill' => 'boolean',
        'created_by' => 'integer',
        'checkin_date' => DateOnly::class,
    ];
}
