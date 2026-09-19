<?php

namespace App\Services\Wms;

use App\Events\RefundResult;
use App\Exceptions\BusinessException;
use App\Jobs\Wms\CancelReturnInboundJob;
use App\Jobs\Wms\PushReturnInboundJob;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\ProductSku;
use App\Models\Refund;
use App\Models\ReturnInboundOrder;
use App\Models\ReturnInboundOrderItem;
use App\Models\SysOperationLog;
use App\Models\Warehouse;
use App\Models\WmsSkuMapping;
use App\Services\Common\NoGeneratorService;
use App\Services\Common\OperationLogService;
use App\Services\Inventory\InventoryService;
use App\Services\Order\OrderService;
use App\Support\WmsMappingMode;
use Illuminate\Support\Facades\DB;

/**
 * 退货入库单服务（WMS 计划 P4 / F3~F8、Step 4）
 *
 * 退货闭环的唯一业务入口：
 * ```
 * createForRefund ──► markPendingPush ──► markPushing ──► markPushed
 *                                        └─► markPushFailed
 * markPushed ──► markReceiving ──► markReceived ──► complete（库存恢复 + 退款完成）
 * ```
 *
 * 与发货单同源的三条铁律：
 * 1. **状态流转唯一执行点** `transitionTo()`（事务 + lockForUpdate + canTransitTo）；
 * 2. **订单状态只能由** `OrderService::transitionTo()` 改写——本服务绝不直接写 `orders.status`；
 * 3. **先收货、后退款**：`complete()` 是唯一把退货退款推进 `success` 的入口（D-P4-1），
 *    库存以**实收正品数量**回加，残次（CC）不回可售（D-P4-3）。
 */
class ReturnInboundOrderService
{
    public function __construct(
        private readonly WmsConfigService $configs,
        private readonly NoGeneratorService $noGenerator,
        private readonly InventoryService $inventory,
        private readonly OrderService $orders,
        private readonly OperationLogService $operationLog,
    ) {}

    // ---------------- 建单 ----------------

    /**
     * 为退货退款单创建退货入库单（幂等）。
     *
     * - 已有**活跃**入库单（status != cancelled）直接返回，不重复建单；
     * - 选仓：显式入参 → refunds.warehouse_id → orders.warehouse_id → 默认仓（首个启用仓）；
     * - `manual` 映射缺 SKU 编码时**不中断建单**，落 `exception` 写明原因（与发货单同思路）；
     * - 配置 `auto_push_return` 为真且建单成功 → 派发推送作业。
     */
    public function createForRefund(Refund $refund, ?int $warehouseId = null): ReturnInboundOrder
    {
        $existing = $this->activeForRefund((int) $refund->id);
        if ($existing) {
            return $existing;
        }

        $warehouseId = $this->resolveWarehouseId($refund, $warehouseId);
        if (! $warehouseId) {
            throw BusinessException::badRequest('未指定退货入库仓库，无法创建退货入库单');
        }

        $config = $this->configs->getForWarehouse($warehouseId);
        if (! $config) {
            throw BusinessException::badRequest('该仓库尚未配置 WMS，无法创建退货入库单');
        }

        $details = $refund->return_details ?? [];
        if ($details === []) {
            throw BusinessException::badRequest('退款单没有退货明细，无法创建退货入库单');
        }

        [$rows, $exceptionReason] = $this->buildItems($refund, $config, $warehouseId, $details);

        $status = $exceptionReason !== null
            ? ReturnInboundOrder::STATUS_EXCEPTION
            : ($config->auto_push_return
                ? ReturnInboundOrder::STATUS_PENDING_PUSH
                : ReturnInboundOrder::STATUS_CREATED);

        $rio = DB::transaction(function () use ($refund, $warehouseId, $config, $rows, $status, $exceptionReason) {
            $rio = ReturnInboundOrder::create([
                'refund_id' => $refund->id,
                'refund_no' => $refund->refund_no,
                'order_id' => $refund->order_id,
                'order_no' => $refund->order_no,
                'inbound_no' => $this->noGenerator->generateReturnInboundNo(),
                'warehouse_id' => $warehouseId,
                'provider' => $config->provider,
                'status' => $status,
                'exception_reason' => $exceptionReason,
                'return_reason' => mb_substr((string) $refund->reason, 0, 250) ?: null,
                'extend' => [],
            ]);

            foreach ($rows as $row) {
                $rio->items()->create($row);
            }

            return $rio->load('items');
        });

        // 事务提交后再入队（避免 worker 抢跑到未提交数据上）
        if ($rio->status === ReturnInboundOrder::STATUS_PENDING_PUSH) {
            PushReturnInboundJob::dispatch($rio->id, $this->pushTriesFor($warehouseId));
        }

        return $rio;
    }

