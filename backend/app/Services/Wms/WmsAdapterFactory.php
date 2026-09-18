<?php

namespace App\Services\Wms;

use App\Exceptions\BusinessException;
use App\Models\WmsConfig;
use App\Services\Wms\Adapters\MockAdapter;
use App\Services\Wms\Contracts\WmsAdapter;
use App\Support\WmsProvider;

/**
 * WMS 适配器工厂（WMS 计划 P0 / Step 3）
 *
 * 按 `provider` + `api_env` + 凭证完备度解析实现：
 *
 * | provider | api_env | 凭证 | 结果 |
 * |---|---|---|---|
 * | cainiao | sandbox | 任意 | MockAdapter（沙箱用 Mock 跑通） |
 * | cainiao | prod | 缺 AppKey/AppSecret | MockAdapter，调用时 fail-closed 抛错 |
 * | cainiao | prod | 齐备 | 抛错：菜鸟生产 Adapter 属 P2，本阶段未提供 |
 * | jd_cloud | 任意 | 任意 | 抛错：P8 才支持 |
 * | 其它 | 任意 | 任意 | 抛错：未知服务商 |
 *
 * 说明：P0「明确不做推送」，故生产链路故意不可用而不是给一个假成功——
 * 避免上线后误以为已生效。
 */
class WmsAdapterFactory
{
    public function make(WmsConfig $config): WmsAdapter
    {
        $provider = (string) $config->provider;

        if (! WmsProvider::isValid($provider)) {
            throw BusinessException::badRequest("未知的 WMS 服务商：{$provider}");
        }

        if (! WmsProvider::isAvailable($provider)) {
            throw BusinessException::badRequest(WmsProvider::label($provider).' 适配器将在 P8 提供，当前请使用菜鸟');
        }

        if (! $this->shouldUseMock($config)) {
            // 菜鸟生产环境：P2 才提供 CainiaoAdapter
            throw BusinessException::badRequest('菜鸟生产环境适配器将于 P2 提供，当前请先使用沙箱环境');
        }

        return new MockAdapter($config);
    }

    /**
     * 是否走 Mock 兜底。
     *
     * 非生产环境（sandbox/未配置）一律 Mock；生产环境缺任一凭证也返回 Mock——
     * 但此时 MockAdapter 会在真正调用时 fail-closed 抛错（不产出假成功）。
     */
    public function shouldUseMock(WmsConfig $config): bool
    {
        if ($config->api_env !== 'prod') {
            return true;
        }

        return empty($config->app_key) || ! $config->hasAppSecret();
    }
}
