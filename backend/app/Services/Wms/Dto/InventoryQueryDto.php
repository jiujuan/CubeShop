<?php

namespace App\Services\Wms\Dto;

/**
 * 库存查询入参（WMS 计划 P0；P5 库存同步复用）
 *
 * 按 README §3-D1 决策**不带 tenant 参数**（CubeShop 当前单商户）。
 */
final class InventoryQueryDto
{
    /**
     * @param  array<int, string>  $skuCodes  WMS 侧货品编码列表（空 = 全量/按仓库）
     */
    public function __construct(
        public readonly int $warehouseId,
        public readonly array $skuCodes = [],
        public readonly ?string $bizNo = null,
    ) {}

    public function toArray(): array
    {
        return [
            'warehouse_id' => $this->warehouseId,
            'sku_codes' => $this->skuCodes,
            'biz_no' => $this->bizNo,
        ];
    }
}