    /** 退款单当前活跃入库单（排除已取消） */
    public function activeForRefund(int $refundId): ?ReturnInboundOrder
    {
        return ReturnInboundOrder::where('refund_id', $refundId)
            ->where('status', '!=', ReturnInboundOrder::STATUS_CANCELLED)
            ->first();
    }

    // ---------------- 状态流转 ----------------

    public function markPendingPush(ReturnInboundOrder $rio): ReturnInboundOrder
    {
        return $this->transitionTo($rio, ReturnInboundOrder::STATUS_PENDING_PUSH);
    }

    /** 进入推送中：记录本次幂等键并累加推送次数 */
    public function markPushing(ReturnInboundOrder $rio, ?string $requestId = null): ReturnInboundOrder
    {
        return $this->transitionTo($rio, ReturnInboundOrder::STATUS_PUSHING, function (ReturnInboundOrder $m) use ($requestId) {
            $m->push_request_id = $requestId ?: $m->push_request_id;
            $m->push_times = (int) $m->push_times + 1;
            $m->last_push_at = now();
        });
    }

    public function markPushed(ReturnInboundOrder $rio, ?string $wmsInboundNo = null): ReturnInboundOrder
    {
        return $this->transitionTo($rio, ReturnInboundOrder::STATUS_PUSHED, function (ReturnInboundOrder $m) use ($wmsInboundNo) {
            if ($wmsInboundNo) {
                $m->wms_inbound_no = $wmsInboundNo;
            }
            $m->last_push_error = null;
        });
    }

    /** 记录一次推送失败原因（不改变状态，重试期间保留现场） */
    public function recordPushFailure(ReturnInboundOrder $rio, string $error): ReturnInboundOrder
    {
        $rio->forceFill(['last_push_error' => $error])->save();

        return $rio;
    }

    /** 推送重试超限：置 push_failed，转人工处理 */
    public function markPushFailed(ReturnInboundOrder $rio, string $error): ReturnInboundOrder
    {
        if ($rio->status === ReturnInboundOrder::STATUS_PUSH_FAILED) {
            return $this->recordPushFailure($rio, $error);
        }

        return $this->transitionTo($rio, ReturnInboundOrder::STATUS_PUSH_FAILED, function (ReturnInboundOrder $m) use ($error) {
            $m->last_push_error = $error;
        });
    }

    public function markReceiving(ReturnInboundOrder $rio): ReturnInboundOrder
    {
        return $this->transitionTo($rio, ReturnInboundOrder::STATUS_RECEIVING);
    }

    /**
     * WMS 回传/报告异常 → 转人工（缺映射修复、数量冲突等）。
     * 已是异常态则仅更新原因（重复回传同一条异常保持幂等）。
     */
    public function markException(ReturnInboundOrder $rio, string $reason): ReturnInboundOrder
    {
        if ($rio->status === ReturnInboundOrder::STATUS_EXCEPTION) {
            return $this->recordPushFailure($rio, $reason);
        }

        return $this->transitionTo($rio, ReturnInboundOrder::STATUS_EXCEPTION, function (ReturnInboundOrder $m) use ($reason) {
            $m->exception_reason = $reason !== '' ? $reason : null;
        });
    }

