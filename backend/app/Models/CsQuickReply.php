<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 快捷回复模板（CS-201 / 供 CS-203、CS-204 消费）
 *
 * `type_id` 为 null 表示**通用模板**（不绑定工单类型），否则只在对应类型的工单里出现。
 * 不做软删除（配置类数据），停用走删除或后续扩展字段。
 */
class CsQuickReply extends Model
{
    protected $table = 'cs_quick_reply';

    protected $fillable = [
        'type_id', 'title', 'content', 'sort', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'type_id' => 'integer',
        'sort' => 'integer',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(CsTicketType::class, 'type_id');
    }

    /**
     * 某工单类型可用的模板 = 绑定该类型的 + 通用的（type_id 为 null）
     *
     * 传 null 时只取通用模板。
     */
    public function scopeForType(Builder $query, ?int $typeId): Builder
    {
        return $query->where(function (Builder $q) use ($typeId) {
            $q->whereNull('type_id');
            if ($typeId !== null) {
                $q->orWhere('type_id', $typeId);
            }
        });
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }
}
