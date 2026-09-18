<?php

namespace App\Services\Wms;

use App\Exceptions\BusinessException;
use App\Models\ProductSku;
use App\Models\Warehouse;
use App\Models\WmsApiLog;
use App\Models\WmsConfig;
use App\Models\WmsSkuMapping;
use App\Models\SysOperationLog;
use App\Services\Common\OperationLogService;
use App\Services\Wms\Dto\InventoryQueryDto;
use App\Services\Wms\Dto\WmsResult;
use App\Support\WmsMappingMode;
use App\Support\WmsProvider;
use Illuminate\Support\Str;

/**
 * WMS 配置服务（WMS 计划 P0 / Step 4）
 *
 * 职责：
 * - 配置读写（凭证「留空不覆盖」语义）+ 变更审计；
 * - 连通性测试（编排 Adapter + 统一落 `wms_api_logs`）；
 * - SKU 编码解析（same 回落 sku_code / manual 查映射，缺失拒绝推送）；
 * - 回调地址生成（供复制到菜鸟后台）。
 *
 * 报文留痕集中在本服务而非 Adapter：才能真正做到「真实实现 / Mock / 抛错」一视同仁。
 * 配置变更审计也放本服务——写入点即审计点，避免调用方漏记；日志**只记非敏感字段**
 * （凭证、callback_token 一律不入库）。
 */
class WmsConfigService
{
    public function __construct(
        private readonly WmsAdapterFactory $factory,
        private readonly OperationLogService $operationLog,
    ) {}

    /** 取仓库的 WMS 配置（无则 null） */
    public function getForWarehouse(int $warehouseId): ?WmsConfig
    {
        return WmsConfig::where('warehouse_id', $warehouseId)->first();
    }

    /**
     * 保存配置（存在则更新，不存在则创建）。
     *
     * @param  array<string, mixed>  $data  含可选的明文 `app_secret` / `access_token`
     *
     * 凭证语义：`app_secret` / `access_token` **为空字符串或未传 = 保持原值**，
     * 因此后台编辑页无需回填明文即可保存其余字段（SEC-01「只写不读」）。
     */
    public function save(int $warehouseId, array $data, int $operatorId): WmsConfig
    {
        $warehouse = Warehouse::find($warehouseId);
        if (! $warehouse) {
            throw BusinessException::notFound('仓库不存在');
        }

        $provider = (string) ($data['provider'] ?? WmsProvider::CAINIAO);
        if (! WmsProvider::isValid($provider)) {
            throw BusinessException::badRequest('不支持的服务商');
        }
        if (! WmsProvider::isAvailable($provider)) {
            throw BusinessException::badRequest(WmsProvider::label($provider).' 对接将在 P8 提供，敬请期待');
        }

        $config = $this->getForWarehouse($warehouseId) ?? new WmsConfig([
            'warehouse_id' => $warehouseId,
            // 随机 32 位：回调路由凭它定位仓库，不暴露自增 id
            'callback_token' => Str::random(32),
        ]);

        // 普通字段：存在即覆盖
        foreach ([
            'provider', 'enabled', 'auto_push', 'auto_push_return',
            'push_retry_times', 'sku_mapping_mode',
            'app_key', 'customer_id', 'owner_no', 'warehouse_code', 'warehouse_no',
            'api_env', 'remark',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $config->{$field} = $data[$field];
            }
        }

        if (array_key_exists('extra_config', $data) && is_array($data['extra_config'])) {
            $config->extra_config = $data['extra_config'];
        }

        // 凭证：非空才覆盖（留空 = 不修改）
        if (! empty($data['app_secret'])) {
            $config->app_secret = (string) $data['app_secret'];
        }
        if (! empty($data['access_token'])) {
            $config->access_token = (string) $data['access_token'];
        }

        $isNew = ! $config->exists;
        $config->save();

        // 变更审计（只记非敏感字段：凭证与 callback_token 不入库）
        $this->operationLog->record(
            $operatorId,
            'wms',
            $isNew ? 'config_created' : 'config_saved',
            'warehouse',
            $warehouseId,
            [
                'provider' => $config->provider,
                'enabled' => (bool) $config->enabled,
                'auto_push' => (bool) $config->auto_push,
                'auto_push_return' => (bool) $config->auto_push_return,
                'push_retry_times' => (int) $config->push_retry_times,
                'sku_mapping_mode' => $config->sku_mapping_mode,
                'api_env' => $config->api_env,
                'app_secret_changed' => ! empty($data['app_secret']),
                'access_token_changed' => ! empty($data['access_token']),
            ],
            SysOperationLog::ACTOR_ADMIN,
        );

        return $config->refresh();
    }

