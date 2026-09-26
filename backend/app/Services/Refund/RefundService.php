<?php

namespace App\Services\Refund;

use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\Refund;
use App\Models\SysOperationLog;
use App\Models\Payment;
use App\Services\Common\NoGeneratorService;
use App\Services\Payment\Dto\RefundResult;
use App\Services\Payment\Dto\RefundCallbackResult;
use App\Services\Payment\Dto\RefundQueryResult;
use App\Services\Payment\PaymentChannelService;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Refund\RefundLogger;
use Illuminate\Support\Str;
use Throwable;
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
        private PaymentGatewayFactory $factory,
        private PaymentChannelService $channels,
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
     * G1/G3：一并继承券/满减规则快照、运费明细与每行运费分摊，确保退款单与订单同一套不可变证据。
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
                'freight_share' => isset($line['freight_share']) ? number_format((float) $line['freight_share'], 2, '.', '') : '0.00',
                'payable' => $payable,
            ];
        }

        return [
            'goods_amount' => $details['goods_amount'] ?? $order->total_amount,
            'freight_amount' => $details['freight_amount'] ?? $order->freight_amount,
            'coupon_discount' => $details['coupon_discount'] ?? '0.00',
            'promotion_discount' => $details['promotion_discount'] ?? '0.00',
            'discount_amount' => $details['discount_amount'] ?? '0.00',
            'pay_amount' => $details['pay_amount'] ?? $order->pay_amount,
            'coupon_snapshot' => $details['coupon_snapshot'] ?? null,
            'promotion_snapshot' => $details['promotion_snapshot'] ?? null,
            'freight_detail' => $details['freight_detail'] ?? null,
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
                    // 仅退款：审核通过即发起渠道退款（原路退回）。微信异步进入 processing，
                    // 支付宝/余额/Mock 同步成功；终态由 executeChannelRefund 驱动。
                    $refund->status = Refund::STATUS_PROCESSING; // 过渡态，executeChannelRefund 落终态
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

        // 仅退款审核通过：立即驱动渠道退款（同步渠道在此落 success，微信异步落 processing）；
        // 拒绝 / 退货退款（待收货）不在此发起
        if ($action === 'approve' && ! $refund->isReturnRefund()) {
            $refund = $this->executeChannelRefund($refund, $adminId, OrderLog::OPERATOR_ADMIN);
        }

        $this->operationLog->record($adminId, 'refund', 'process_'.$action, 'refund', $refund->id, [
            'refund_no' => $refund->refund_no,
            'order_no' => $refund->order_no,
            'type' => $refund->type,
            'amount' => (string) $refund->amount,
            'admin_remark' => $adminRemark,
            'admin_images' => $adminImages,
        ]);

        // V1.1 F02 / T-018：退款结果通知买家（失败不影响审核结果）
        event(new \App\Events\RefundResult($refund->fresh()));

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
        // 幂等：已收货完成 / 渠道退款处理中直接返回，不重复回库存或重复发起退款（WMS 回传可能重放）
        if (in_array($refund->status, [Refund::STATUS_SUCCESS, Refund::STATUS_PROCESSING], true)) {
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

                // 退款完成（回库存后由 executeChannelRefund 驱动终态：同步成功 / 微信异步 processing）
                $refund->return_status = Refund::RETURN_STATUS_RECEIVED;
                $refund->return_received_details = $normalized;
                $refund->return_received_at = now();
                $refund->return_exception_reason = $diffReason ?: null;
                // 差异单不阻断退款完成，仅记录（金额以审核金额为准）
                $refund->save();

                return $refund;
            });
        } catch (BusinessException $e) {
            throw $e;
        }

        // 收货确认后发起渠道退款（与仅退款一致：同步成功 / 微信异步 processing）
        $refund = $this->executeChannelRefund($refund, $adminId, OrderLog::OPERATOR_ADMIN);

        $this->operationLog->record($adminId, 'refund', 'return_received', 'refund', $refund->id, [
            'refund_no' => $refund->refund_no,
            'received_details' => $normalized,
            'exception_reason' => $diffReason,
        ]);

        // 通知买家退款完成
        event(new \App\Events\RefundResult($refund->fresh()));

        return $refund;
    }

    /**
     * 最大重试次数（超限转人工）
     */
    public const MAX_RETRY = 3;

    /**
     * 调用支付网关执行渠道退款（Phase 3 核心编排）。
     *
     * 取订单成功支付单 → 原路 → 复用/生成 out_refund_no → 调网关 → 落 success/processing/failed。
     * 每个节点写 refund_logs 便于全链路追溯。失败重试、异步回调、定时轮询均复用本方法。
     *
     * @param  int|null  $actorId  触发本次渠道退款的操作人（审核/重试的管理员；回调/轮询为系统 0）
     * @param  string  $actorType  OrderLog 操作方类型（OPERATOR_ADMIN / OPERATOR_SYSTEM）
     */
    public function executeChannelRefund(Refund $refund, ?int $actorId = null, string $actorType = OrderLog::OPERATOR_SYSTEM): Refund
    {
        $order = Order::whereKey($refund->order_id)->firstOrFail();

        $payment = Payment::where('order_id', $order->id)
            ->where('biz_type', Payment::BIZ_TYPE_ORDER)
            ->where('status', Payment::STATUS_SUCCESS)
            ->firstOrFail();

        $channel = $payment->channel;
        $config = $this->channels->decryptedConfig($channel);

        // 幂等单号：首次生成并持久化，重试复用（微信同单必须用相同 out_refund_no）
        if (empty($refund->out_refund_no)) {
            $refund->out_refund_no = 'R'.strtoupper((string) Str::ulid());
            $refund->channel = $channel;
            $refund->payment_no = $payment->payment_no;
            $refund->save();
        }

        RefundLogger::record($refund, RefundLogger::TYPE_CHANNEL_REQUEST, [
            'channel' => $channel,
            'out_refund_no' => $refund->out_refund_no,
            'request' => [
                'payment_no' => $payment->payment_no,
                'amount' => (string) $refund->amount,
                'reason' => $refund->reason,
            ],
            'actor_type' => 'system',
            'actor_id' => 0,
        ]);

        $gateway = $this->factory->make($channel);
        try {
            $result = $gateway->refund(
                $payment,
                (string) $refund->amount,
                (string) $refund->reason,
                $config,
                $refund->out_refund_no,
            );
        } catch (Throwable $e) {
            $result = RefundResult::fail($e->getMessage());
        }

        RefundLogger::record($refund, RefundLogger::TYPE_CHANNEL_RESPONSE, [
            'channel' => $channel,
            'out_refund_no' => $refund->out_refund_no,
            'response' => $result->raw ?: ['message' => $result->message],
            'channel_status' => $result->channelStatus,
            'actor_type' => 'system',
            'actor_id' => 0,
        ]);

        // 网关失败：落 failed，订单保持 refunding，等后台重试
        if (! $result->ok) {
            DB::transaction(function () use ($refund, $result) {
                $refund->status = Refund::STATUS_FAILED;
                $refund->failed_reason = $result->message;
                $refund->channel_raw = $result->raw;
                $refund->save();
            });
            RefundLogger::record($refund, RefundLogger::TYPE_FAILED, [
                'channel' => $channel,
                'out_refund_no' => $refund->out_refund_no,
                'note' => $refund->failed_reason,
                'actor_type' => 'system',
                'actor_id' => 0,
            ]);

            return $refund;
        }

        // 异步渠道（微信）进入 processing，等待回调/轮询确认终态
        // （真实 WechatGateway 成功时显式返回 PROCESSING；沙箱/Mock 成功 channelStatus 为空或 SUCCESS，不走此分支）
        if ($channel === Payment::CHANNEL_WECHAT && ($result->channelStatus ?? '') === 'PROCESSING') {
            DB::transaction(function () use ($refund, $result) {
                $refund->status = Refund::STATUS_PROCESSING;
                $refund->refund_status = $result->channelStatus ?? 'PROCESSING';
                $refund->channel_refund_no = $result->refundNo;
                $refund->channel_raw = $result->raw;
                $refund->save();
            });

            return $refund;
        }

        // 同步渠道（支付宝/余额/Mock）→ 直接成功
        $this->markRefundSuccess($refund, $result, $order, $actorId, $actorType);

        return $refund;
    }

    /**
     * 退款成功收尾（订单 refunded + 整单退券），供 process / receiveReturn / 重试 / 回调 / 轮询复用。
     *
     * 订单状态机由 OrderService 驱动（网关纯净约束），本方法不再重复判断。
     *
     * @param  int|null  $actorId  操作人（管理员或系统 0）
     * @param  string  $actorType  OrderLog 操作方类型
     */
    public function markRefundSuccess(
        Refund $refund,
        RefundResult $result,
        Order $order,
        ?int $actorId = null,
        string $actorType = OrderLog::OPERATOR_ADMIN,
    ): void {
        DB::transaction(function () use ($refund, $result) {
            $refund->status = Refund::STATUS_SUCCESS;
            $refund->refunded_at = now();
            $refund->channel_refund_no = $result->refundNo;
            $refund->channel_raw = $result->raw;
            $refund->refund_status = $result->channelStatus ?? 'SUCCESS';
            if ($refund->isReturnRefund()) {
                $refund->return_status = Refund::RETURN_STATUS_RECEIVED;
            }
            $refund->save();
        });

        // 订单 refunded（幂等：已终态则跳过，防止回调/轮询重放触发状态机冲突）
        if (Order::whereKey($order->id)->value('status') !== Order::STATUS_REFUNDED) {
            $this->orders->transitionTo($order, Order::STATUS_REFUNDED, '退款成功', 'order', $actorId, $actorType);
        }

        // 整单全额退款 → 原样返还券（一券一单，releaseCoupon 幂等且仅返还本单占用券）
        if (abs((float) $refund->amount - (float) $order->pay_amount) < 0.005) {
            $this->orders->releaseCoupon($order);
            $this->operationLog->record(
                $actorId ?? 0, 'refund', 'coupon_returned', 'order', $order->id,
                ['order_no' => $order->order_no, 'coupon_id' => $order->coupon_id, 'reason' => '整单退款返还'],
                $actorType === OrderLog::OPERATOR_SYSTEM ? SysOperationLog::ACTOR_SYSTEM : SysOperationLog::ACTOR_ADMIN,
            );
        }

        RefundLogger::record($refund, RefundLogger::TYPE_SUCCESS, [
            'channel' => $refund->channel,
            'out_refund_no' => $refund->out_refund_no,
            'channel_status' => $result->channelStatus ?? 'SUCCESS',
            'actor_type' => $actorType === OrderLog::OPERATOR_SYSTEM ? 'system' : 'admin',
            'actor_id' => $actorId,
        ]);
    }

    /**
     * 失败退款重试：复用 out_refund_no 幂等重新发起渠道退款。
     *
     * 仅 failed 态可重试；每次自增 retry_count；达 MAX_RETRY(3) 仍失败则转人工。
     *
     * @throws \App\Exceptions\BusinessException
     */
    public function retry(Refund $refund, ?int $adminId = null): Refund
    {
        if ($refund->status !== Refund::STATUS_FAILED) {
            throw BusinessException::badRequest('仅失败状态的退款可重试');
        }
        if ($refund->retry_count >= self::MAX_RETRY) {
            throw BusinessException::badRequest('已达最大重试次数（'.self::MAX_RETRY.'），请转人工处理');
        }

        DB::transaction(function () use ($refund, $adminId) {
            $refund->retry_count += 1;
            $refund->status = Refund::STATUS_PROCESSING; // 重试视为重新发起，先回 processing
            $refund->save();
        });

        RefundLogger::record($refund, RefundLogger::TYPE_RETRY, [
            'out_refund_no' => $refund->out_refund_no,
            'channel' => $refund->channel,
            'note' => '第 '.$refund->retry_count.' 次重试',
            'actor_type' => 'admin',
            'actor_id' => $adminId,
        ]);

        $refund = $this->executeChannelRefund($refund, $adminId, OrderLog::OPERATOR_ADMIN);

        // 达到上限仍失败 → 标记转人工
        if ($refund->status === Refund::STATUS_FAILED && $refund->retry_count >= self::MAX_RETRY) {
            DB::transaction(function () use ($refund) {
                $refund->failed_reason = '已达最大重试次数，转人工';
                $refund->save();
            });
        }

        return $refund;
    }

    /**
     * 微信退款异步回调接入（Phase 4）
     *
     * 由 RefundNotifyController 验签后调用：按 out_refund_no 反查退款单 → 写 channel_callback 日志
     * → 据 channelStatus 落终态。幂等：终态退款单直接跳过（微信会重放同一通知）。
     */
    /**
     * 纠纷裁决：将已拒绝/失败的退款重新置为待审核，重新进入处理流程。
     * 仅允许 rejected/failed → pending；订单回到 refunding；写 RefundLog。
     * 终态仍由后续 process/executeChannelRefund 驱动，绝不直接改终态。
     */
    public function reopen(Refund $refund, int $adminId): Refund
    {
        if (! in_array($refund->status, [Refund::STATUS_REJECTED, Refund::STATUS_FAILED], true)) {
            throw BusinessException::badRequest('仅拒绝/失败的退款可重新发起审核');
        }

        $refund = DB::transaction(function () use ($refund, $adminId) {
            $order = Order::whereKey($refund->order_id)->lockForUpdate()->first();

            $refund->status = Refund::STATUS_PENDING;
            $refund->processed_by = $adminId;
            $refund->processed_at = now();
            $refund->save();

            if ($order->status !== Order::STATUS_REFUNDING) {
                $this->orders->transitionTo(
                    $order,
                    Order::STATUS_REFUNDING,
                    '纠纷裁决：重新发起退款审核',
                    'order',
                    $adminId,
                    OrderLog::OPERATOR_ADMIN,
                );
            }

            RefundLogger::record($refund, RefundLogger::TYPE_STATUS_CHANGE, [
                'actor_type' => OrderLog::OPERATOR_ADMIN,
                'actor_id' => $adminId,
                'note' => 'dispute_reopen',
            ]);

            return $refund;
        });

        return $refund;
    }

    public function applyChannelCallback(RefundCallbackResult $result): void
    {
        if (! $result->ok || $result->outRefundNo === '') {
            return;
        }

        $refund = Refund::where('out_refund_no', $result->outRefundNo)->first();
        if (! $refund) {
            // 找不到对应退款单（可能延迟 / 重复推送）：不抛错，避免渠道反复重推
            return;
        }

        RefundLogger::record($refund, RefundLogger::TYPE_CHANNEL_CALLBACK, [
            'channel' => $refund->channel,
            'out_refund_no' => $result->outRefundNo,
            'channel_status' => $result->channelStatus,
            'event_type' => $result->eventType,
            'response' => $result->raw,
            'actor_type' => 'system',
            'actor_id' => 0,
        ]);

        // RefundCallbackResult 无 refundNo 字段：渠道退款单号（refund_id）在 raw 内
        $this->transitionByChannelStatus($refund, $result->channelStatus, $result->raw, $result->raw['refund_id'] ?? null);
    }

    /**
     * 定时轮询查单结果接入（Phase 4 兜底）
     *
     * 由 RefundSyncCommand 调用：写 query 日志 → 据 channelStatus 落库。
     * 查单接口异常（ok=false）不盲目置失败，保持 processing 等待下一轮。
     */
    public function applyQueryResult(Refund $refund, RefundQueryResult $result): void
    {
        RefundLogger::record($refund, RefundLogger::TYPE_QUERY, [
            'channel' => $refund->channel,
            'out_refund_no' => $refund->out_refund_no,
            'channel_status' => $result->channelStatus,
            'response' => $result->raw ?: ['ok' => $result->ok],
            'actor_type' => 'system',
            'actor_id' => 0,
        ]);

        if (! $result->ok) {
            return;
        }

        $this->transitionByChannelStatus($refund, $result->channelStatus, $result->raw, $result->refundNo);
    }

    /**
     * 异步结果统一落库（回调 / 轮询共用）
     *
     * SUCCESS → markRefundSuccess（订单 refunded + 退券）；
     * ABNORMAL / CLOSED → failRefund（订单保持 refunding 转人工）；
     * PROCESSING → 仅更新状态快照，保持 processing 等待下次回调 / 轮询。
     * 终态幂等：已 success / failed 直接跳过（回调 / 轮询重放保护）。
     *
     * @param  string|null  $refundNo  渠道退款单号（微信 refund_id）
     */
    private function transitionByChannelStatus(Refund $refund, string $channelStatus, array $raw, ?string $refundNo): void
    {
        if (in_array($refund->status, [Refund::STATUS_SUCCESS, Refund::STATUS_FAILED], true)) {
            return;
        }

        DB::transaction(function () use ($refund, $channelStatus, $raw, $refundNo) {
            $refund->refund_status = $channelStatus;
            $refund->channel_raw = $raw;
            if ($refundNo !== null) {
                $refund->channel_refund_no = $refundNo;
            }
            $refund->save();
        });

        if ($channelStatus === 'SUCCESS') {
            $order = Order::whereKey($refund->order_id)->firstOrFail();
            $this->markRefundSuccess(
                $refund,
                RefundResult::success($refundNo ?? $refund->channel_refund_no, $raw, $channelStatus),
                $order,
                0,
                OrderLog::OPERATOR_SYSTEM,
            );
        } elseif (in_array($channelStatus, ['ABNORMAL', 'CLOSED'], true)) {
            $this->failRefund($refund, '渠道退款异常：'.$channelStatus, $channelStatus);
        }
    }

    /**
     * 标记退款失败（异步异常 / 轮询超时兜底）
     *
     * 订单保持 refunding，由后台人工重试。终态幂等。
     *
     * @param  string|null  $channelStatus  渠道侧状态快照（ABNORMAL / CLOSED / TIMEOUT），写入 refund_status
     */
    public function failRefund(Refund $refund, string $reason, ?string $channelStatus = null): void
    {
        if (in_array($refund->status, [Refund::STATUS_SUCCESS, Refund::STATUS_FAILED], true)) {
            return;
        }

        DB::transaction(function () use ($refund, $reason, $channelStatus) {
            $refund->status = Refund::STATUS_FAILED;
            $refund->failed_reason = $reason;
            if ($channelStatus !== null) {
                $refund->refund_status = $channelStatus;
            }
            $refund->save();
        });

        RefundLogger::record($refund, RefundLogger::TYPE_FAILED, [
            'channel' => $refund->channel,
            'out_refund_no' => $refund->out_refund_no,
            'note' => $reason,
            'channel_status' => $channelStatus,
            'actor_type' => 'system',
            'actor_id' => 0,
        ]);
    }

}
