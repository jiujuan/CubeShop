<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 搜索词频（站内搜索 S1-06）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.7
 *
 * 一行 = 一个被搜过的词。**只做计数，不做分词** —— 存的永远是归一化后的整串
 * （`SearchTokenizer::normalize()`），避免「北欧沙发」和「北欧 沙发」变成两行各算一半热度。
 */
class SearchKeyword extends Model
{
    public const STATUS_ACTIVE = 1;

    public const STATUS_BLOCKED = 0;

    protected $fillable = [
        'keyword',
        'hit_count',
        'result_count',
        'last_hit_at',
        'status',
    ];

    protected $casts = [
        'hit_count' => 'integer',
        'result_count' => 'integer',
        'status' => 'integer',
        'last_hit_at' => 'datetime',
    ];

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
