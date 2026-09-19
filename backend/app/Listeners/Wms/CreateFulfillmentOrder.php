<?php

namespace App\Listeners\Wms;

use App\Events\OrderAcceptedForShipment;
use App\Jobs\Wms\PushOutboundJob;
use App\Models\FulfillmentOrder;
use App\Services\Wms\FulfillmentOrderService;

/**
 * 订单进入待发货 → 创建发货单并（按配置）派发推送作业（WMS 计划 P1 / F4、Step 5）
 *
 * 关键约束：
 * - **未启用 WMS 时直接返回**：既有「支付成功 → 待发货 → 商家手工发货」链路行为零变化；
 * - 本监听器在支付回调链路里**同步**执行，任何异常都必须吞掉并上报，
 *   绝不能因为履约建单失败让支付接口 500（钱已收，单必须落）；
 * - 不在事件里做任何 HTTP 调用，推送一律交给队列作业（`PushOutboundJob`）。
 */
class CreateFulfillmentOrder
{
    public function __construct(private readonly FulfillmentOrderService $fulfillments) {}

    public function handle(OrderAcceptedForShipment $event): void
    {
        $order = $event->order;

        // 没有启用的 WMS 配置 → 这条订单不进 WMS 履约链路
        $config = $this->fulfillments->resolveConfigFor($order);
        if (! $config) {
            return;
        }

        try {
            $fo = $this->fulfillments->createForOrder($order, (int) $config->warehouse_id);

            // 仅「待推送」需要派发作业：created（未开自动推送）等人工作业，
            // exception（缺 SKU 映射）等运营补齐后重推。
            if ($fo->status === FulfillmentOrder::STATUS_PENDING_PUSH) {
                PushOutboundJob::dispatch($fo->id, (int) $config->push_retry_times);
            }
        } catch (\Throwable $e) {
            // 履约失败绝不能影响支付/受理主流程。队列为 sync 时作业异常也会冒泡到这里，
            // 同样吞掉并上报——钱已收，订单流转不能被 WMS 侧问题连累。
            report($e);
        }
    }
}
