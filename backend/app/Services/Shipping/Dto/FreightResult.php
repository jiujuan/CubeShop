<?php

namespace App\Services\Shipping\Dto;

/**
 * 运费计算结果（纯值对象，FreightCalculator 唯一出口）
 *
 * not_support=true 时 freight_amount 无效（'0.00'），调用方必须拒单；
 * free_shipping_gap：未达包邮门槛时 = 门槛 − 商品总额（前端「再买 ¥X 包邮」），无门槛或不包邮场景为 null。
 */
final class FreightResult
{
    /**
     * @param  array<int, array{template_id: int|null, mode: string, weight_g: int, amount: string, source: string}>  $detail
     */
    public function __construct(
        public readonly string $freightAmount,
        public readonly bool $freeShipping,
        public readonly ?string $freeShippingGap,
        public readonly bool $notSupport,
        public readonly array $detail,
    ) {}

    public function toArray(): array
    {
        return [
            'freight_amount' => $this->freightAmount,
            'free_shipping' => $this->freeShipping,
            'free_shipping_gap' => $this->freeShippingGap,
            'not_support' => $this->notSupport,
            'detail' => $this->detail,
        ];
    }
}
