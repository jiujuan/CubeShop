<?php

namespace App\Services\Wms\Adapters;

use App\Exceptions\BusinessException;
use App\Exceptions\Wms\WmsBizException;
use App\Exceptions\Wms\WmsGatewayException;
use App\Exceptions\Wms\WmsUnsupportedException;
use App\Models\WmsConfig;
use App\Services\Wms\Adapters\Cainiao\CainiaoErrorCode;
use App\Services\Wms\Adapters\Cainiao\CainiaoGateway;
use App\Services\Wms\Adapters\Cainiao\CainiaoNormalizer;
use App\Services\Wms\Contracts\WmsAdapter;
use App\Services\Wms\Dto\CancelOutboundDto;
use App\Services\Wms\Dto\InventoryQueryDto;
use App\Services\Wms\Dto\OutboundDto;
use App\Services\Wms\Dto\ReturnInboundDto;
use App\Services\Wms\Dto\WmsResult;
use App\Support\WmsProvider;

/**
 * 菜鸟（奇门仓配）适配器（WMS 计划 P2 / F4~F7、Step 4）
 *
 * 职责分两层，刻意不混：
 * - **平台 DTO → 奇门报文**：见 {@see self::buildOutboundBiz()} 等私有方法；
 * - **奇门回执 → 平台结果**：统一走 {@see CainiaoGateway::toResult()}，把
 *   「单据已存在」翻译成幂等成功、把网络抖动翻译成可重试失败，
 *   再把协议字段名归一成平台字段名（`deliveryOrderId` → `wms_order_no`）。
 *
 * 三条硬性不变量：
 * 1. **绝不重复建单**：`deliveryOrderCode` 用平台全局唯一的 `outbound_no`；
 *    对方回「已存在」按幂等成功处理（`WmsResult::idempotent = true`）；
 * 2. **绝不静默假成功**：配置缺失（网关地址、AppKey/AppSecret、仓库/货主编码、
 *    收件人必要信息）一律 fail-fast 抛 `BusinessException`——这类问题重试无意义，
 *    必须让运营看见并修数据；
 * 3. **退货入库不假装支持**：`createReturnInbound` / `cancelReturnInbound` 抛
 *    `WmsUnsupportedException`（P4 补齐），而不是返回一个空的成功。
 */
class CainiaoAdapter implements WmsAdapter
{
    public function __construct(
        private readonly WmsConfig $config,
        private readonly CainiaoGateway $gateway,
        private readonly CainiaoNormalizer $normalizer,
    ) {}

    public function provider(): string
    {
        return WmsProvider::CAINIAO;
    }

    public function isMock(): bool
    {
        return false;
    }

    // ---------------- 库存查询（设计文档 §7.6） ----------------

    public function queryInventory(InventoryQueryDto $dto): WmsResult
    {
        return $this->guard(function () use ($dto) {
            $biz = array_filter([
                'warehouseCode' => $this->requireCode($this->normalizer->warehouseCode(), '菜鸟仓库编码（warehouse_code）'),
                'ownerCode' => $this->requireCode($this->normalizer->ownerCode(), '菜鸟货主编码（customer_id）'),
                // 空列表 = 不按货品过滤（全仓查询）；`itemCodes` 直接给数组
                'itemCodes' => $dto->skuCodes !== [] ? array_values($dto->skuCodes) : null,
            ], static fn ($v) => $v !== null && $v !== '');

            $envelope = $this->post('query_inventory', $biz);
            $outcome = $this->gateway->toResult($envelope, $this->duplicateCodes());

            if (! $outcome->success) {
                return $outcome;
            }

            return $outcome->with($this->inventoryData($envelope));
        });
    }

    // ---------------- 创建出库单（设计文档 §7.1） ----------------

    public function createOutbound(OutboundDto $dto): WmsResult
    {
        return $this->guard(function () use ($dto) {
            $envelope = $this->post('create_outbound', $this->buildOutboundBiz($dto));
            $outcome = $this->gateway->toResult($envelope, $this->duplicateCodes());
            $payload = (array) ($envelope['payload'] ?? []);

            return $outcome->with([
                'request_id' => $envelope['request_id'] ?? null,
                'biz_no' => $dto->bizNo,
                // 归一 WMS 单号：奇门叫 deliveryOrderId，平台统一记 wms_outbound_no
                'wms_order_no' => $this->firstString($payload, ['deliveryOrderId', 'deliveryOrderCode']),
                'idempotent' => $outcome->idempotent,
                'payload' => $payload,
            ]);
        });
    }

    // ---------------- 取消出库单（设计文档 §7.3） ----------------

