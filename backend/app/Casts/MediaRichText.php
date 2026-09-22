<?php

namespace App\Casts;

use App\Support\MediaUrl;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * 富文本（HTML / Markdown）里的内联图片
 *
 * 商品详情 `description`、帮助中心 `content` / `content_md` 这类正文本质是「一堆 URL」，
 * 不适合拆成列，因此只对文本做两端换算：
 *
 * - **写**：`http://host/storage/uploads/x.png` → `/storage/uploads/x.png`（去掉写死的域名）
 * - **读**：`/storage/uploads/x.png` → `http://host/storage/uploads/x.png`
 *   —— 必须转回去：前端只代理 `/api`，根相对地址会被浏览器发往本地端口而 404。
 */
class MediaRichText implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return MediaUrl::absoluteEmbedded($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return MediaUrl::normalizeEmbedded($value);
    }
}
