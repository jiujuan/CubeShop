<?php

namespace App\Services\Refund;

use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\Refund;
use App\Models\SysOperationLog;
use App\Services\Common\NoGeneratorService;
use App\Services\Common\OperationLogService;
use App\Services\Inventory\InventoryService;
use App\Services\Order\OrderService;
use App\Support\PublicId;
use Illuminate\Support\Facades\DB;

/**
 * 退款服务（Roadmap P5）
 *
 * 流程（API 文档 6.5 / 8.4）：
 * - 用户申请：paid / pending_ship / shipped / completed → refunding（状态机），生成退款单 pending
 * - 后台审核：approve → 沙箱退款成功 → success → 订单 refunded；reject → rejected → 订单回 paid
 * - 幂等/互斥：同一订单同时只允许一笔未完结退款
 *
 * 退货退款（WMS 计划 P4 / D3，为接入菜鸟打基础）：
 * - 申请时 type=return_refund 并附带应退明细 return_details
 * - 审核通过**不立即退款**：停在 approved + return_status=waiting_return，订单保持 refunding
 * - 后台「确认收货」(receiveReturn) 后按实收正品回加库存、再置 success + 订单 refunded
 * - 仅退款（默认）路径行为完全不变（强回归项）
 */
class RefundService
{
    public function __construct(
        private OrderService $orders,
        private NoGeneratorService $noGenerator,
        private OperationLogService $operationLog,
        private InventoryService $inventory,
    ) {
    }

