<?php

namespace App\Services\Wms;

use App\Exceptions\BusinessException;
use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\ProductSku;
use App\Models\SysOperationLog;
use App\Models\WmsConfig;
use App\Models\WmsSkuMapping;
use App\Services\Common\NoGeneratorService;
use App\Services\Common\OperationLogService;
use App\Services\Order\OrderService;
use App\Support\WmsMappingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * 履约发货单服务（WMS 计划 P1 / F1～F7、F9）
 *
 * 平台内部履约链路的唯一业务入口：
 * ```
 * createForOrder ──► markPendingPush ──► markPushing ──► markPushed
 *                                        └─► markPushFailed
 * markPushed ──► markPicking ──► markPacked ──► markShipped ──► (完成)
 * ```
 *
 * 两条铁律（与订单域同源）：
 * 1. **状态流转唯一执行点** `transitionTo()`：事务 + `lockForUpdate` + `canTransitTo`，
 *    非法流转统一 `BusinessException::conflict`（40009 / HTTP 409）；
 * 2. **订单状态只能由** `OrderService::shipForShipment()` **改写**——本服务绝不直接写
 *    `orders.status`，`markShipped()` 是唯一挂着订单发货的入口。
 */
class FulfillmentOrderService
{
    public function __construct(
        private readonly WmsConfigService $configs,
        private readonly NoGeneratorService $noGenerator,
        private readonly OrderService $orders,
        private readonly OperationLogService $operationLog,
    ) {}

    /**
     * 为订单创建发货单（幂等）。
     *
     * - 已有**活跃**发货单（status != cancelled）直接返回，不重复建单；
     * - 已取消的历史发货单不阻塞新建（退款被驳回 → 订单回到已支付 → 重新受理的场景）；
     * - `manual` 映射缺 SKU 编码时**不中断建单**，而是把发货单落成 `exception` 并写明原因，
     *   等运营补映射后重推——推错货的成本远高于延迟发货；
     * - 并发下由部分唯一索引 `fulfillment_orders_active_order_unique` 兜底，命中冲突即回查复用。
     */
    public function createForOrder(Order $order, ?int $warehouseId = null): FulfillmentOrder
    {
        $existing = $this->activeForOrder((int) $order->id);
        if ($existing) {
            return $existing;
        }

        $warehouseId = $warehouseId ?? ($order->warehouse_id ? (int) $order->warehouse_id : null);
        if (! $warehouseId) {
            throw BusinessException::badRequest('未指定履约仓库，无法创建发货单');
        }

        $config = $this->configs->getForWarehouse($warehouseId);
        if (! $config) {
            throw BusinessException::badRequest('该仓库尚未配置 WMS，无法创建发货单');
        }

        $order->loadMissing('items');
        if ($order->items->isEmpty()) {
            throw BusinessException::badRequest('订单没有可发货的商品');
        }

        [$rows, $exceptionReason] = $this->buildItems($order, $config, $warehouseId);

        $status = $exceptionReason !== null
            ? FulfillmentOrder::STATUS_EXCEPTION
            : ($config->auto_push ? FulfillmentOrder::STATUS_PENDING_PUSH : FulfillmentOrder::STATUS_CREATED);

        try {
            return DB::transaction(function () use ($order, $warehouseId, $config, $rows, $status, $exceptionReason) {
                $fo = FulfillmentOrder::create([
                    'order_id' => $order->id,
                    'order_no' => $order->order_no,
                    'outbound_no' => $this->noGenerator->generateOutboundNo(),
                    'warehouse_id' => $warehouseId,
                    'provider' => $config->provider,
                    'status' => $status,
                    'exception_reason' => $exceptionReason,
                    'buyer_info' => $order->address_snapshot,
                    'shipping_info' => ['remark' => $order->remark],
                    'extend' => [],
                ]);

                foreach ($rows as $row) {
                    $fo->items()->create($row);
                }

                // 订单侧挂载：履约仓 + 发货单状态冗余
                $order->forceFill([
                    'warehouse_id' => $warehouseId,
                    'fulfillment_status' => $status,
                ])->save();

                return $fo->load('items');
            });
        } catch (QueryException $e) {
            // 并发建单撞唯一索引：回查已存在的那张（幂等语义）
            $existing = $this->activeForOrder((int) $order->id);
            if ($existing) {
                return $existing;
            }

            throw $e;
        }
    }

    /** 订单当前活跃发货单（排除已取消） */
    public function activeForOrder(int $orderId): ?FulfillmentOrder
    {
        return FulfillmentOrder::where('order_id', $orderId)
            ->where('status', '!=', FulfillmentOrder::STATUS_CANCELLED)
            ->first();
    }

