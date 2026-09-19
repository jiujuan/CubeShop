<?php

namespace App\Services\Wms\Callback;

use App\Models\FulfillmentOrder;
use App\Models\ReturnInboundOrder;
use App\Models\SysOperationLog;
use App\Services\Common\OperationLogService;
use App\Services\Notification\NotificationService;
use App\Services\Wms\Callback\Handlers\CallbackHandler;
use App\Services\Wms\Callback\Handlers\DeliveryOrderConfirmHandler;
use App\Services\Wms\Callback\Handlers\DeliveryOrderStatusHandler;
use App\Services\Wms\Callback\Handlers\ReturnOrderConfirmHandler;
use App\Services\Wms\FulfillmentOrderService;
use App\Services\Wms\ReturnInboundOrderService;
use Throwable;

/**
 * 回传消息分发器（WMS 计划 P3 / Step 4；P4 扩展退货入库链）
 *
 * 职责：定位单据 → 路由到处理器 → 异常/未知的观测与告警。
 * 幂等占坑在 {@see ProcessWmsCallbackJob}，本类不做幂等。
 *
 * 两条路由：
 * - `deliveryorder.*` → 发货单（{@see FulfillmentOrder}）+ 出库处理器；
 * - `returnorder.*` → 退货入库单（{@see ReturnInboundOrder}）+ 退货处理器（P4）。
 *
 * 不可恢复情形（单据不存在、未知消息类型）**按已消费处理**（不抛）：
 * 状态机已终态或消息无意义时重推只会制造风暴，统一走「审计日志 + 站内告警」转人工。
 */
class CallbackDispatcher
{
    /** @var list<CallbackHandler> */
    private array $handlers;

    /** @var list<object> 退货入库处理器（supports/handle 契约，P4） */
    private array $returnHandlers;

    public function __construct(
        private readonly OperationLogService $operationLog,
        private readonly NotificationService $notifications,
        private readonly FulfillmentOrderService $fulfillments,
        private readonly ReturnInboundOrderService $returns,
    ) {
        $this->handlers = [
            new DeliveryOrderConfirmHandler($fulfillments),
            new DeliveryOrderStatusHandler($fulfillments),
        ];

        $this->returnHandlers = [
            new ReturnOrderConfirmHandler($returns),
        ];
    }

    /** 注册自定义处理器（测试打桩 / 后续阶段扩展用，追加在默认处理器之后） */
    public function register(CallbackHandler $handler): void
    {
        $this->handlers[] = $handler;
    }

    /** 注册自定义退货处理器（P4 扩展点） */
    public function registerReturn(object $handler): void
    {
        $this->returnHandlers[] = $handler;
    }

    /**
     * 分发一条已解析的回传消息。
     *
     * @param  array<string, mixed>  $message  {@see CallbackMessageParser::parse()} 归一结构
     * @param  array{log_id?: int|null}  $ctx
     * @return array{status: string, detail: string} status: handled|ignored|unknown_order|unknown_type
     */
    public function dispatch(string $provider, array $message, array $ctx = []): array
    {
        $bizNo = (string) ($message['biz_no'] ?? '');
        $msgType = (string) ($message['msg_type'] ?? '');

        if ($bizNo === '' || $msgType === '') {
            $detail = sprintf('回传缺少 biz_no 或 msg_type（biz_no=%s, msg_type=%s）', $bizNo, $msgType);
            $this->alert($provider, '回调消息不完整', $detail, $ctx);

            return ['status' => 'ignored', 'detail' => $detail];
        }

        // P4：退货入库链路（returnorder.*）
        if (str_starts_with($msgType, 'returnorder')) {
            return $this->dispatchReturn($provider, $message, $bizNo, $msgType, $ctx);
        }

        $fo = FulfillmentOrder::query()
            ->where('outbound_no', $bizNo)
            ->orWhere('wms_outbound_no', (string) ($message['wms_no'] ?? ''))
            ->first();

        if (! $fo) {
            $detail = "回传找不到对应发货单（outbound_no={$bizNo}）";
            $this->alert($provider, 'WMS 回传单据不存在', $detail, $ctx);

            return ['status' => 'unknown_order', 'detail' => $detail];
        }

        $handler = null;
        foreach ($this->handlers as $candidate) {
            if ($candidate->supports($msgType)) {
                $handler = $candidate;
                break;
            }
        }

        if (! $handler) {
            $detail = "忽略未知消息类型：{$msgType}";
            $this->alert($provider, 'WMS 回传消息类型未知', $detail, $ctx);

            return ['status' => 'unknown_type', 'detail' => $detail];
        }

        $handler->handle($fo, $message, $ctx);

        return ['status' => 'handled', 'detail' => sprintf('%s → %s', $msgType, $fo->refresh()->status)];
    }

    /**
     * 退货入库链分发：定位入库单 → 路由到退货处理器。
     *
     * @param  array<string, mixed>  $message
     * @param  array{log_id?: int|null}  $ctx
     * @return array{status: string, detail: string}
     */
    private function dispatchReturn(string $provider, array $message, string $bizNo, string $msgType, array $ctx): array
    {
        $rio = ReturnInboundOrder::query()
            ->where('inbound_no', $bizNo)
            ->orWhere('wms_inbound_no', (string) ($message['wms_no'] ?? ''))
            ->first();

        if (! $rio) {
            $detail = "回传找不到对应退货入库单（inbound_no={$bizNo}）";
            $this->alert($provider, 'WMS 退货回传单据不存在', $detail, $ctx);

            return ['status' => 'unknown_order', 'detail' => $detail];
        }

        $handler = null;
        foreach ($this->returnHandlers as $candidate) {
            if ($candidate->supports($msgType)) {
                $handler = $candidate;
                break;
            }
        }

        if (! $handler) {
            $detail = "忽略未知退货消息类型：{$msgType}";
            $this->alert($provider, 'WMS 退货回传消息类型未知', $detail, $ctx);

            return ['status' => 'unknown_type', 'detail' => $detail];
        }

        $handler->handle($rio, $message, $ctx);

        return ['status' => 'handled', 'detail' => sprintf('%s → %s', $msgType, $rio->refresh()->status)];
    }

    /** 审计日志 + 站内告警（双通道，通知失败不影响主流程） */
    private function alert(string $provider, string $title, string $detail, array $ctx): void
    {
        try {
            // 系统回传告警：无操作人（user_id=null），按既有约定归 admin 桶（同 OrderLog::OPERATOR_SYSTEM 映射）
            $this->operationLog->record(
                null,
                'wms',
                'callback_alert',
                'wms_callback',
                (int) ($ctx['log_id'] ?? 0),
                ['provider' => $provider, 'title' => $title, 'detail' => $detail],
                SysOperationLog::ACTOR_ADMIN,
            );
        } catch (Throwable) {
            // 审计失败不阻断
        }

        try {
            $this->notifications->sendToPermission(
                'wms.order.view',
                NotificationService::TYPE_WMS_ALERT,
                "[WMS] {$title}",
                $detail,
            );
        } catch (Throwable) {
            // 告警失败不阻断
        }
    }
}
