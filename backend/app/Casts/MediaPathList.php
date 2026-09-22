<?php

namespace App\Casts;

use App\Support\MediaUrl;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * 图片路径数组（评价晒图 / 退款凭证 / 工单留言图等 JSON 列）
 *
 * 行为对齐原生 `array` cast（null 与空串都得到 null，而非 []），只是在两端各加一道
 * {@see MediaUrl} 换算：写入归一成相对路径，读取拼成绝对 URL。
 */
class MediaPathList implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if (is_array($value)) {
            return MediaUrl::toMany($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? MediaUrl::toMany($decoded) : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        // 已经是 JSON 串 / 非数组：只在它是合法 JSON 时收录，避免把脏值写坏列
        if (! is_array($value)) {
            return is_string($value) ? $value : null;
        }

        return json_encode(MediaUrl::toPathMany($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
