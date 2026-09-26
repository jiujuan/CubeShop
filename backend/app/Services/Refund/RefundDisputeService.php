<?php

namespace App\Services\Refund;

use App\Exceptions\BusinessException;
use App\Models\OrderLog;
use App\Models\Refund;
use App\Models\RefundDispute;
use App\Models\RefundDisputeMessage;
use App\Services\Refund\RefundLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * 退款纠纷/申诉编排服务
 *
 * 纠纷与退款主表解耦，但裁决时如需改变退款结论，一律经 RefundService 驱动退款终态，
 * 绝不在本服务内直接改 refunds 表（网关纯净 / 状态机唯一入口）。
 */
class RefundDisputeService
{
    public function __construct(private RefundService $refunds)
    {
    }

    /**
     * 买家发起纠纷。
     *
     * @param  array<int, string>  $evidence
     */
    public function open(Refund $refund, int $userId, string $reasonCode, ?string $description, array $evidence): RefundDispute
    {
        if (! in_array($refund->status, [
            Refund::STATUS_PENDING,
            Refund::STATUS_APPROVED,
            Refund::STATUS_REJECTED,
            Refund::STATUS_FAILED,
        ], true)) {
            throw BusinessException::badRequest('当前退款状态不可发起纠纷');
        }

        if ($refund->user_id !== $userId) {
            throw BusinessException::forbidden('只能对自己的退款发起纠纷');
        }

        $open = RefundDispute::where('refund_id', $refund->id)
            ->whereIn('status', [RefundDispute::STATUS_OPENED, RefundDispute::STATUS_PLATFORM_INVOLVED])
            ->exists();
        if ($open) {
            throw BusinessException::conflict('该退款已有进行中的纠纷');
        }

        return DB::transaction(function () use ($refund, $userId, $reasonCode, $description, $evidence) {
            $dispute = RefundDispute::create([
                'refund_id' => $refund->id,
                'order_id' => $refund->order_id,
                'user_id' => $userId,
                'reason_code' => $reasonCode,
                'description' => $description,
                'evidence' => $evidence,
                'status' => RefundDispute::STATUS_OPENED,
            ]);

            RefundLogger::record($refund, RefundLogger::TYPE_DISPUTE_OPENED, [
                'actor_type' => 'customer',
                'actor_id' => $userId,
                'note' => $reasonCode,
            ]);

            return $dispute;
        });
    }

    /**
     * 后台指派处理人（已指派即视为介入）。
     */
    public function assign(RefundDispute $dispute, int $adminId, int $assigneeId): void
    {
        if ($dispute->status !== RefundDispute::STATUS_OPENED) {
            throw BusinessException::badRequest('仅待介入纠纷可指派');
        }

        $dispute->assigned_admin_id = $assigneeId;
        $dispute->status = RefundDispute::STATUS_PLATFORM_INVOLVED;
        $dispute->save();

        RefundLogger::record($dispute->refund, RefundLogger::TYPE_DISPUTE_ASSIGNED, [
            'actor_type' => OrderLog::OPERATOR_ADMIN,
            'actor_id' => $adminId,
            'note' => (string) $assigneeId,
        ]);
    }

    /**
     * 后台裁决。resolution 决定支持哪一方；resolution=resolved_refund 时按 refund_action 驱动退款。
     */
    public function resolve(RefundDispute $dispute, int $adminId, string $resolution, ?string $refundAction, ?string $note): void
    {
        if (! in_array($dispute->status, [RefundDispute::STATUS_OPENED, RefundDispute::STATUS_PLATFORM_INVOLVED], true)) {
            throw BusinessException::badRequest('该纠纷已裁决或关闭');
        }
        if (! in_array($resolution, [RefundDispute::RESOLUTION_REFUND, RefundDispute::RESOLUTION_REJECT], true)) {
            throw BusinessException::badRequest('非法的裁决结论');
        }

        DB::transaction(function () use ($dispute, $adminId, $resolution, $refundAction, $note) {
            $refund = $dispute->refund;

            if ($resolution === RefundDispute::RESOLUTION_REFUND) {
                $this->applyRefundAction($refund, $adminId, $refundAction);
            }

            $dispute->status = $resolution === RefundDispute::RESOLUTION_REFUND
                ? RefundDispute::STATUS_RESOLVED_REFUND
                : RefundDispute::STATUS_RESOLVED_REJECT;
            $dispute->resolution = $resolution;
            $dispute->resolution_note = $note;
            $dispute->refund_action = $refundAction;
            $dispute->resolved_by = $adminId;
            $dispute->resolved_at = now();
            $dispute->save();

            RefundLogger::record($refund, RefundLogger::TYPE_DISPUTE_RESOLVED, [
                'actor_type' => OrderLog::OPERATOR_ADMIN,
                'actor_id' => $adminId,
                'note' => $resolution.($refundAction ? ':'.$refundAction : ''),
            ]);
        });
    }

