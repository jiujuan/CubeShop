<?php

namespace App\Casts;

use App\Support\MediaUrl;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * 单值图片路径：库里存相对路径，读取时拼绝对 URL（媒体治理 P0）
 *
 * - **写**：任意形态（绝对 URL / 根相对 / 已经相对）→ `uploads/products/...`（见 {@see MediaUrl::toPath()}）
 * - **读**：拼上磁盘 URL 返回绝对路径，前端零改动
 * - 外链 / `data:` base64 原样透传
 *
 * 之所以用 cast 而不是模型观察者：cast 在 `$model->attr`、`toArray()`、Resource
 * 三条路径上**都**生效，不会出现有些出口漏掉归一化的问题。
 *
 * @template-implements CastsAttributes<string, string>
 */
class MediaPath implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return MediaUrl::to($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return MediaUrl::toPath($value);
    }
}
