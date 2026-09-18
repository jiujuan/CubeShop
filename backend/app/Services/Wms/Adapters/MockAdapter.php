<?php

namespace App\Services\Wms\Adapters;

use App\Exceptions\BusinessException;
use App\Models\WmsConfig;
use App\Services\Wms\Contracts\WmsAdapter;
use App\Services\Wms\Dto\InventoryQueryDto;
use App\Services\Wms\Dto\OutboundDto;
use App\Services\Wms\Dto\ReturnInboundDto;
use App\Services\Wms\Dto\WmsResult;
use App\Support\WmsProvider;
use Illuminate\Support\Str;

/**
 * Mock WMS 适配器（WMS 计划 P0 / F4、Step 3）
 *
 * 用途：在菜鸟沙箱账号下来之前，让「配置页 → 测试连通性 → 日志留痕」整条链路可跑通，
 * 也让 P1/P2 的业务闭环能先本地验证。
 *
 * 行为约定：
 * - 按入参回一个固定成功报文（含确定性标识，便于断言）；
 * - **不外呼**不签名，纯本地回执；
 * - 报文留痕（`wms_api_logs`）统一由 {@see \App\Services\Wms\WmsConfigService} 负责——
 *   放在编排层才能对「真实 Adapter / 抛错」一视同仁地记录，Adapter 自身保持纯粹；
 * - fail-closed：**仅当** `api_env=prod` 且真实凭证（app_key + app_secret）缺失时抛
 *   `BusinessException`——生产环境绝不允许静默降级成假成功（SEC-01 同源原则）。
 *   沙箱/未配置环境一律放行（这正是 Mock 存在的意义）。
 */
class MockAdapter implements WmsAdapter
{
    public function __construct(private readonly WmsConfig $config) {}

    public function provider(): string
    {
        return WmsProvider::MOCK;
    }

    public function isMock(): bool
    {
        return true;
    }

    public function queryInventory(InventoryQueryDto $dto): WmsResult
    {
        $this->assertCredentialsForProd();

        $items = collect($dto->skuCodes)->map(fn (string $code) => [
            'sku_code' => $code,
            // 固定值：Mock 无真实库存，给 999 表示「充足」，避免下游误判缺货
            'quantity' => 999,
            'warehouse_code' => $this->config->warehouse_code ?: 'MOCK_WH',
        ])->all();

        return WmsResult::ok([
            'request_id' => $this->requestId(),
            'warehouse_code' => $this->config->warehouse_code ?: 'MOCK_WH',
            'items' => $items,
            'mock' => true,
        ], 200, [], $this->duration());
    }

    public function createOutbound(OutboundDto $dto): WmsResult
    {
        $this->assertCredentialsForProd();

        return WmsResult::ok([
            'request_id' => $this->requestId(),
            'biz_no' => $dto->bizNo,
            'wms_order_no' => 'MOCK-OUT-'.strtoupper(Str::random(10)),
            'status' => 'accepted',
            'mock' => true,
        ], 200, [], $this->duration());
    }

    public function cancelOutbound(string $bizNo): WmsResult
    {
        $this->assertCredentialsForProd();

        return WmsResult::ok([
            'request_id' => $this->requestId(),
            'biz_no' => $bizNo,
            'status' => 'cancelled',
            'mock' => true,
        ], 200, [], $this->duration());
    }

    public function createReturnInbound(ReturnInboundDto $dto): WmsResult
    {
        $this->assertCredentialsForProd();

        return WmsResult::ok([
            'request_id' => $this->requestId(),
            'biz_no' => $dto->bizNo,
            'wms_order_no' => 'MOCK-RET-'.strtoupper(Str::random(10)),
            'status' => 'accepted',
            'mock' => true,
        ], 200, [], $this->duration());
    }

    public function cancelReturnInbound(string $bizNo): WmsResult
    {
        $this->assertCredentialsForProd();

        return WmsResult::ok([
            'request_id' => $this->requestId(),
            'biz_no' => $bizNo,
            'status' => 'cancelled',
            'mock' => true,
        ], 200, [], $this->duration());
    }

    /**
     * fail-closed：生产环境缺凭证直接拒绝，不产出假成功。
     *
     * 沙箱（sandbox）与未配置环境放行——Mock 本就为它们存在。
     */
    private function assertCredentialsForProd(): void
    {
        if ($this->config->api_env !== 'prod') {
            return;
        }

        if (empty($this->config->app_key) || ! $this->config->hasAppSecret()) {
            throw BusinessException::badRequest('生产环境缺少 WMS 凭证（AppKey/AppSecret），已拒绝降级为 Mock');
        }
    }

    private function requestId(): string
    {
        return 'MOCK-'.strtoupper(Str::random(16));
    }

    /** Mock 无真实网络耗时，返回 0（保留字段供真实 Adapter 填充） */
    private function duration(): int
    {
        return 0;
    }
}
