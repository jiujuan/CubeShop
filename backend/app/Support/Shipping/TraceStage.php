<?php

namespace App\Support\Shipping;

/** 轨迹阶段统一枚举（渠道文案 → 统一阶段的映射目标） */
final class TraceStage
{
    public const PICKUP = 'pickup';

    public const IN_TRANSIT = 'in_transit';

    public const DELIVERING = 'delivering';

    public const DELIVERED = 'delivered';
}