    /**
     * 收货回传落档（WMS 计划 P4 / F5、Step 6）。
     *
     * 语义：`pushed/receiving → received`，逐行写 `received_qty` / `inventory_type`。
     * **不做破坏性操作**（计划 Step 6）：
     * - 实收合计 = 0 → 转异常，退款单保持等待人工；
     * - 任一行实收 > 应退 → 转异常，不回库存；
     * - 实收 < 应退（少件）→ 记录差额后照常完成（差异不阻断退款，金额以审核金额为准）。
     *
     * @param  list<array<string, mixed>>  $lines  归一明细 [{platform_sku_code|sku_id, quantity, inventory_type}]
     */
    public function markReceived(
        ReturnInboundOrder $rio,
        array $lines,
        ?int $operatorId = null,
        ?string $exceptionReason = null,
    ): ReturnInboundOrder {
        $rio = $rio->loadMissing('items');
        $byCode = $rio->items->keyBy('platform_sku_code');
        $bySkuId = $rio->items->whereNotNull('sku_id')->keyBy('sku_id');

        // 归一回传明细到入库单行
        $received = [];   // item_id => ['qty' => int, 'type' => ZP|CC]
        $receivedTotal = 0;
        foreach ($lines as $line) {
            $item = null;
            $code = trim((string) ($line['platform_sku_code'] ?? $line['item_code'] ?? ''));
            $skuId = $line['sku_id'] ?? null;
            if ($code !== '' && isset($byCode[$code])) {
                $item = $byCode[$code];
            } elseif ($skuId && isset($bySkuId[(int) $skuId])) {
                $item = $bySkuId[(int) $skuId];
            }

            if (! $item) {
                return $this->markException($rio, sprintf(
                    '收货回传包含未知货品（%s），转人工处理',
                    $code !== '' ? $code : ('sku_id='.$skuId),
                ));
            }

            $qty = (int) ($line['quantity'] ?? $line['actualQty'] ?? 0);
            if ($qty < 0) {
                return $this->markException($rio, '收货回传数量非法（负数），转人工处理');
            }

            $type = strtoupper(trim((string) ($line['inventory_type'] ?? ReturnInboundOrder::INVENTORY_TYPE_GOOD)));
            if (! in_array($type, [ReturnInboundOrder::INVENTORY_TYPE_GOOD, ReturnInboundOrder::INVENTORY_TYPE_DEFECTIVE], true)) {
                $type = ReturnInboundOrder::INVENTORY_TYPE_GOOD;
            }

            $received[$item->id] = [
                'qty' => ($received[$item->id]['qty'] ?? 0) + $qty,
                'type' => $type,
            ];
            $receivedTotal += $qty;
        }

        // 实收 0：仓库空收——极可能是回传报文不全，绝不完成退款（计划 §4.3）
        if ($receivedTotal === 0) {
            return $this->markException($rio, 'WMS 收货回传实收数量为 0，转人工核实');
        }

        // 超收：不做破坏性操作，转人工
        foreach ($received as $itemId => $row) {
            $item = $rio->items->firstWhere('id', $itemId);
            if ($row['qty'] > (int) $item->qty) {
                return $this->markException($rio, sprintf(
                    '货品「%s」实收 %d 件超过应退 %d 件，转人工核实',
                    $item->platform_sku_code, $row['qty'], (int) $item->qty,
                ));
            }
        }

        // 差额（少件）记录：不阻断完成
        $expectedTotal = (int) $rio->items->sum('qty');
        $diffReason = $exceptionReason;
        if ($receivedTotal !== $expectedTotal) {
            $diffReason = trim(($diffReason ? $diffReason.'；' : '')."实收 {$receivedTotal} 件 ≠ 应退 {$expectedTotal} 件");
        }

        $this->transitionTo($rio, ReturnInboundOrder::STATUS_RECEIVED, function (ReturnInboundOrder $m) use ($received) {
            $m->received_at = now();
            foreach ($m->items as $item) {
                $row = $received[$item->id] ?? null;
                if ($row === null) {
                    continue;
                }
                $item->received_qty = $row['qty'];
                $item->inventory_type = $row['type'];
                $item->save();
            }
        });

        return $this->complete($rio->fresh(), $operatorId, $diffReason);
    }