    /**
     * 用户申请退款
     *
     * @param  array<string, mixed>  $opts  扩展参数：
     *   - type: refund(默认) | return_refund
     *   - return_details: 退货应退明细 [{sku_id, product_title?, sku_specs?, quantity}]
     *   - return_tracking_no / return_express_company / warehouse_id：退货物流与仓库
     *   - images: 用户凭证图片 URL 数组（≤9）
     */
    public function apply(Order $order, int $userId, ?string $reason, ?string $amount = null, array $opts = []): Refund
    {
        if ($order->user_id !== $userId) {
            throw BusinessException::notFound('订单不存在');
        }

        $type = $opts['type'] ?? Refund::TYPE_REFUND;
        if (! in_array($type, [Refund::TYPE_REFUND, Refund::TYPE_RETURN_REFUND], true)) {
            throw BusinessException::badRequest('非法的退款类型');
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

        // 退货退款：校验应退明细合法（sku 属本单、数量不超过已购、quantity>0）
        $returnDetails = null;
        if ($type === Refund::TYPE_RETURN_REFUND) {
            $returnDetails = $this->normalizeReturnDetails($order, $opts['return_details'] ?? null);
        }

        // 用户凭证图片（商品实拍等）
        $images = $this->normalizeImages($opts['images'] ?? null);

        // 固化优惠构成与每行实付快照（来自 orders.amount_details，T-034 同一套分摊口径）
        $refundDetails = $this->buildRefundDetails($order);

        try {
            $refund = DB::transaction(function () use ($order, $userId, $reason, $amount, $type, $returnDetails, $images, $opts, $refundDetails) {
                // 状态机：paid/pending_ship/shipped/completed → refunding（买家发起，操作人归属买家）
                $this->orders->transitionTo($order, Order::STATUS_REFUNDING, $reason, 'order', $userId, OrderLog::OPERATOR_USER);

                return Refund::create([
                    'refund_no' => $this->noGenerator->generateRefundNo(),
                    'order_id' => $order->id,
                    'order_no' => $order->order_no,
                    'user_id' => $userId,
                    'type' => $type,
                    'warehouse_id' => $opts['warehouse_id'] ?? null,
                    'amount' => $amount,
                    'reason' => $reason,
                    'images' => $images ?: null,
                    'status' => Refund::STATUS_PENDING,
                    'refund_details' => $refundDetails,
                    'return_tracking_no' => $opts['return_tracking_no'] ?? null,
                    'return_express_company' => $opts['return_express_company'] ?? null,
                    'return_details' => $returnDetails,
                ]);
            });
        } catch (BusinessException $e) {
            // 状态机拒绝（如待支付/已取消订单）时给出更友好的提示
            throw BusinessException::conflict('订单当前状态不支持申请退款');
        }

        $this->operationLog->record($userId, 'refund', 'apply', 'refund', $refund->id, [
            'order_no' => $order->order_no,
            'refund_no' => $refund->refund_no,
            'type' => $type,
            'amount' => $amount,
            'reason' => $reason,
            'images' => $images,
            'max_refundable' => $maxRefundable,
        ], SysOperationLog::ACTOR_CUSTOMER);

        return $refund;
    }

    /**
     * 校验并归一化退货应退明细
     *
     * 规则：非空数组；每行含 sku_id(int) 与 quantity(int>0)；sku 必须属于本订单；
     * 该行 quantity 不得超过该 sku 在本订单的实际购买数量。
     *
     * @param  array<int, array<string, mixed>>|null  $raw
     * @return array<int, array<string, mixed>>
     */
    private function normalizeReturnDetails(Order $order, ?array $raw): array
    {
        if (empty($raw)) {
            throw BusinessException::badRequest('退货退款需填写退货商品明细');
        }

        // 本单实际购买：sku_id => 购买数量
        $purchased = [];
        foreach ($order->items as $item) {
            $purchased[$item->sku_id] = (int) $item->quantity;
        }

        $details = [];
        foreach ($raw as $row) {
            // 兼容前端传 public_id（ULID）或历史 int 主键
            $skuId = PublicId::resolve(PublicId::SCOPE_SKU, $row['sku_id'] ?? null);
            $qty = (int) ($row['quantity'] ?? 0);

            if (! $skuId || $qty <= 0) {
                throw BusinessException::badRequest('退货明细非法：sku 或数量无效');
            }
            if (! isset($purchased[$skuId])) {
                throw BusinessException::badRequest('退货商品不属于该订单');
            }
            if ($qty > $purchased[$skuId]) {
                throw BusinessException::badRequest('退货数量超过该商品实际购买数量');
            }

            $details[] = [
                'sku_id' => $skuId,
                'product_title' => $row['product_title'] ?? null,
                'sku_specs' => $row['sku_specs'] ?? null,
                'quantity' => $qty,
            ];
        }

        return $details;
    }

    /**
     * 规范化图片 URL 数组：去空、丢弃非字符串、单条限长、总数封顶
     *
     * @param  array<int, mixed>|null  $raw
     * @return array<int, string>
     */
    private function normalizeImages(?array $raw, int $max = 9): array
    {
        if (empty($raw)) {
            return [];
        }

        $urls = [];
        foreach ($raw as $url) {
            if (! is_string($url)) {
                continue;
            }
            $url = trim($url);
            if ($url === '') {
                continue;
            }
            $urls[] = mb_substr($url, 0, 500);
            if (count($urls) >= $max) {
                break;
            }
        }

        return $urls;
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
     *
     * @param  string|null  $adminRemark  同意/拒绝理由（写入 refunds.admin_remark）
     * @param  array<int, mixed>  $adminImages  后台处理说明图片（写入 refunds.admin_images）
     */
    public function process(Refund $refund, int $adminId, string $action, ?string $adminRemark = null, array $adminImages = []): Refund
    {
        if (! in_array($action, ['approve', 'reject'], true)) {
            throw BusinessException::badRequest('非法的审核操作');
        }
        if ($refund->status !== Refund::STATUS_PENDING) {
            throw BusinessException::conflict('退款单已处理，请勿重复操作');
        }

        $adminImages = $this->normalizeImages($adminImages);

        $refund = DB::transaction(function () use ($refund, $adminId, $action, $adminRemark, $adminImages) {
            $order = Order::whereKey($refund->order_id)->lockForUpdate()->first();

            if ($action === 'approve') {
                // 退货退款：审核通过**不立即放款**，停在 approved 等待收货（先收货后退款）
                if ($refund->isReturnRefund()) {
                    $refund->status = Refund::STATUS_APPROVED;
                    $refund->return_status = Refund::RETURN_STATUS_WAITING_RETURN;
                    // 订单保持 refunding，不流转；后续由 receiveReturn() 推进
                } else {
                    // 仅退款：沙箱直接标记渠道退款成功（生产环境对接渠道退款 API）
                    $refund->status = Refund::STATUS_SUCCESS;
                    $this->orders->transitionTo($order, Order::STATUS_REFUNDED, '退款成功', 'order', $adminId, OrderLog::OPERATOR_ADMIN);

                    // T-036：整单全额退款 → 原样返还券（一券一单，releaseCoupon 幂等且仅返还本单占用券）；
                    // 部分退款默认不返还已使用券（防止「退了钱又白拿券」的资损）。
                    if (abs((float) $refund->amount - (float) $order->pay_amount) < 0.005) {
                        $this->orders->releaseCoupon($order);
                        $this->operationLog->record(
                            $adminId, 'refund', 'coupon_returned', 'order', $order->id,
                            ['order_no' => $order->order_no, 'coupon_id' => $order->coupon_id, 'reason' => '整单退款返还'],
                        );
                    }
                }
            } else {
                $refund->status = Refund::STATUS_REJECTED;
                // 拒绝后订单回到已支付（状态机 refunding → paid）
                $this->orders->transitionTo($order, Order::STATUS_PAID, '退款被拒绝', 'order', $adminId, OrderLog::OPERATOR_ADMIN);
            }

            $refund->admin_remark = $adminRemark;
            $refund->admin_images = $adminImages ?: null;
            $refund->processed_by = $adminId;
            $refund->processed_at = now();
            $refund->save();

            return $refund;
        });

        $this->operationLog->record($adminId, 'refund', 'process_'.$action, 'refund', $refund->id, [
            'refund_no' => $refund->refund_no,
            'order_no' => $refund->order_no,
            'type' => $refund->type,
            'amount' => (string) $refund->amount,
            'admin_remark' => $adminRemark,
            'admin_images' => $adminImages,
        ]);

        // V1.1 F02 / T-018：退款结果通知买家（失败不影响审核结果）
        event(new \App\Events\RefundResult($refund));

        // WMS 计划 P4 / Step 3：退货退款审核通过 → 触发退货入库单创建（监听器内部
        // 捕获异常，绝不阻断审核结果；仅退款类型不发本事件，老路径零变化）
        if ($action === 'approve' && $refund->isReturnRefund()) {
            event(new \App\Events\RefundApproved($refund));
        }

        return $refund;
    }

    /**
     * 后台确认收货（退货退款专用）：按实收正品回加库存 → 退款完成 → 订单 refunded
     *
     * 等效于 WMS 回传收货（returnorder.confirm）；WMS 接入时由回调 Handler 调用同一方法。
     * 仅退款类型不可调用。幂等：已 success 直接返回，不重复回库存。
     *
     * @param  array<int, array<string, mixed>>  $receivedDetails  实收明细 [{sku_id, quantity, condition:good|defective}]
     * @param  string|null  $exceptionReason  实收差异/异常说明（少件、残次等）
     */
    public function receiveReturn(Refund $refund, int $adminId, array $receivedDetails, ?string $exceptionReason = null): Refund
    {
        if (! $refund->isReturnRefund()) {
            throw BusinessException::badRequest('仅退货退款类型需要确认收货');
        }
        // 幂等：已收货完成直接返回，不重复回库存（WMS 回传可能重放）
        if ($refund->status === Refund::STATUS_SUCCESS) {
            return $refund;
        }
        if ($refund->status !== Refund::STATUS_APPROVED) {
            throw BusinessException::conflict('退款单当前状态不支持确认收货');
        }
        if (empty($receivedDetails)) {
            throw BusinessException::badRequest('请填写实收明细');
        }

        // 应退明细：sku_id => 应退数量（用于差异比对）
        $expected = [];
        foreach (($refund->return_details ?? []) as $row) {
            $expected[(int) $row['sku_id']] = (int) $row['quantity'];
        }

        $normalized = [];
        $receivedGoodTotal = 0;
        $expectedTotal = array_sum($expected);
        foreach ($receivedDetails as $row) {
            $skuId = PublicId::resolve(PublicId::SCOPE_SKU, $row['sku_id'] ?? null);
            $qty = (int) ($row['quantity'] ?? 0);
            $condition = $row['condition'] ?? Refund::RETURN_CONDITION_GOOD;

            if (! $skuId || $qty < 0) {
                throw BusinessException::badRequest('实收明细非法：sku 或数量无效');
            }
            if (! isset($expected[$skuId])) {
                throw BusinessException::badRequest('实收商品不属于该退货单');
            }
            if (! in_array($condition, [Refund::RETURN_CONDITION_GOOD, Refund::RETURN_CONDITION_DEFECTIVE], true)) {
                throw BusinessException::badRequest('非法的实收商品状态');
            }

            $normalized[] = ['sku_id' => $skuId, 'quantity' => $qty, 'condition' => $condition];
            if ($condition === Refund::RETURN_CONDITION_GOOD) {
                $receivedGoodTotal += $qty;
            }
        }

        // 实收差异（应退 vs 实收）：任意应退行未被足额收货，或实收 > 应退，均记异常
        $receivedBySku = [];
        foreach ($normalized as $row) {
            $receivedBySku[$row['sku_id']] = ($receivedBySku[$row['sku_id']] ?? 0) + $row['quantity'];
        }
        $receivedTotal = array_sum($receivedBySku);
        $diffReason = $exceptionReason;
        if ($receivedTotal !== $expectedTotal) {
            $diffReason = trim(($diffReason ? $diffReason.'；' : '')."实收 {$receivedTotal} 件 ≠ 应退 {$expectedTotal} 件");
        }

        try {
            $refund = DB::transaction(function () use ($refund, $adminId, $normalized, $diffReason, $expectedTotal) {
                $order = Order::whereKey($refund->order_id)->lockForUpdate()->first();

                // 逐行按实收状态处理库存：正品回加可售，残次不计可售（记差异待人工）
                foreach ($normalized as $row) {
                    if ($row['condition'] === Refund::RETURN_CONDITION_GOOD && $row['quantity'] > 0) {
                        $this->inventory->adjust(
                            $row['sku_id'],
                            $row['quantity'],
                            $adminId,
                            '退货入库 '.$refund->refund_no,
                        );
                    }
                }

                // 退款完成 + 订单 refunded（保持与仅退款一致的收尾）
                $refund->status = Refund::STATUS_SUCCESS;
                $refund->return_status = Refund::RETURN_STATUS_RECEIVED;
                $refund->return_received_details = $normalized;
                $refund->return_received_at = now();
                $refund->return_exception_reason = $diffReason ?: null;
                // 差异单不阻断退款完成，仅记录（金额以审核金额为准）
                $refund->save();

                $this->orders->transitionTo($order, Order::STATUS_REFUNDED, '退货收货完成退款', 'order', $adminId, OrderLog::OPERATOR_ADMIN);

                // 整单退货退款且金额等于实付：返还券（与仅退款一致）
                if (abs((float) $refund->amount - (float) $order->pay_amount) < 0.005) {
                    $this->orders->releaseCoupon($order);
                }

                return $refund;
            });
        } catch (BusinessException $e) {
            throw $e;
        }

        $this->operationLog->record($adminId, 'refund', 'return_received', 'refund', $refund->id, [
            'refund_no' => $refund->refund_no,
            'received_details' => $normalized,
            'exception_reason' => $diffReason,
        ]);

        // 通知买家退款完成
        event(new \App\Events\RefundResult($refund));

        return $refund;
    }
}
