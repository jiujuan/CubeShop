<?php

namespace App\Casts;

use App\Support\MediaUrl;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * 嵌套结构里散落的图片 URL（CMS `blocks` 等）
 *
 * `blocks` 的结构是 `[{key: 'hero', data: {bg: '/storage/...', items: [{image: '...'}]}}]`，
 * 图片藏在任意深度的字符串里 —— 因此需要**递归**遍历所有字符串节点做两端换算。
 */
class MediaNested implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        $decoded = $this->decode($value);

        return $decoded === null ? null : $this->walk($decoded, fn (string $v): string => MediaUrl::toIfAsset($v) ?? $v);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        $normalized = $this->walk($value, fn (string $v): string => MediaUrl::toPathIfAsset($v) ?? $v);

        return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<mixed>|null */
    private function decode(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 递归遍历，只对字符串叶子节点应用 $fn
     *
     * @param  callable(string): string  $fn
     * @return mixed
     */
    private function walk(mixed $node, callable $fn): mixed
    {
        if (is_string($node)) {
            return $fn($node);
        }

        if (! is_array($node)) {
            return $node;
        }

        foreach ($node as $k => $v) {
            $node[$k] = $this->walk($v, $fn);
        }

        return $node;
    }
}