    /**
     * 完成退货入库：按实收正品回加库存 → 退款 success → 订单 refunded（D-P4-1/3）。
     *
     * - 幂等：入库单已 completed 或退款已 success 直接返回，绝不二次加库存/二次放款；
     * - 金额以审核金额为准，不因实收差异自动改额（Step 7）；
     * - 完成后发 `RefundResult` 事件通知买家（复用退款通知链路，F8）。
     */
    public function complete(ReturnInboundOrder $rio, ?int $operatorId = null, ?string $exceptionReason = null): ReturnInboundOrder
    {
        // 快速幂等短路（无事务）：终态直接返回
        if ($rio->status === ReturnInboundOrder::STATUS_COMPLETED) {
            return $rio;
        }

        $operatorType = $operatorId ? OrderLog::OPERATOR_ADMIN : OrderLog::OPERATOR_SYSTEM;

        $completedNow = false;

        [$rio, $refund] = DB::transaction(function () use ($rio, $operatorId, $exceptionReason, $operatorType, &$completedNow) {
            /** @var ReturnInboundOrder $locked */
            $locked = ReturnInboundOrder::whereKey($rio->id)->lockForUpdate()->first();
            if (! $locked) {
                throw BusinessException::notFound('退货入库单不存在');
            }

            /** @var Refund $refund */
            $refund = Refund::whereKey($locked->refund_id)->lockForUpdate()->first();
            if (! $refund) {
                throw BusinessException::notFound('退货入库单对应退款单不存在');
            }

            // 幂等：退款已完成（如后台确认收货先行）→ 只把入库单推进终态
            if ($refund->status === Refund::STATUS_SUCCESS) {
                if ($locked->status !== ReturnInboundOrder::STATUS_COMPLETED) {
                    $locked->status = ReturnInboundOrder::STATUS_COMPLETED;
                    $locked->received_at ??= now();
                    $locked->save();
                }

                return [$locked, $refund];
            }

            if ($locked->status !== ReturnInboundOrder::STATUS_RECEIVED) {
                throw BusinessException::conflict(sprintf(
                    '退货入库单当前状态「%s」不允许完成结算',
                    $locked->statusLabel(),
                ));
            }

            $order = Order::whereKey($refund->order_id)->lockForUpdate()->first();

            // 1) 库存恢复：只回**实收正品**（D-P4-3）；流水 biz_type=return_inbound 便于对账（Step 7）
            $receivedDetails = [];
            foreach ($locked->items()->get() as $item) {
                $qty = (int) $item->received_qty;
                if ($qty <= 0) {
                    continue;
                }

                $good = $item->inventory_type === ReturnInboundOrder::INVENTORY_TYPE_GOOD;
                if ($good && $item->sku_id) {
                    $this->inventory->adjust(
                        (int) $item->sku_id,
                        $qty,
                        $operatorId,
                        '退货入库单 '.$locked->inbound_no,
                        'return_inbound',
                    );
                }

                $receivedDetails[] = [
                    'sku_id' => (int) $item->sku_id,
                    'quantity' => $qty,
                    'condition' => $good ? Refund::RETURN_CONDITION_GOOD : Refund::RETURN_CONDITION_DEFECTIVE,
                ];
            }

            // 2) 退款完成 + 订单 refunded（走唯一执行点）
            $refund->status = Refund::STATUS_SUCCESS;
            $refund->return_status = Refund::RETURN_STATUS_RECEIVED;
            $refund->return_received_details = $receivedDetails;
            $refund->return_received_at = now();
            $refund->return_exception_reason = $exceptionReason ?: null;
            $refund->save();

            if ($order && $order->status !== Order::STATUS_REFUNDED) {
                $this->orders->transitionTo(
                    $order,
                    Order::STATUS_REFUNDED,
                    '退货入库完成退款（'.$locked->inbound_no.'）',
                    'order',
                    $operatorId,
                    $operatorType,
                );
            }

            // 整单退货退款且金额等于实付：返还券（与后台确认收货路径一致）
            if ($order && abs((float) $refund->amount - (float) $order->pay_amount) < 0.005) {
                $this->orders->releaseCoupon($order);
            }

            // 3) 入库单 → completed
            $locked->status = ReturnInboundOrder::STATUS_COMPLETED;
            $locked->received_at ??= now();
            $locked->save();

            $completedNow = true;

            return [$locked, $refund->refresh()];
        });

        // 幂等重入（入库单/退款早已完成）：不写审计、不重复通知
        if (! $completedNow) {
            return $rio->fresh();
        }

        $this->operationLog->record(
            $operatorId,
            'wms',
            'return_inbound_completed',
            'return_inbound_order',
            $rio->id,
            [
                'inbound_no' => $rio->inbound_no,
                'refund_no' => $rio->refund_no,
                'order_no' => $rio->order_no,
                'exception_reason' => $exceptionReason,
                'operator_type' => $operatorType,
            ],
            // 无操作人（WMS 回传驱动）也归 admin 桶（同 P3 callback_alert 约定）
            SysOperationLog::ACTOR_ADMIN,
        );

        // 通知买家退款完成（失败不影响主流程）
        try {
            event(new RefundResult($refund));
        } catch (\Throwable $e) {
            report($e);
        }

        return $rio->fresh();
    }

