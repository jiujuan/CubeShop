<?php

namespace App\Services\Common;

use Illuminate\Support\Facades\Cache;

/**
 * 缓存封装（架构文档 4.1.1）
 * 统一缓存键规范：cubeshop:{module}:{key}（CACHE_PREFIX 由 .env 提供）
 */
class CacheService
{
    public function key(string $module, string|int $key): string
    {
        return implode(':', [$module, $key]);
    }

    public function get(string $module, string|int $key, mixed $default = null): mixed
    {
        return Cache::get($this->key($module, $key), $default);
    }

    public function put(string $module, string|int $key, mixed $value, int $ttlSeconds): void
    {
        Cache::put($this->key($module, $key), $value, $ttlSeconds);
    }

    public function remember(string $module, string|int $key, int $ttlSeconds, callable $callback): mixed
    {
        return Cache::remember($this->key($module, $key), $ttlSeconds, $callback);
    }

    public function forget(string $module, string|int $key): void
    {
        Cache::forget($this->key($module, $key));
    }
}
