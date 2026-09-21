<?php

namespace App\Services\Wms\Adapters\Cainiao;

use App\Models\WmsConfig;

/**
 * 平台值 → 奇门值 的归一（WMS 计划 P2 / Step 2）
 *
 * 只做**值的翻译**，不组织报文结构（结构由 {@see \App\Services\Wms\Adapters\CainiaoAdapter} 负责）。
 * 拆开的好处：字段值口径变化（如某地市改名、某仓库编码调整）时改一处即可。
 *
 * ⚠️ **承运商编码的翻译不在本类**。历史上这里有个 `logisticsCode()` 把平台 code 转成
 * 快递100 编码（`SF` → `shunfeng`）拟发奇门——方向是错的（菜鸟不认快递100 编码），
 * 且全仓无调用点，已删除。涉及多方编码互转统一走 {@see \App\Support\CarrierCode}，
 * 它同时负责正查（平台→渠道）与反查（渠道→平台，用于 WMS 回传入站归一）。
 *
 * 数据来源约定：
 * - 收货人取自 `orders.address_snapshot`（下单时快照，之后用户改地址不影响已下单的发货单）
 *   → 经 `FulfillmentOrder.buyer_info` 透传过来；
 * - 货主/仓库编码取自 `wms_configs`（`customer_id` / `warehouse_code`）。
 */
class CainiaoNormalizer
{
    public function __construct(private readonly WmsConfig $config) {}

    /** 出库单类型：一般交易出库（设计文档 §7.1） */
    public function orderType(): string
    {
        return (string) config('wms.providers.cainiao.order_type', 'JYCK');
    }

    /**
     * 来源平台编码：配置留空则回落 `default_source_platform_code`（默认 OTHER）。
     *
     * 设计文档 §7.1「建议填 OTHER 或自有平台编码」——用自有编码便于仓方按来源分流。
     */
    public function sourcePlatformCode(): string
    {
        $configured = (string) (config('wms.providers.cainiao.source_platform_code') ?? '');

        return $configured !== ''
            ? $configured
            : (string) config('wms.providers.cainiao.default_source_platform_code', 'OTHER');
    }

    /** 货主编码（设计文档 §7.1：配置 `customer_id` → `ownerCode`） */
    public function ownerCode(): string
    {
        return (string) ($this->config->customer_id ?? '');
    }

    /** 仓库编码（设计文档 §7.1：配置 `warehouse_code` → `warehouseCode`） */
    public function warehouseCode(): string
    {
        return (string) ($this->config->warehouse_code ?? '');
    }

    /** 行号：奇门从 1 开始的连续字符串（设计文档 §7.1 `orderLines.orderLineNo`） */
    public function orderLineNo(int $zeroBasedIndex): string
    {
        return (string) ($zeroBasedIndex + 1);
    }

    /**
     * 收货人信息（设计文档 §7.1 `receiverInfo`）。
     *
     * 字段对应关系刻意保持**不平滑、不兜底**：省市区缺就留空，由对方按业务规则报错，
     * 而不是把完整地址硬塞进 detailAddress 造成「看起来成功、实际发错」。
     *
     * @param  array<string, mixed>  $buyerInfo  `orders.address_snapshot` 结构
     * @return array<string, string>
     */
    public function receiverInfo(array $buyerInfo): array
    {
        $detail = trim((string) ($buyerInfo['detail_address'] ?? ''));

        // 快照缺结构化详细地址时用完整地址兜底（至少不会丢门牌）
        if ($detail === '') {
            $detail = trim((string) ($buyerInfo['full_address'] ?? ''));
        }

        return array_filter([
            'name' => trim((string) ($buyerInfo['contact_name'] ?? '')),
            'mobile' => trim((string) ($buyerInfo['contact_phone'] ?? '')),
            'province' => trim((string) ($buyerInfo['province'] ?? '')),
            'city' => trim((string) ($buyerInfo['city'] ?? '')),
            // 平台叫 district，奇门叫 area
            'area' => trim((string) ($buyerInfo['district'] ?? '')),
            'detailAddress' => $detail,
            'zipCode' => trim((string) ($buyerInfo['postcode'] ?? '')),
        ], static fn (string $v) => $v !== '');
    }
}