    /**
     * 取消退货入库单（收货完成前）。
     *
     * 已推送过的单据除本地置取消外，还要通知 WMS 撤单（异步，失败只记日志不回滚——
     * 退款单停在 approved 等人工，与发货单 cancel 同思路）。
     */
    public function cancel(ReturnInboundOrder $rio, string $reason, ?int $operatorId = null): ReturnInboundOrder
    {
        $wasPushed = in_array($rio->status, [
            ReturnInboundOrder::STATUS_PUSHED, ReturnInboundOrder::STATUS_RECEIVING,
        ], true);

        $result = $this->transitionTo($rio, ReturnInboundOrder::STATUS_CANCELLED, function (ReturnInboundOrder $m) use ($operatorId, $reason) {
            $m->cancelled_at = now();
            $m->exception_reason = $reason !== '' ? $reason : null;

            if ($operatorId) {
                $this->operationLog->record(
                    $operatorId,
                    'wms',
                    'return_inbound_cancelled',
                    'return_inbound_order',
                    $m->id,
                    [
                        'inbound_no' => $m->inbound_no,
                        'refund_no' => $m->refund_no,
                        'reason' => $reason,
                    ],
                    SysOperationLog::ACTOR_ADMIN,
                );
            }
        });

        if ($wasPushed && $result->wms_inbound_no) {
            CancelReturnInboundJob::dispatch($result->id, $reason);
        }

        return $result;
    }

    /**
     * 手工重推（后台按钮 / 异常修复后）。状态回到 pending_push 并派发推送作业。
     */
    public function retryPush(ReturnInboundOrder $rio, ?int $operatorId = null): ReturnInboundOrder
    {
        if (! in_array($rio->status, ReturnInboundOrder::PUSHABLE, true)) {
            throw BusinessException::conflict(sprintf(
                '退货入库单当前状态「%s」不允许重推',
                $rio->statusLabel(),
            ));
        }

        $result = $rio->status === ReturnInboundOrder::STATUS_PENDING_PUSH
            ? $rio
            : $this->transitionTo($rio, ReturnInboundOrder::STATUS_PENDING_PUSH, function (ReturnInboundOrder $m) {
                // 重推前清掉旧的推送上下文，让作业重新生成幂等键
                $m->push_request_id = null;
            });

        if ($operatorId) {
            $this->operationLog->record(
                $operatorId,
                'wms',
                'return_inbound_retry_push',
                'return_inbound_order',
                $result->id,
                [
                    'inbound_no' => $result->inbound_no,
                    'refund_no' => $result->refund_no,
                    'push_times' => (int) $result->push_times,
                ],
                SysOperationLog::ACTOR_ADMIN,
            );
        }

        PushReturnInboundJob::dispatch($result->id, $this->pushTriesFor((int) $result->warehouse_id));

        return $result;
    }

    /**
     * 后台手工标记收货（回传丢失兜底，计划 F9）。
     *
     * 未提供明细时按「全部应退行足额正品实收」处理，走与回传完全相同的
     * markReceived → complete 链路（计划 §4.3「等价于收货处理」）。
     *
     * @param  list<array<string, mixed>>|null  $receivedDetails  [{sku_id|platform_sku_code, quantity, inventory_type}]
     */
    public function manualReceived(
        ReturnInboundOrder $rio,
        ?array $receivedDetails,
        ?int $operatorId = null,
        ?string $exceptionReason = null,
    ): ReturnInboundOrder {
        if (! in_array($rio->status, [
            ReturnInboundOrder::STATUS_PUSHED,
            ReturnInboundOrder::STATUS_RECEIVING,
            // 超收/异常单经人工核实后按实情标记收货
            ReturnInboundOrder::STATUS_EXCEPTION,
        ], true)) {
            throw BusinessException::conflict(sprintf(
                '退货入库单当前状态「%s」不允许标记收货',
                $rio->statusLabel(),
            ));
        }

        if (empty($receivedDetails)) {
            $receivedDetails = $rio->loadMissing('items')->items->map(fn ($item) => [
                'platform_sku_code' => $item->platform_sku_code,
                'quantity' => (int) $item->qty,
                'inventory_type' => ReturnInboundOrder::INVENTORY_TYPE_GOOD,
            ])->values()->all();
        }

        return $this->markReceived($rio, $receivedDetails, $operatorId, $exceptionReason);
    }

