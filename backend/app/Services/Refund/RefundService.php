<?php

namespace App\Services\Refund;

use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Refund;
use App\Services\Common\NoGeneratorService;
use App\Services\Common\OperationLogService;
use App\Services\Order\OrderService;
use Illuminate\Support\Facades\DB;

/**
 * 退款服务（Roadmap P5）
 *
 * 流程（API 文档 6.5 / 8.4）：
 * - 用户申请：paid / pending_ship / shipped / completed → refunding（状态机），生成退款单 pending
 * - 后台审核：approve → 沙箱退款成功 → success → 订单 refunded；reject → rejected → 订单回 paid
 * - 幂等/互斥：同一订单同时只允许一笔未完结退款
 */
class RefundService
{
    public function __construct(
        private OrderService $orders,
        private NoGeneratorService $noGenerator,
        private OperationLogService $operationLog,
    ) {
    }

    /** 用户申请退款 */
    public function apply(Order $order, int $userId, ?string $reason, ?string $amount = null): Refund
    {
        if ($order->user_id !== $userId) {
            throw BusinessException::notFound('订单不存在');
        }

        $amount = $amount !== null && $amount !== ''
            ? number_format((float) $amount, 2, '.', '')
            : (string) $order->pay_amount;

        if ((float) $amount <= 0) {
            throw BusinessException::badRequest('退款金额必须大于 0');
        }
        if ((float) $amount > (float) $order->pay_amount) {
            throw BusinessException::badRequest('退款金额不能超过实付金额');
        }

        // 已存在未完结退款则拒绝（防重复申请）
        $active = Refund::where('order_id', $order->id)
            ->whereIn('status', [Refund::STATUS_PENDING, Refund::STATUS_APPROVED])
            ->exists();
        if ($active) {
            throw BusinessException::conflict('该订单已有退款处理中，请勿重复申请');
        }

        try {
            $refund = DB::transaction(function () use ($order, $userId, $reason, $amount) {
                // 状态机：paid/pending_ship/shipped/completed → refunding
                $this->orders->transitionTo($order, Order::STATUS_REFUNDING, $reason, 'order');

                return Refund::create([
                    'refund_no' => $this->noGenerator->generateRefundNo(),
                    'order_id' => $order->id,
                    'order_no' => $order->order_no,
                    'user_id' => $userId,
                    'amount' => $amount,
                    'reason' => $reason,
                    'status' => Refund::STATUS_PENDING,
                ]);
            });
        } catch (BusinessException $e) {
            // 状态机拒绝（如待支付/已取消订单）时给出更友好的提示
            throw BusinessException::conflict('订单当前状态不支持申请退款');
        }

        $this->operationLog->record($userId, 'refund', 'apply', 'refund', $refund->id, [
            'order_no' => $order->order_no,
            'refund_no' => $refund->refund_no,
            'amount' => $amount,
            'reason' => $reason,
        ]);

        return $refund;
    }

    /**
     * 后台审核（API 文档 8.4）：action = approve / reject
     */
    public function process(Refund $refund, int $adminId, string $action, ?string $adminRemark = null): Refund
    {
        if (! in_array($action, ['approve', 'reject'], true)) {
            throw BusinessException::badRequest('非法的审核操作');
        }
        if ($refund->status !== Refund::STATUS_PENDING) {
            throw BusinessException::conflict('退款单已处理，请勿重复操作');
        }

        $refund = DB::transaction(function () use ($refund, $adminId, $action, $adminRemark) {
            $order = Order::whereKey($refund->order_id)->lockForUpdate()->first();

            if ($action === 'approve') {
                // 沙箱：直接标记渠道退款成功；生产环境此处对接渠道退款 API
                $refund->status = Refund::STATUS_SUCCESS;
                $this->orders->transitionTo($order, Order::STATUS_REFUNDED, '退款成功', 'order');
            } else {
                $refund->status = Refund::STATUS_REJECTED;
                // 拒绝后订单回到已支付（状态机 refunding → paid）
                $this->orders->transitionTo($order, Order::STATUS_PAID, '退款被拒绝', 'order');
            }

            $refund->admin_remark = $adminRemark;
            $refund->processed_by = $adminId;
            $refund->processed_at = now();
            $refund->save();

            return $refund;
        });

        $this->operationLog->record($adminId, 'refund', 'process_'.$action, 'refund', $refund->id, [
            'refund_no' => $refund->refund_no,
            'order_no' => $refund->order_no,
            'amount' => (string) $refund->amount,
            'admin_remark' => $adminRemark,
        ]);

        // V1.1 F02 / T-018：退款结果通知买家（失败不影响审核结果）
        event(new \App\Events\RefundResult($refund));

        return $refund;
    }
}
