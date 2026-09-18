<?php

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * P2-11 终态：对外标识 public_id（ULID）能力。
 *
 * - creating 时自动生成 26 位 ULID（时间有序、不依赖 app.key）；
 * - getRouteKeyName() 返回 public_id，使隐式路由模型绑定按对外标识解析；
 * - scopeWherePublicId / resolvePublicId 提供按列查询与解析（替代 sqids decode）。
 *
 * 注意：内部一律仍用 int 主键；public_id 仅在边界（控制器出口 / 路由入参）使用。
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function scopeWherePublicId(Builder $query, string $publicId): Builder
    {
        return $query->where('public_id', $publicId);
    }

    /**
     * 按存储的 public_id 解析模型；兼容历史 int 主键（过渡期）。
     */
    public static function resolvePublicId(string $publicId): ?static
    {
        /** @var Builder $query */
        $query = static::query()->where('public_id', $publicId);

        if (ctype_digit($publicId) && strlen($publicId) <= 19) {
            $query->orWhere('id', (int) $publicId);
        }

        return $query->first();
    }
}