    // ---------------- 内部 ----------------

    /** 该仓配置的重试次数（缺配置按 3；上限由作业自身兜底） */
    private function pushTriesFor(int $warehouseId): int
    {
        $config = $this->configs->getForWarehouse($warehouseId);

        return $config ? (int) $config->push_retry_times : 3;
    }

    /**
     * 选仓：显式入参 → refunds.warehouse_id → orders.warehouse_id → 首个启用仓。
     */
    private function resolveWarehouseId(Refund $refund, ?int $warehouseId): ?int
    {
        if ($warehouseId) {
            return (int) $warehouseId;
        }
        if ($refund->warehouse_id) {
            return (int) $refund->warehouse_id;
        }

        $order = $refund->order()->first();
        if ($order?->warehouse_id) {
            return (int) $order->warehouse_id;
        }

        return Warehouse::where('status', 1)->orderBy('id')->value('id');
    }

    /**
     * 组装行项目快照 + WMS 货品编码（从 refunds.return_details）。
     *
     * @param  array<int, array<string, mixed>>  $details
     * @return array{0: array<int, array<string, mixed>>, 1: string|null}  [行数组, 异常原因（null=全部解析成功）]
     */
    private function buildItems(Refund $refund, $config, int $warehouseId, array $details): array
    {
        $rows = [];
        $exceptionReason = null;

        foreach ($details as $row) {
            $platformCode = '';
            $wmsCode = '';
            $barcode = null;
            $productName = $row['product_title'] ?? null;

            $sku = ! empty($row['sku_id']) ? ProductSku::withTrashed()->find($row['sku_id']) : null;
            if (! $sku) {
                $exceptionReason ??= '退货明细缺少 SKU 信息，无法解析 WMS 货品编码';
            } else {
                $platformCode = (string) $sku->sku_code;

                try {
                    $wmsCode = $this->configs->resolveSkuCode($config, (int) $sku->id);
                } catch (BusinessException $e) {
                    $exceptionReason ??= $e->getMessage();
                }

                if ($config->sku_mapping_mode === WmsMappingMode::MANUAL) {
                    $barcode = WmsSkuMapping::where('warehouse_id', $warehouseId)
                        ->where('sku_id', $sku->id)
                        ->where('status', 1)
                        ->value('barcode');
                }

                $productName ??= $sku->product->title ?? null;
            }

            $rows[] = [
                'sku_id' => $sku?->id,
                'platform_sku_code' => $platformCode,
                'wms_sku_code' => $wmsCode,
                'product_name' => $productName,
                'qty' => (int) ($row['quantity'] ?? 0),
                'received_qty' => 0,
                'inventory_type' => ReturnInboundOrder::INVENTORY_TYPE_GOOD,
                'barcode' => $barcode,
            ];
        }

        return [$rows, $exceptionReason];
    }

    /**
     * 状态流转唯一执行点（与发货单同构）。
     *
     * @param  callable(ReturnInboundOrder): void|null  $mutate
     */
    private function transitionTo(ReturnInboundOrder $rio, string $target, ?callable $mutate = null): ReturnInboundOrder
    {
        return DB::transaction(function () use ($rio, $target, $mutate) {
            /** @var ReturnInboundOrder $locked */
            $locked = ReturnInboundOrder::whereKey($rio->id)->lockForUpdate()->first();
            if (! $locked) {
                throw BusinessException::notFound('退货入库单不存在');
            }

            if (! $locked->canTransitTo($target)) {
                throw BusinessException::conflict(sprintf(
                    '退货入库单当前状态「%s」不允许变更为「%s」',
                    $locked->statusLabel(),
                    ReturnInboundOrder::STATUS_LABELS[$target] ?? $target,
                ));
            }

            $locked->status = $target;
            if ($mutate) {
                $mutate($locked);
            }
            $locked->save();

            return $locked;
        });
    }
}
