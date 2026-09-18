<?php

namespace App\Support\Shipping;

/**
 * 渠道查询结果 DTO（V1.1 T-045，E03）
 *
 * traces 行级 stage 统一枚举（渠道适配器负责把第三方文案映射过来）：
 * - pickup     揽收
 * - in_transit 运输中
 * - delivering 派送中
 * - delivered  已签收
 */
class TraceResult
{
    public const STAGE_ORDER = [
        TraceStage::PICKUP => 1,
        TraceStage::IN_TRANSIT => 2,
        TraceStage::DELIVERING => 3,
        TraceStage::DELIVERED => 4,
    ];

    /**
     * @param  list<array{context: string, occurred_at: string|\DateTimeInterface, stage: string}>  $traces
     * @param  array<string, mixed>|null  $raw  第三方原始报文
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $traces = [],
        public readonly ?array $raw = null,
        public readonly string $message = '',
    ) {}

    public static function ok(array $traces, ?array $raw = null): self
    {
        return new self(true, $traces, $raw, 'ok');
    }

    public static function fail(string $message, ?array $raw = null): self
    {
        return new self(false, [], $raw, $message);
    }

    /** 汇总行级 stage → 运单级 trace_status（取最高阶段） */
    public function toTraceStatus(): ?string
    {
        $highest = 0;
        $status = null;

        foreach ($this->traces as $trace) {
            $stage = $trace['stage'] ?? '';
            $order = self::STAGE_ORDER[$stage] ?? 0;

            if ($order > $highest) {
                $highest = $order;
                $status = match ($stage) {
                    TraceStage::PICKUP, TraceStage::IN_TRANSIT, TraceStage::DELIVERING => 'in_transit',
                    TraceStage::DELIVERED => 'delivered',
                    default => null,
                };
            }
        }

        return $status;
    }
}
