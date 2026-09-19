<?php

namespace App\Listeners;

use App\Events\RefundApproved;
use App\Models\SysOperationLog;
use App\Services\Common\OperationLogService;
use App\Services\Wms\ReturnInboundOrderService;
use Throwable;

/**
 * 退货退款审核通过 → 创建退货入库单（WMS 计划 P4 / Step 3）
 *
 * - 仅 `return_refund` 生效；未启用 WMS（仓库无配置）时抛出的 BusinessException
 *   会被捕获并落审计日志——**绝不阻断退款审核**（审核已提交，失败可由运营补建/重推）；
 * - 建单成功与否不影响退款单状态（approved + waiting_return）。
 */
class CreateReturnInboundOrder
{
    public function __construct(private readonly OperationLogService $operationLog) {}

    public function handle(RefundApproved $event): void
    {
        $refund = $event->refund;

        if (! $refund->isReturnRefund() || $refund->status !== \App\Models\Refund::STATUS_APPROVED) {
            return;
        }

        try {
            app(ReturnInboundOrderService::class)->createForRefund($refund);
        } catch (Throwable $e) {
            report($e);

            // 建单失败必须被看见：审计留痕转人工（缺 WMS 配置 / 缺仓是常见原因）
            try {
                $this->operationLog->record(
                    null,
                    'wms',
                    'return_inbound_create_failed',
                    'refund',
                    $refund->id,
                    [
                        'refund_no' => $refund->refund_no,
                        'order_no' => $refund->order_no,
                        'error' => $e->getMessage(),
                    ],
                    SysOperationLog::ACTOR_ADMIN,
                );
            } catch (Throwable) {
                // 审计失败不阻断
            }
        }
    }
}