    public function cancelOutbound(CancelOutboundDto $dto): WmsResult
    {
        return $this->guard(function () use ($dto) {
            $biz = array_filter([
                'deliveryOrderCode' => $dto->bizNo,
                'deliveryOrderId' => $dto->wmsOutboundNo,
                'warehouseCode' => $this->requireCode($this->normalizer->warehouseCode(), '菜鸟仓库编码（warehouse_code）'),
                'ownerCode' => $this->requireCode($this->normalizer->ownerCode(), '菜鸟货主编码（customer_id）'),
            ], static fn ($v) => $v !== null && $v !== '');

            $envelope = $this->post('cancel_outbound', $biz);
            $outcome = $this->gateway->toResult($envelope, $this->duplicateCodes());
            $code = $envelope['code'] ?? null;

            // 「单据状态不允许取消」（已出库/已发货）是**业务终局**而非故障：
            // 抛 WmsBizException 让上层按「需人工介入」处理，避免队列白跑几轮。
            // 注意：正常路径下本地状态机在 packed 之后就已拒绝取消（不走到这里），
            // 这里兜的是「本地已推、仓方已出库」的时间差。
            if (! $outcome->success && ! $outcome->retryable && $this->looksLikeNotCancellable($code)) {
                throw new WmsBizException((string) $outcome->error, (string) $code, (array) ($envelope['payload'] ?? []));
            }

            return $outcome->with([
                'request_id' => $envelope['request_id'] ?? null,
                'biz_no' => $dto->bizNo,
                'status' => $outcome->success ? 'cancelled' : null,
                'payload' => $envelope['payload'] ?? [],
            ]);
        });
    }

    // ---------------- 退货入库（P4） ----------------

    public function createReturnInbound(ReturnInboundDto $dto): WmsResult
    {
        throw WmsUnsupportedException::method('createReturnInbound');
    }

    public function cancelReturnInbound(string $bizNo): WmsResult
    {
        throw WmsUnsupportedException::method('cancelReturnInbound');
    }

    // ---------------- 报文组装 ----------------

    /**
     * 组装 `deliveryorder.create` 业务报文（设计文档 §7.1）。
     *
     * `orderLines` 与 `deliveryOrder` **平级**（奇门约定的信封形状），不嵌在 `deliveryOrder` 里。
     *
     * @return array<string, mixed>
     */
    private function buildOutboundBiz(OutboundDto $dto): array
    {
        $ownerCode = $this->requireCode($this->normalizer->ownerCode(), '菜鸟货主编码（customer_id）');
        $warehouseCode = $this->requireCode($this->normalizer->warehouseCode(), '菜鸟仓库编码（warehouse_code）');

        $receiver = $this->normalizer->receiverInfo($dto->buyerInfo());
        $this->assertReceiver($receiver);

        $lines = [];
        foreach (array_values($dto->items) as $index => $item) {
            $itemCode = trim((string) ($item['wms_sku_code'] ?? ''));
            if ($itemCode === '') {
                throw BusinessException::badRequest('出库单存在缺少 WMS 货品编码的明细行，已拒绝推送');
            }

            $qty = (int) ($item['quantity'] ?? 0);
            if ($qty <= 0) {
                throw BusinessException::badRequest("出库单明细数量必须大于 0（货品：{$itemCode}）");
            }

            $lines[] = array_filter([
                'orderLineNo' => $this->normalizer->orderLineNo($index),
                'sourceOrderCode' => $dto->orderNo,
                'ownerCode' => $ownerCode,
                'itemCode' => $itemCode,
                'itemName' => isset($item['product_name']) ? trim((string) $item['product_name']) : null,
                'planQty' => $qty,
                'barCode' => isset($item['barcode']) ? trim((string) $item['barcode']) : null,
            ], static fn ($v) => $v !== null && $v !== '');
        }

        if ($lines === []) {
            throw BusinessException::badRequest('出库单没有可推送的明细行，已拒绝推送');
        }

        return [
            'deliveryOrder' => array_filter([
                'deliveryOrderCode' => $dto->bizNo,
                'orderType' => $this->normalizer->orderType(),
                'sourceOrderCode' => $dto->orderNo,
                'warehouseCode' => $warehouseCode,
                'ownerCode' => $ownerCode,
                'receiverInfo' => $receiver,
                'remark' => $dto->remark !== null ? trim($dto->remark) : null,
            ], static fn ($v) => $v !== null && $v !== '' && $v !== []),
            'orderLines' => ['orderLine' => $lines],
            'sourcePlatformCode' => $this->normalizer->sourcePlatformCode(),
        ];
    }

    // ---------------- 内部 ----------------