    /**
     * 解析订单应走的 WMS 配置（供事件监听器判断是否介入）。
     *
     * 订单已挂履约仓则用该仓的启用配置；否则退化为「唯一启用的配置」。
     * 返回 null 表示订单不该进 WMS 履约链路（既有流程零变化）。
     */
    public function resolveConfigFor(Order $order): ?WmsConfig
    {
        if ($order->warehouse_id) {
            $config = WmsConfig::where('warehouse_id', $order->warehouse_id)
                ->where('enabled', true)
                ->first();
            if ($config) {
                return $config;
            }
        }

        return WmsConfig::where('enabled', true)->orderBy('id')->first();
    }

    // ---------------- 状态流转 ----------------

    public function markPendingPush(FulfillmentOrder $fo): FulfillmentOrder
    {
        return $this->transitionTo($fo, FulfillmentOrder::STATUS_PENDING_PUSH);
    }

    /** 进入推送中：记录本次幂等键并累加推送次数（push_times = 实际发起的推送尝试次数） */
    public function markPushing(FulfillmentOrder $fo, ?string $requestId = null): FulfillmentOrder
    {
        return $this->transitionTo($fo, FulfillmentOrder::STATUS_PUSHING, function (FulfillmentOrder $m) use ($requestId) {
            $m->push_request_id = $requestId ?: $m->push_request_id;
            $m->push_times = (int) $m->push_times + 1;
            $m->last_push_at = now();
        });
    }

    public function markPushed(FulfillmentOrder $fo, ?string $wmsOutboundNo = null): FulfillmentOrder
    {
        return $this->transitionTo($fo, FulfillmentOrder::STATUS_PUSHED, function (FulfillmentOrder $m) use ($wmsOutboundNo) {
            if ($wmsOutboundNo) {
                $m->wms_outbound_no = $wmsOutboundNo;
            }
            $m->last_push_error = null;
        });
    }

    /** 记录一次推送失败原因（不改变状态，重试期间保留现场） */
    public function recordPushFailure(FulfillmentOrder $fo, string $error): FulfillmentOrder
    {
        $fo->forceFill(['last_push_error' => $error])->save();

        return $fo;
    }

    /** 推送重试超限：置 PushFailed，转人工处理 */
    public function markPushFailed(FulfillmentOrder $fo, string $error): FulfillmentOrder
    {
        if ($fo->status === FulfillmentOrder::STATUS_PUSH_FAILED) {
            return $this->recordPushFailure($fo, $error);
        }

        return $this->transitionTo($fo, FulfillmentOrder::STATUS_PUSH_FAILED, function (FulfillmentOrder $m) use ($error) {
            $m->last_push_error = $error;
        });
    }

    public function markPicking(FulfillmentOrder $fo): FulfillmentOrder
    {
        return $this->transitionTo($fo, FulfillmentOrder::STATUS_PICKING);
    }

    public function markPacked(FulfillmentOrder $fo): FulfillmentOrder
    {
        return $this->transitionTo($fo, FulfillmentOrder::STATUS_PACKED);
    }

    /**
     * WMS 回传仓库异常（缺货/地址不清等）→ 转人工处理（WMS 计划 P3 / F2）。
     *
     * 入边见 {@see FulfillmentOrder::TRANSITIONS}（pending_push/pushing/pushed/picking → exception）。
     * 已是异常态则仅更新原因（重复回传同一条异常消息时保持幂等）。
     */
    public function markException(FulfillmentOrder $fo, string $reason): FulfillmentOrder
    {
        if ($fo->status === FulfillmentOrder::STATUS_EXCEPTION) {
            return $this->recordPushFailure($fo, $reason);
        }

        return $this->transitionTo($fo, FulfillmentOrder::STATUS_EXCEPTION, function (FulfillmentOrder $m) use ($reason) {
            $m->exception_reason = $reason !== '' ? $reason : null;
        });
    }

