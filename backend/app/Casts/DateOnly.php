<?php

namespace App\Casts;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * 仅日期（无时间）强转 cast（支付对账 reconcile_date 等）。
 *
 * 背景：SQLite 无原生 DATE 类型，Laravel 内置 `date` cast 会按连接日期格式
 * 落库为 `Y-m-d H:i:s`（如 `2026-09-20 00:00:00`），导致 `where('reconcile_date', '2026-09-20')`
 * 永远不匹配，破坏每日重跑幂等（updateOrCreate 始终 miss）与后台按日筛选。
 *
 * 本 cast 写入时统一规整为 `Y-m-d` 字符串、读取时返回 Carbon，PostgreSQL（真 DATE 列）
 * 与 SQLite 行为一致。
 */
class DateOnly implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }

    public function set($model, string $key, $value, array $attributes): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $value instanceof Carbon ? $value->format('Y-m-d') : (string) $value;
    }
}