    /**
     * 将裁决动作映射到 RefundService（状态机/库存/日志保持唯一入口）。
     */
    private function applyRefundAction(Refund $refund, int $adminId, ?string $refundAction): void
    {
        switch ($refundAction) {
            case RefundDispute::ACTION_RE_OPEN:
                if (! in_array($refund->status, [Refund::STATUS_REJECTED, Refund::STATUS_FAILED], true)) {
                    throw BusinessException::badRequest('仅拒绝/失败的退款可重新发起审核');
                }
                $this->refunds->reopen($refund, $adminId);
                break;

            case RefundDispute::ACTION_APPROVE:
                if ($refund->status === Refund::STATUS_REJECTED || $refund->status === Refund::STATUS_FAILED) {
                    $refund = $this->refunds->reopen($refund, $adminId);
                }
                if ($refund->status === Refund::STATUS_PENDING) {
                    $this->refunds->process($refund, $adminId, 'approve');
                } else {
                    throw BusinessException::badRequest('当前退款状态无法执行同意退款');
                }
                break;

            case RefundDispute::ACTION_FORCE_RECEIVE:
                if (! $refund->isReturnRefund()
                    || ! in_array($refund->return_status, [Refund::RETURN_STATUS_WAITING_RETURN, Refund::RETURN_STATUS_SHIPPING], true)) {
                    throw BusinessException::badRequest('仅退货退款且待收货/退货中可强制收货');
                }
                $receivedDetails = collect($refund->return_details ?? [])->map(fn ($row) => [
                    'sku_id' => $row['sku_id'],
                    'quantity' => $row['quantity'],
                    'condition' => Refund::RETURN_CONDITION_GOOD,
                ])->all();
                $this->refunds->receiveReturn($refund, $adminId, $receivedDetails);
                break;

            case RefundDispute::ACTION_NONE:
            default:
                break;
        }
    }

    /**
     * 追加纠纷消息（admin/customer/system）。
     *
     * @param  array<int, string>  $attachments
     */
    public function addMessage(RefundDispute $dispute, string $senderType, ?int $senderId, string $body, array $attachments = []): RefundDisputeMessage
    {
        $msg = RefundDisputeMessage::create([
            'dispute_id' => $dispute->id,
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'body' => $body,
            'attachments' => $attachments,
        ]);

        RefundLogger::record($dispute->refund, RefundLogger::TYPE_DISPUTE_MESSAGE, [
            'actor_type' => $senderType,
            'actor_id' => $senderId,
        ]);

        return $msg;
    }

    public function listForBuyer(int $userId, array $filters = []): LengthAwarePaginator
    {
        $q = RefundDispute::with(['refund:id,refund_no,status,amount,type'])
            ->where('user_id', $userId);

        return $this->applyFilters($q, $filters)
            ->paginate(min($filters['page_size'] ?? 20, 100), ['*'], 'page', $filters['page'] ?? 1);
    }

    public function listForAdmin(array $filters = []): LengthAwarePaginator
    {
        $q = RefundDispute::with([
            'refund:id,refund_no,status,amount,type,user_id',
            'user:id,username,nickname',
            'assignee:id,username,nickname',
        ]);

        return $this->applyFilters($q, $filters)
            ->paginate(min($filters['page_size'] ?? 20, 100), ['*'], 'page', $filters['page'] ?? 1);
    }

    private function applyFilters($q, array $filters)
    {
        return $q->when($filters['status'] ?? null, fn ($query, $v) => $query->where('status', $v))
            ->when($filters['reason_code'] ?? null, fn ($query, $v) => $query->where('reason_code', $v))
            ->when($filters['keyword'] ?? null, function ($query, $v) {
                $query->where(function ($sub) use ($v) {
                    $sub->whereHas('refund', fn ($r) => $r->where('refund_no', $v)->orWhere('order_no', $v))
                        ->orWhereHas('user', fn ($u) => $u->where('username', 'like', "%{$v}%")->orWhere('nickname', 'like', "%{$v}%"));
                });
            })
            ->orderByDesc('id');
    }
}