    /**
     * 回传运单 → 完成订单发货（WMS 计划 P1 / F6，**唯一入口**）。
     *
     * - 订单状态改写入 `OrderService::shipForShipment()`（唯一执行点），本服务不碰 `orders.status`；
     * - 幂等：同一运单号重复回传直接成功返回，不报错（WMS 回调会重试）；
     * - `shippedQty` 传 `[sku_id => 数量]`，未传的行按应发数量计。
     */
    public function markShipped(
        FulfillmentOrder $fo,
        string $carrierCode,
        string $carrierName,
        string $trackingNo,
        array $shippedQty = [],
        ?\DateTimeInterface $shippedAt = null,
    ): FulfillmentOrder {
        // 幂等短路：同运单号已发货，重复回传视为成功
        if ($fo->status === FulfillmentOrder::STATUS_SHIPPED && $fo->tracking_no === $trackingNo) {
            return $fo;
        }

        if (! in_array($fo->status, [
            FulfillmentOrder::STATUS_PUSHED,
            FulfillmentOrder::STATUS_PICKING,
            FulfillmentOrder::STATUS_PACKED,
        ], true)) {
            throw BusinessException::conflict(sprintf(
                '发货单当前状态「%s」不允许回传发货',
                $fo->statusLabel(),
            ));
        }

        $order = $fo->order()->first();
        if (! $order) {
            throw BusinessException::notFound('发货单对应订单不存在');
        }

        // 1) 订单发货（唯一入口）。订单已发货且运单号一致时视为已达成，跳过流转。
        if ($order->status !== Order::STATUS_SHIPPED) {
            $this->orders->shipForShipment(
                $order,
                $carrierCode,
                $carrierName,
                $trackingNo,
                'WMS 回传运单',
                null,
                OrderLog::OPERATOR_SYSTEM,
            );
        } elseif ($order->tracking_no !== $trackingNo) {
            throw BusinessException::conflict('订单已发货且运单号不一致，请人工核对');
        }

        // 2) 发货单落运单与实发数量
        return $this->transitionTo($fo, FulfillmentOrder::STATUS_SHIPPED, function (FulfillmentOrder $m) use ($carrierCode, $carrierName, $trackingNo, $shippedQty, $shippedAt) {
            $m->carrier_code = $carrierCode;
            $m->carrier_name = $carrierName;
            $m->tracking_no = $trackingNo;
            $m->shipped_at = $shippedAt ?: now();

            foreach ($m->items()->get() as $item) {
                $item->shipped_qty = $shippedQty[$item->sku_id] ?? $item->qty;
                $item->save();
            }
        });
    }

    /**
     * 取消发货单（WMS 计划 P1 / F7）。
     *
     * 仅出库前可取消（见 {@see FulfillmentOrder::cancellableStates()}）；已推送过的单据
     * 除本地置取消外，还要通知 WMS 撤单（异步，失败只记日志不回滚本地取消——
     * 订单已取消，留着 WMS 出库单才是更大的风险）。
     */
    public function cancel(FulfillmentOrder $fo, string $reason, ?int $operatorId = null): FulfillmentOrder
    {
        $wasPushed = in_array($fo->status, [
            FulfillmentOrder::STATUS_PUSHED, FulfillmentOrder::STATUS_PICKING,
        ], true);

        $result = $this->transitionTo($fo, FulfillmentOrder::STATUS_CANCELLED, function (FulfillmentOrder $m) use ($operatorId, $reason) {
            $m->cancelled_at = now();
            $m->exception_reason = $reason !== '' ? $reason : null;

            if ($operatorId) {
                $this->operationLog->record(
                    $operatorId,
                    'wms',
                    'fulfillment_cancelled',
                    'fulfillment_order',
                    $m->id,
                    [
                        'outbound_no' => $m->outbound_no,
                        'order_no' => $m->order_no,
                        'wms_outbound_no' => $m->wms_outbound_no,
                        'reason' => $reason,
                    ],
                    SysOperationLog::ACTOR_ADMIN,
                );
            }
        });

        if ($wasPushed && $result->wms_outbound_no) {
            \App\Jobs\Wms\CancelOutboundJob::dispatch($result->id, $reason);
        }

        return $result;
    }

    /**
     * 记录一次「通知 WMS 撤单失败」（WMS 计划 P2 / F5）。
     *
     * 为什么特别处理：本地状态在 `cancel()` 时已置 `cancelled`（终态，矩阵里没有出边），
     * 所以这里**不能也不该**把状态改回去。但仓方拒撤（典型是「已出库」）意味着
     * **货可能已经在路上，而平台侧订单已取消**——这是必须被人看见的事。
     * 于是：失败详情落 `extend.cancel_outbound`，并写一条操作日志（`cancel_outbound_failed`，
     * 无操作人，属系统行为），后台审计里可检索到。
     */
    public function recordCancelFailure(FulfillmentOrder $fo, string $error, ?string $wmsCode = null): FulfillmentOrder
    {
        $extend = $fo->extend ?? [];
        $extend['cancel_outbound'] = [
            'success' => false,
            'error' => $error,
            'wms_code' => $wmsCode,
            'at' => now()->toDateTimeString(),
        ];

        $fo->forceFill(['extend' => $extend])->save();

        $this->operationLog->record(
            null,
            'wms',
            'cancel_outbound_failed',
            'fulfillment_order',
            $fo->id,
            [
                'outbound_no' => $fo->outbound_no,
                'order_no' => $fo->order_no,
                'wms_outbound_no' => $fo->wms_outbound_no,
                'wms_code' => $wmsCode,
                'error' => $error,
            ],
            SysOperationLog::ACTOR_ADMIN,
        );

        return $fo;
    }

