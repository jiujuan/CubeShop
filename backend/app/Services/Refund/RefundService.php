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

        // 已存在未完结退款则拒绝（防重复申请）。先于「可退上限」校验，
        // 避免「首笔全额退款处理中 + 第二笔同额申请」被误判成「超过可退余额」（40000），
        // 而应精确命中「重复申请冲突」（40009）。
        $active = Refund::where('order_id', $order->id)
            ->whereIn('status', [Refund::STATUS_PENDING, Refund::STATUS_APPROVED])
            ->exists();
        if ($active) {
            throw BusinessException::conflict('该订单已有退款处理中，请勿重复申请');
        }

        // 累计已退款（含处理中）金额：退款总额不得超过订单实付（不变量）
        $refundedSoFar = (float) Refund::where('order_id', $order->id)
            ->whereIn('status', [Refund::STATUS_PENDING, Refund::STATUS_APPROVED, Refund::STATUS_SUCCESS])
            ->sum('amount');
        $maxRefundable = bcsub((string) $order->pay_amount, number_format($refundedSoFar, 2, '.', ''), 2);

        if (bccomp($amount, $maxRefundable, 2) === 1) {
            throw BusinessException::badRequest('退款金额超过可退余额');
        }

        // 固化优惠构成与每行实付快照（来自 orders.amount_details，T-034 同一套分摊口径）
        $refundDetails = $this->buildRefundDetails($order);

        try {
            $refund = DB::transaction(function () use ($order, $userId, $reason, $amount, $refundDetails) {
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
                    'refund_details' => $refundDetails,
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
            'max_refundable' => $maxRefundable,
        ]);

        return $refund;
    }

    /**
     * 可退余额 = 订单实付 − 已退款（含处理中）累计
     *
     * 供后台审核页展示「可退上限」与申请时的上限校验共用同一口径。
     */
    public function maxRefundableAmount(Order $order): string
    {
        $refundedSoFar = (float) Refund::where('order_id', $order->id)
            ->whereIn('status', [Refund::STATUS_PENDING, Refund::STATUS_APPROVED, Refund::STATUS_SUCCESS])
            ->sum('amount');

        return bcsub((string) $order->pay_amount, number_format($refundedSoFar, 2, '.', ''), 2);
    }

    /**
     * 从 orders.amount_details 固化每行实付快照
     *
     * 每行实付 = price×qty − coupon_share − promotion_share；Σ 行实付 + 运费处理 = 订单实付
     * （不变量：T-034 assertInvariants 已保证）。退款金额不得超 Σ 行实付。
     */
    private function buildRefundDetails(Order $order): array
    {
        $details = $order->amount_details ?? [];
        $lines = [];

        foreach ($details['lines'] ?? [] as $line) {
            $payable = number_format(
                (float) ($line['amount'] ?? 0)
                - (float) ($line['coupon_share'] ?? 0)
                - (float) ($line['promotion_share'] ?? 0),
                2, '.', ''
            );
            $lines[] = [
                'index' => $line['index'] ?? count($lines),
                'amount' => isset($line['amount']) ? number_format((float) $line['amount'], 2, '.', '') : '0.00',
                'coupon_share' => isset($line['coupon_share']) ? number_format((float) $line['coupon_share'], 2, '.', '') : '0.00',
                'promotion_share' => isset($line['promotion_share']) ? number_format((float) $line['promotion_share'], 2, '.', '') : '0.00',
                'payable' => $payable,
            ];
        }

        return [
            'goods_amount' => $details['goods_amount'] ?? $order->total_amount,
            'freight_amount' => $details['freight_amount'] ?? $order->freight_amount,
            'coupon_discount' => $details['coupon_discount'] ?? '0.00',
            'promotion_discount' => $details['promotion_discount'] ?? '0.00',
            'pay_amount' => $details['pay_amount'] ?? $order->pay_amount,
            'lines' => $lines,
        ];
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

                // T-036：整单全额退款 → 原样返还券（一券一单，releaseCoupon 幂等且仅返还本单占用券）；
                // 部分退款默认不返还已使用券（防止「退了钱又白拿券」的资损）。
                if (abs((float) $refund->amount - (float) $order->pay_amount) < 0.005) {
                    $this->orders->releaseCoupon($order);
                    $this->operationLog->record(
                        $adminId, 'refund', 'coupon_returned', 'order', $order->id,
                        ['order_no' => $order->order_no, 'coupon_id' => $order->coupon_id, 'reason' => '整单退款返还'],
                    );
                }
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
