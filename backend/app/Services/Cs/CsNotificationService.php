<?php

namespace App\Services\Cs;

use App\Models\CsTicket;
use App\Models\CsTicketMessage;
use App\Models\Notification;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Log;

/**
 * 客服工单通知（CS-107）
 *
 * 只做「工单语义 → 通知通道」的转译，通道本身复用 NotificationService：
 * - 客服侧：sendToPermission('cs.ticket.view') → receiver_type = admin
 * - 买家侧：send() → receiver_type = customer
 *
 * ⚠️ 收件人按**权限**而非角色名投递：运营（operator）不持有 cs.* 权限，
 *    若写死 sendToRole('operator') 会出现「有通知但打不开」；按权限投递后，
 *    一期由超管接收，二期新增客服角色只要授予 cs.ticket.view 即自动覆盖。
 * ⚠️ 硬性规则：**内部备注（is_internal）绝不通知用户**（AC-107.3）。
 * ⚠️ 通知失败不得阻断主流程（异常吞掉并记日志）。
 */
class CsNotificationService
{
    public function __construct(private NotificationService $notifications)
    {
    }

    /** 新工单 → 通知持有工单查看权限的管理员 */
    public function notifyNewTicket(CsTicket $ticket): int
    {
        return $this->safely(function () use ($ticket) {
            return $this->notifications->sendToPermission(
                'cs.ticket.view',
                NotificationService::TYPE_CS_TICKET_NEW,
                '新服务工单待处理',
                "{$ticket->ticket_no} {$ticket->title}",
                $this->staffLink($ticket),
            );
        });
    }

    /** 客服回复 → 通知买家（内部备注不通知） */
    public function notifyStaffReply(CsTicket $ticket, CsTicketMessage $message): bool
    {
        if ($message->is_internal) {
            return false;
        }

        return $this->safely(function () use ($ticket, $message) {
            $this->notifications->send(
                (int) $ticket->user_id,
                NotificationService::TYPE_CS_TICKET_REPLY,
                '客服回复了您的工单',
                mb_substr((string) $message->content, 0, 100),
                $this->userLink($ticket),
            );

            return true;
        });
    }

    /** 状态变更 → 通知买家 */
    public function notifyStatusChanged(CsTicket $ticket, string $to): bool
    {
        return $this->safely(function () use ($ticket, $to) {
            $label = CsTicket::STATUS_LABELS[$to] ?? $to;

            $this->notifications->send(
                (int) $ticket->user_id,
                NotificationService::TYPE_CS_TICKET_STATUS,
                '工单状态更新',
                "工单 {$ticket->ticket_no} 已更新为「{$label}」",
                $this->userLink($ticket),
            );

            return true;
        });
    }

    private function userLink(CsTicket $ticket): string
    {
        return '/service-center/tickets/'.$ticket->id;
    }

    private function staffLink(CsTicket $ticket): string
    {
        return '/cs/tickets/'.$ticket->id;
    }

    /**
     * 通知异常一律降级：记录日志，不影响建单/回复主流程
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function safely(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            Log::warning('[CS] 通知发送失败，已降级不阻断主流程', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
