<?php

namespace App\Services\Common;

use Illuminate\Support\Facades\Cache;
use App\Models\SystemConfig;

/**
 * 系统配置读取服务（架构文档 4.1.1）
 * 带缓存读取 system_configs，更新时自动失效。
 */
class ConfigService
{
    private const CACHE_KEY = 'sys_configs';

    private const TTL_SECONDS = 300;

    public function get(string $key, ?string $default = null): ?string
    {
        $all = $this->all();

        return $all[$key] ?? $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        return (int) ($this->get($key) ?? $default);
    }

    public function getDecimal(string $key, string $default = '0.00'): string
    {
        return number_format((float) ($this->get($key) ?? $default), 2, '.', '');
    }

    /**
     * 全部配置（key => value），带缓存
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, function () {
            return SystemConfig::query()
                ->pluck('config_value', 'config_key')
                ->all();
        });
    }

    public function set(string $key, string $value): void
    {
        SystemConfig::updateOrCreate(
            ['config_key' => $key],
            ['config_value' => $value],
        );
        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