    /**
     * 出站调用守卫：把「网络类失败」收敛成结果返回，其余异常照常上抛。
     *
     * 收敛的理由：Adapter 契约规定「失败以结果表达」，上层（Job）只需按
     * `retryable` 决定重试，不必理解 HTTP 细节。
     * 而 `BusinessException`（我方配置/数据问题）与 `WmsBizException`（对方业务终局）
     * **刻意不放行到这里**——它们需要立刻被看见，不该被悄悄降级成一次"重试"。
     */
    private function guard(callable $fn): WmsResult
    {
        try {
            return $fn();
        } catch (WmsGatewayException $e) {
            return WmsResult::fail(
                $e->getMessage(),
                $e->httpStatus,
                ['response_body' => $e->responseBody],
                retryable: $e->retryable,
            );
        }
    }

    /**
     * 发起一次奇门调用。
     *
     * @param  string  $methodKey  `wms.providers.cainiao.methods` 里的键
     * @return array<string, mixed> 回执信封（见 {@see CainiaoGateway::post()}）
     */
    private function post(string $methodKey, array $biz): array
    {
        $method = (string) config("wms.providers.cainiao.methods.{$methodKey}", '');

        if ($method === '') {
            throw BusinessException::badRequest("未配置奇门方法名：{$methodKey}");
        }

        return $this->gateway->post($method, $biz, $this->config);
    }

    /**
     * 库存回执 → 平台结构。
     *
     * `items` 与 Mock 适配器同形（下游/P5 不必区分服务商），另给 `quantities`
     * 便于「按编码直接取值」的调用方。
     *
     * @return array<string, mixed>
     */
    private function inventoryData(array $envelope): array
    {
        $payload = (array) ($envelope['payload'] ?? []);
        $rawItems = $payload['items']['item'] ?? $payload['items'] ?? $payload['item'] ?? [];

        // 奇门「单条返回对象、多条返回数组」，两种都得认
        if (is_array($rawItems) && ! array_is_list($rawItems)) {
            $rawItems = [$rawItems];
        }

        $items = [];
        $quantities = [];
        foreach ((array) $rawItems as $row) {
            if (! is_array($row)) {
                continue;
            }

            $code = $this->firstString($row, ['itemCode', 'skuCode', 'itemId']);
            if ($code === null) {
                continue;
            }

            $available = (int) ($row['quantity'] ?? $row['availableQty'] ?? 0);
            $locked = (int) ($row['lockQuantity'] ?? 0);

            $items[] = [
                'sku_code' => $code,
                'quantity' => $available,
                'lock_quantity' => $locked,
                'warehouse_code' => $this->normalizer->warehouseCode(),
            ];
            $quantities[$code] = $available;
        }

        return [
            'request_id' => $envelope['request_id'] ?? null,
            'warehouse_code' => $this->normalizer->warehouseCode(),
            'items' => $items,
            'quantities' => $quantities,
            'mock' => false,
        ];
    }

    /**
     * 收件人必要字段缺失时 fail-fast。
     *
     * 只校验「无歧义」的三项：姓名、手机、详细地址。省/市/区**刻意不拦**——
     * 直辖市、省直管县、海外地址的拆分规则各不相同，硬拦会误伤真实订单；
     * 让仓方按它的规则报错，错误原样回到 `push_failed` 供人工处理。
     */
    private function assertReceiver(array $receiver): void
    {
        foreach (['name' => '收件人姓名', 'mobile' => '收件人手机号', 'detailAddress' => '收件人详细地址'] as $key => $label) {
            if (trim((string) ($receiver[$key] ?? '')) === '') {
                throw BusinessException::badRequest("出库单缺少{$label}，已拒绝推送（请检查订单收货地址）");
            }
        }
    }

    /** 配置类编码缺失即拒绝——推出一个没有仓库/货主的单据只会被对方丢掉 */
    private function requireCode(string $value, string $label): string
    {
        $value = trim($value);

        if ($value === '') {
            throw BusinessException::badRequest("未配置{$label}，已拒绝调用菜鸟接口");
        }

        return $value;
    }

    /** 「不可取消」类业务码：单据已推进到出库之后，取消窗口已过 */
    private function looksLikeNotCancellable(?string $code): bool
    {
        $normalized = strtoupper(trim((string) $code));
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, [
            'S08',                                  // 单据状态不允许当前操作
            'ORDER_STATUS_NOT_ALLOW',
            'ORDER_ALREADY_OUTBOUND',
            'DELIVERY_ORDER_NOT_CANCELLABLE',
        ], true);
    }

    /** @return list<string> */
    private function duplicateCodes(): array
    {
        $configured = array_values(array_filter((array) config('wms.providers.cainiao.duplicate_codes', [])));

        return $configured !== [] ? $configured : CainiaoErrorCode::DEFAULT_DUPLICATE_CODES;
    }

    /** 从数组里取第一个非空字符串（字段名跨环境不一致时的兜底） */
    private function firstString(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_scalar($row[$key])) {
                $value = trim((string) $row[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }
}
