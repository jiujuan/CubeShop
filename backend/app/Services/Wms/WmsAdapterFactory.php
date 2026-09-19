<?php

namespace App\Services\Wms;

use App\Exceptions\BusinessException;
use App\Models\WmsConfig;
use App\Services\Wms\Adapters\Cainiao\CainiaoGateway;
use App\Services\Wms\Adapters\Cainiao\CainiaoNormalizer;
use App\Services\Wms\Adapters\CainiaoAdapter;
use App\Services\Wms\Adapters\MockAdapter;
use App\Services\Wms\Contracts\WmsAdapter;
use App\Support\WmsProvider;
use Illuminate\Support\Facades\Log;

/**
 * WMS 适配器工厂（WMS 计划 P0 / Step 3；P2 / Step 5 接入真实菜鸟）
 *
 * 解析规则（P2 起以**凭证完备度**为唯一判据，`api_env` 不再决定走不走真实网关）：
 *
 * | provider | 凭证 | 结果 |
 * |---|---|---|
 * | cainiao | app_key + app_secret 齐备 | `CainiaoAdapter`（沙箱/生产同一实现，网关地址按 `api_env` 取） |
 * | cainiao | 缺任一 | `MockAdapter`（并记 warning）；**生产**环境调用时 fail-closed 抛错 |
 * | jd_cloud | 任意 | 抛错：P8 才支持 |
 * | 其它 | 任意 | 抛错：未知服务商 |
 *
 * 为什么 `api_env` 不再参与判定：P0「沙箱一律 Mock」是为了在没有真实实现时把配置链路跑通；
 * P2 有了真实 Adapter 后若仍按环境一刀切，就会出现「填了沙箱真凭证却仍在发假成功」——
 * 正是 SEC-01 要杜绝的。改为「有凭证就走真链路」，沙箱联调才会真的打到菜鸟沙箱。
 *
 * fail-closed 的落点：**生产**环境缺凭证时返回的 MockAdapter 会在真正调用时抛
 * `BusinessException`（`MockAdapter::assertCredentialsForProd()`），绝不产出"看起来成功"的假回执。
 */
class WmsAdapterFactory
{
    public function __construct(private readonly CainiaoGateway $gateway) {}

    public function make(WmsConfig $config): WmsAdapter
    {
        $provider = (string) $config->provider;

        if (! WmsProvider::isValid($provider)) {
            throw BusinessException::badRequest("未知的 WMS 服务商：{$provider}");
        }

        if (! WmsProvider::isAvailable($provider)) {
            throw BusinessException::badRequest(WmsProvider::label($provider).' 适配器将在 P8 提供，当前请使用菜鸟');
        }

        if ($this->shouldUseMock($config)) {
            $this->warnMockFallback($config);

            return new MockAdapter($config);
        }

        return new CainiaoAdapter($config, $this->gateway, new CainiaoNormalizer($config));
    }

    /**
     * 是否走 Mock 兜底：**凭证不齐**即 Mock。
     *
     * 与 P0 的语义差异——P0 是「非生产环境一律 Mock」，P2 起改为「凭证齐备才走真实网关」，
     * 这样沙箱联调（填真凭证）与生产走同一条代码路径，避免"沙箱通了、生产才发现没实现"。
     */
    public function shouldUseMock(WmsConfig $config): bool
    {
        return ! $this->credentialsComplete($config);
    }

    /** AppKey 与 AppSecret 是否都配了（AppKey 是明文列，AppSecret 是加密列） */
    public function credentialsComplete(WmsConfig $config): bool
    {
        return trim((string) $config->app_key) !== '' && $config->hasAppSecret();
    }

    /**
     * 走 Mock 时记一条 warning。
     *
     * 两类情形措辞不同：
     * - **半配置**（只填了其中一个）：几乎一定是操作失误，直接点明；
     * - **未配置**：属正常「还没接入」状态，但仍要留痕——否则事后翻日志会误以为
     *   「连通过菜鸟」，排查方向从一开始就错了。
     */
    private function warnMockFallback(WmsConfig $config): void
    {
        $hasKey = trim((string) $config->app_key) !== '';
        $hasSecret = $config->hasAppSecret();

        $reason = $hasKey !== $hasSecret
            ? '凭证只配置了一半（AppKey 与 AppSecret 必须同时提供）'
            : '尚未配置菜鸟凭证，本次调用由 Mock 应答';

        Log::warning('[WMS] 使用 Mock 适配器', [
            'warehouse_id' => $config->warehouse_id,
            'api_env' => $config->api_env,
            'reason' => $reason,
        ]);
    }
}