    /**
     * 手工重推（后台按钮 / `PushFailed`、`Exception` 修复后）。
     *
     * 状态回到 `pending_push`（已是 `pending_push` 则不动），并派发推送作业；
     * `push_times` 由作业在真正尝试时累加，避免「点了重推但没推」也算一次。
     */
    public function retryPush(FulfillmentOrder $fo, ?int $operatorId = null): FulfillmentOrder
    {
        if (! in_array($fo->status, FulfillmentOrder::PUSHABLE, true)) {
            throw BusinessException::conflict(sprintf(
                '发货单当前状态「%s」不允许重推',
                $fo->statusLabel(),
            ));
        }

        $result = $fo->status === FulfillmentOrder::STATUS_PENDING_PUSH
            ? $fo
            : $this->transitionTo($fo, FulfillmentOrder::STATUS_PENDING_PUSH, function (FulfillmentOrder $m) {
                // 重推前清掉旧的推送上下文，让作业重新生成幂等键
                $m->push_request_id = null;
            });

        if ($operatorId) {
            $this->operationLog->record(
                $operatorId,
                'wms',
                'fulfillment_retry_push',
                'fulfillment_order',
                $result->id,
                [
                    'outbound_no' => $result->outbound_no,
                    'order_no' => $result->order_no,
                    'push_times' => (int) $result->push_times,
                ],
                SysOperationLog::ACTOR_ADMIN,
            );
        }

        \App\Jobs\Wms\PushOutboundJob::dispatch($result->id, $this->pushTriesFor((int) $result->warehouse_id));

        return $result;
    }

    // ---------------- 内部 ----------------

    /** 该仓配置的重试次数（缺配置按 3；上限由作业自身兜底） */
    private function pushTriesFor(int $warehouseId): int
    {
        $config = $this->configs->getForWarehouse($warehouseId);

        return $config ? (int) $config->push_retry_times : 3;
    }

    /**
     * 状态流转唯一执行点。
     *
     * @param  callable(FulfillmentOrder): void|null  $mutate  同事务内的字段写入（如运单号、时间戳）
     */
    private function transitionTo(FulfillmentOrder $fo, string $target, ?callable $mutate = null): FulfillmentOrder
    {
        return DB::transaction(function () use ($fo, $target, $mutate) {
            /** @var FulfillmentOrder $locked */
            $locked = FulfillmentOrder::whereKey($fo->id)->lockForUpdate()->first();
            if (! $locked) {
                throw BusinessException::notFound('发货单不存在');
            }

            if (! $locked->canTransitTo($target)) {
                throw BusinessException::conflict(sprintf(
                    '发货单当前状态「%s」不允许变更为「%s」',
                    $locked->statusLabel(),
                    FulfillmentOrder::STATUS_LABELS[$target] ?? $target,
                ));
            }

            $locked->status = $target;
            if ($mutate) {
                $mutate($locked);
            }
            $locked->save();

            // 订单侧状态冗余（真值在 fulfillment_orders，这里只为列表筛选）
            Order::whereKey($locked->order_id)->update(['fulfillment_status' => $target]);

            return $locked;
        });
    }

    /**
     * 组装行项目快照 + WMS 货品编码。
     *
     * @return array{0: array<int, array<string, mixed>>, 1: string|null}  [行数组, 异常原因（null=全部解析成功）]
     */
    private function buildItems(Order $order, WmsConfig $config, int $warehouseId): array
    {
        $rows = [];
        $exceptionReason = null;

        foreach ($order->items as $item) {
            $platformCode = '';
            $wmsCode = '';
            $barcode = null;

            $sku = $item->sku_id ? ProductSku::withTrashed()->find($item->sku_id) : null;
            if (! $sku) {
                $exceptionReason ??= '订单商品缺少 SKU 信息，无法解析 WMS 货品编码';
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
            }

            $rows[] = [
                'sku_id' => $item->sku_id,
                'platform_sku_code' => $platformCode,
                'wms_sku_code' => $wmsCode,
                'product_name' => $item->product_title,
                'qty' => (int) $item->quantity,
                'shipped_qty' => 0,
                'barcode' => $barcode,
            ];
        }

        return [$rows, $exceptionReason];
    }
}
