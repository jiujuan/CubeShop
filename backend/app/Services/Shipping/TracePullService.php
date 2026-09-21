<?php

namespace App\Services\Shipping;

use App\Models\Shipping;
use App\Models\ShippingTrace;
use App\Support\Shipping\ShippingChannelInterface;
use Illuminate\Support\Facades\Log;

/**
 * 物流轨迹拉取服务（V1.1 T-045，E03）
 *
 * - 单运单拉取：调渠道 → 轨迹去重落库（occurred_at + context 唯一）→ 按最高阶段更新 trace_status；
 * - 签收（delivered）回填 shippings.delivered_at；**不改变订单主状态**（订单完成由 orders:auto-complete 负责）；
 * - 失败处理：异常/业务失败 → pull_fail_count+1，连续达到上限置 trace_status=failed（不阻断其他运单）；
 * - 渠道不可用（未配置）→ skipped，保证降级可用。
 */
class TracePullService
{
    public const RESULT_PULLED = 'pulled';

    public const RESULT_FAILED = 'failed';

    public const RESULT_SKIPPED = 'skipped';

    public function __construct(private readonly ShippingChannelInterface $channel)
    {
    }

    /**
     * 拉取单个运单轨迹。
     *
     * @return string self::RESULT_*
     */
    public function pull(Shipping $shipping): string
    {
        if (! $this->channel->available()) {
            return self::RESULT_SKIPPED;
        }

        try {
            $result = $this->channel->query(
                $shipping->company_code,
                $shipping->tracking_no,
                $shipping->resolvePhone(),
            );
        } catch (\Throwable $e) {
            Log::warning('shipping trace pull exception', [
                'shipping_id' => $shipping->id,
                'tracking_no' => $shipping->tracking_no,
                'error' => $e->getMessage(),
            ]);

            return $this->markFailure($shipping, $e->getMessage());
        }

        if (! $result->success) {
            Log::warning('shipping trace pull failed', [
                'shipping_id' => $shipping->id,
                'tracking_no' => $shipping->tracking_no,
                'message' => $result->message,
            ]);

            return $this->markFailure($shipping, $result->message);
        }

        $this->persistTraces($shipping, $result);

        return self::RESULT_PULLED;
    }

    /** 轨迹去重落库 + 状态推导 */
    private function persistTraces(Shipping $shipping, \App\Support\Shipping\TraceResult $result): void
    {
        // 现有轨迹键集合（occurred_at|context），内存判重避免逐行 exists
        $existing = ShippingTrace::where('shipping_id', $shipping->id)
            ->get(['occurred_at', 'context'])
            ->keyBy(fn ($t) => $t->occurred_at->format('Y-m-d H:i:s').'|'.$t->context);

        $newRows = [];
        foreach ($result->traces as $trace) {
            $occurredAt = $trace['occurred_at'] instanceof \DateTimeInterface
                ? $trace['occurred_at']->format('Y-m-d H:i:s')
                : (string) $trace['occurred_at'];
            // 第三方偶发缺失时间时兜底为发货时间：绝不能用 now()，
            // 否则每次拉取时间戳都不同 → 去重键不命中 → 重复插入同一条轨迹
            if ($occurredAt === '') {
                $occurredAt = $shipping->shipped_at?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s');
            }
            $context = mb_substr((string) $trace['context'], 0, 500);
            $key = $occurredAt.'|'.$context;

            if ($existing->has($key)) {
                continue;
            }
            $existing->put($key, true);

            $newRows[] = [
                'shipping_id' => $shipping->id,
                'context' => $context,
                'occurred_at' => $occurredAt,
                'raw' => $result->raw !== null ? json_encode($result->raw, JSON_UNESCAPED_UNICODE) : null,
            ];
        }

        if ($newRows !== []) {
            ShippingTrace::insert($newRows);
        }

        // 状态推导：delivered > in_transit；签收回填 delivered_at
        $newStatus = $result->toTraceStatus();
        if ($newStatus !== null && $newStatus !== $shipping->trace_status) {
            $shipping->trace_status = $newStatus;
        }
        if ($newStatus === 'delivered' && $shipping->delivered_at === null) {
            $deliveredTraces = array_filter($result->traces, fn ($t) => ($t['stage'] ?? '') === \App\Support\Shipping\TraceStage::DELIVERED);
            $latest = end($deliveredTraces);
            $shipping->delivered_at = $latest['occurred_at'] instanceof \DateTimeInterface
                ? $latest['occurred_at']
                : now();
        }

        $shipping->pull_fail_count = 0;
        $shipping->save();
    }

    /** 失败计数；连续达到上限标记 failed（trace_status 保持可追溯） */
    private function markFailure(Shipping $shipping, string $message): string
    {
        $max = max(1, (int) config('services.shipping.max_failures', 5));

        $shipping->pull_fail_count = $shipping->pull_fail_count + 1;
        if ($shipping->pull_fail_count >= $max) {
            $shipping->trace_status = Shipping::TRACE_FAILED;
            $shipping->last_fail_message = mb_substr($message, 0, 200);
        }
        $shipping->save();

        return self::RESULT_FAILED;
    }
}