    /**
     * 连通性测试：调 Adapter 的 queryInventory（沙箱即 Mock），返回耗时与结果，落 wms_api_logs。
     *
     * 返回结构（后台按钮直接用）：
     * `['success' => bool, 'mock' => bool|null, 'provider' => string, 'duration_ms' => int,
     *   'message' => string, 'error' => string|null, 'request_id' => string|null]`
     *
     * 工厂/凭证类错误（如生产缺密钥）也转成 `success=false` 返回，而不是 500——
     * 让配置页能就地把原因显示给操作者。
     */
    public function testConnection(WmsConfig $config): array
    {
        $apiName = 'queryInventory';
        $started = microtime(true);
        $provider = (string) $config->provider;

        try {
            $adapter = $this->factory->make($config);
            $result = $adapter->queryInventory(new InventoryQueryDto(
                (int) $config->warehouse_id,
                [],
                'connection-test',
            ));
            $duration = $this->elapsedMs($started);

            $this->writeLog($config, $apiName, $result, $duration, requestId: $result->data['request_id'] ?? null);

            $mock = $adapter->isMock();

            return [
                'success' => $result->success,
                'mock' => $mock,
                'provider' => $adapter->provider(),
                'duration_ms' => $duration,
                'message' => $result->success
                    ? ($mock ? "连通成功（Mock，{$duration} ms）" : "连通成功（{$duration} ms）")
                    : (string) $result->error,
                'error' => $result->error,
                'request_id' => $result->data['request_id'] ?? null,
            ];
        } catch (BusinessException $e) {
            $duration = $this->elapsedMs($started);
            $this->writeLog($config, $apiName, WmsResult::fail($e->getMessage(), 400), $duration);

            return [
                'success' => false,
                'mock' => null,
                'provider' => $provider,
                'duration_ms' => $duration,
                'message' => $e->getMessage(),
                'error' => $e->getMessage(),
                'request_id' => null,
            ];
        }
    }

    /**
     * 解析 SKU 在 WMS 侧的货品编码。
     *
     * - `same`：直接回落平台 `product_skus.sku_code`（零配置可用）；
     * - `manual`：必须在 `wms_sku_mappings` 命中且启用，否则抛 40009 拒绝推送——
     *   宁可不推也不能推错货（错发的成本远高于拒单）。
     */
    public function resolveSkuCode(WmsConfig $config, int $skuId): string
    {
        $sku = ProductSku::find($skuId);
        if (! $sku) {
            throw BusinessException::notFound('SKU 不存在');
        }

        if ($config->sku_mapping_mode !== WmsMappingMode::MANUAL) {
            return (string) $sku->sku_code;
        }

        $mapping = WmsSkuMapping::where('warehouse_id', $config->warehouse_id)
            ->where('sku_id', $skuId)
            ->where('status', 1)
            ->first();

        if (! $mapping || empty($mapping->wms_sku_code)) {
            throw BusinessException::conflict("该仓库未配置 WMS 货品编码（SKU: {$sku->sku_code}）");
        }

        return (string) $mapping->wms_sku_code;
    }

    /** 回调地址（只读，供复制到菜鸟后台）；token 用于回调时识别仓库 */
    public function callbackUrl(WmsConfig $config): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return $base.'/api/wms/callback/'.$config->provider.'?token='.$config->callback_token;
    }

    // ---------------- 内部 ----------------

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /** 落一条 wms_api_logs（失败不影响主流程） */
    private function writeLog(
        WmsConfig $config,
        string $apiName,
        WmsResult $result,
        int $duration,
        ?string $requestId = null,
    ): void {
        try {
            WmsApiLog::create([
                'direction' => WmsApiLog::DIRECTION_OUTBOUND,
                'provider' => (string) $config->provider,
                'api_name' => $apiName,
                'request_id' => $requestId,
                'biz_no' => 'connection-test',
                'request_body' => [
                    'warehouse_id' => $config->warehouse_id,
                    'api_env' => $config->api_env,
                    'sku_codes' => [],
                    'duration_ms' => $duration,
                ],
                'response_body' => $result->toArray(),
                'http_status' => $result->httpStatus,
                'success' => $result->success,
                'error_msg' => $result->error,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
