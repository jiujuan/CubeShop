<?php

namespace App\Services\Order;

use App\Exceptions\BusinessException;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLog;
use App\Models\ProductSku;
use App\Models\UserAddress;
use App\Services\Common\ConfigService;
use App\Services\Common\NoGeneratorService;
use App\Services\Common\OperationLogService;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 订单服务（Roadmap P4：订单与结算闭环）
 *
 * 职责：
 * - 创建订单：地址快照 + 商品快照 + 运费计算 + 库存锁定（事务）
 * - 状态机校验：禁止非法流转
 * - 取消订单：释放库存 + 记录原因
 * - 超时取消：供 Scheduler 命令调用（order.timeout_minutes）
 */
class OrderService
{
    public function __construct(
        private InventoryService $inventory,
        private NoGeneratorService $noGenerator,
        private ConfigService $config,
        private OperationLogService $operationLog,
        private OrderLogService $orderLog,
    ) {
    }

    /**
     * 从购物车创建订单（API 文档 6.1）
     *
     * @param  int  $userId  下单用户
     * @param  int  $addressId  收货地址
     * @param  array<int, int>|null  $cartItemIds  要结算的购物车项；null = 全部有效项
     * @param  string|null  $remark  用户备注
     * @return Order 待支付订单
     *
     * @throws BusinessException 地址无效 / 无有效商品 / 库存不足
     */
    public function createFromCart(int $userId, int $addressId, ?array $cartItemIds, ?string $remark): Order
    {
        // 1. 地址校验（本人地址）
        $address = UserAddress::where('user_id', $userId)->find($addressId);
        if (! $address) {
            throw BusinessException::notFound('收货地址不存在');
        }

        // 2. 取购物车项
        $query = CartItem::where('user_id', $userId)
            ->with(['sku:id,product_id,specs,price,status', 'sku.product:id,title,main_image,status']);
        if ($cartItemIds !== null && $cartItemIds !== []) {
            $query->whereIn('id', $cartItemIds);
        }
        $cartItems = $query->orderBy('id')->get();

        if ($cartItems->isEmpty()) {
            throw BusinessException::badRequest('没有可结算的商品');
        }

        // 3. 逐项校验有效性 + 预检库存（给出友好错误；真实防超卖由 lock 的条件更新兜底）
        $stockMap = $this->inventory->getStockMap($cartItems->pluck('sku_id')->unique()->all());
        foreach ($cartItems as $item) {
            $sku = $item->sku;
            $product = $sku?->product;

            if (! $sku || ! $product) {
                throw BusinessException::conflict('购物车中存在已失效商品，请移除后重试');
            }
            if ((int) $product->status !== 1) {
                throw BusinessException::conflict(sprintf('商品「%s」已下架，无法结算', $product->title));
            }
            if ((int) $sku->status !== 1) {
                throw BusinessException::conflict(sprintf('商品「%s」规格已失效，无法结算', $product->title));
            }
            $stock = $stockMap[$item->sku_id] ?? 0;
            if ($stock < $item->quantity) {
                throw BusinessException::conflict(sprintf('商品「%s」库存不足（仅剩 %d 件）', $product->title, $stock));
            }
        }

        // 4. 金额计算（快照单价）+ 运费
        $totalAmount = '0.00';
        foreach ($cartItems as $item) {
            $subtotal = bcmul((string) $item->sku->price, (string) $item->quantity, 2);
            $totalAmount = bcadd($totalAmount, $subtotal, 2);
        }
        $freightAmount = $this->calcFreight($totalAmount);
        $payAmount = bcadd($totalAmount, $freightAmount, 2);

        // 5. 事务：锁库存 → 建订单 → 快照明细 → 清理已结算购物车项
        $order = DB::transaction(function () use ($userId, $address, $cartItems, $totalAmount, $freightAmount, $payAmount, $remark) {
            foreach ($cartItems as $item) {
                $this->inventory->lock($item->sku_id, $item->quantity, 'order', null, '下单锁定库存');
            }

            /** @var Order $order */
            $order = Order::create([
                'order_no' => $this->noGenerator->generateOrderNo(),
                'user_id' => $userId,
                'status' => Order::STATUS_PENDING_PAYMENT,
                'total_amount' => $totalAmount,
                'freight_amount' => $freightAmount,
                'pay_amount' => $payAmount,
                'address_snapshot' => [
                    'contact_name' => $address->contact_name,
                    'contact_phone' => $address->contact_phone,
                    'province' => $address->province,
                    'city' => $address->city,
                    'district' => $address->district,
                    'detail_address' => $address->detail_address,
                    'full_address' => trim(sprintf(
                        '%s%s%s%s',
                        (string) $address->province,
                        (string) $address->city,
                        (string) $address->district,
                        (string) $address->detail_address,
                    )),
                ],
                'remark' => $remark,
            ]);

            foreach ($cartItems as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item->sku->product_id,
                    'sku_id' => $item->sku_id,
                    'product_title' => $item->sku->product->title,
                    'sku_specs' => $item->sku->specs,
                    'sku_image' => $item->sku->product->main_image,
                    'price' => $item->sku->price,
                    'quantity' => $item->quantity,
                    'total_amount' => bcmul((string) $item->sku->price, (string) $item->quantity, 2),
                ]);
            }

            // 已结算的购物车项移除
            CartItem::where('user_id', $userId)->whereIn('id', $cartItems->pluck('id'))->delete();

            // V1.1 E04 / T-028：地址使用频次累加（供地址列表按常用排序）
            app(\App\Services\Address\AddressService::class)->recordUsage($address->id);

            return $order;
        });

        $this->operationLog->record($userId, 'order', 'create', 'order', $order->id, [
            'order_no' => $order->order_no,
            'pay_amount' => $order->pay_amount,
            'items' => $cartItems->count(),
        ]);

        // V1.1 T-001：创建订单的初始流水
        $this->orderLog->recordCreated($order, OrderLog::OPERATOR_USER, $userId);

        return $order->load('items');
    }

    /**
     * 用户取消订单（API 文档 6.4）：仅待支付可取消，释放库存
     */
    public function cancel(Order $order, int $userId, ?string $reason = null): Order
    {
        if ($order->user_id !== $userId) {
            throw BusinessException::notFound('订单不存在');
        }

        return $this->transitionTo($order, Order::STATUS_CANCELLED, $reason ?? '用户主动取消', 'cancel', $userId, OrderLog::OPERATOR_USER);
    }

    /**
     * 超时自动取消（Scheduler 命令调用）：释放库存，幂等
     */
    public function cancelExpired(Order $order): bool
    {
        try {
            $this->transitionTo($order, Order::STATUS_CANCELLED, '超时未支付，系统自动取消', 'cancel', operatorId: null, operatorType: OrderLog::OPERATOR_SYSTEM);

            return true;
        } catch (BusinessException) {
            return false; // 状态已变（并发取消/已支付），幂等跳过
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * 受理备货：已支付 → 待发货（进入发货队列）
     *
     * 两条触发路径：
     * - **系统自动**：支付成功后由 PaymentService 立即调用（默认路径，订单不会停留在「已支付」）；
     * - **后台手动**：订单滞留「已支付」（自动流转失败、退款被驳回回流 refunding → paid）时，
     *   运营在订单管理页点「受理备货」兜底。
     *
     * 幂等：已是待发货时直接返回，不重复写流水。
     */
    public function acceptForShipment(
        Order $order,
        ?int $operatorId = null,
        string $operatorType = OrderLog::OPERATOR_SYSTEM,
        ?string $reason = null,
    ): Order {
        if ($order->status === Order::STATUS_PENDING_SHIP) {
            return $order;
        }

        return $this->transitionTo(
            $order,
            Order::STATUS_PENDING_SHIP,
            $reason ?? '订单进入发货队列',
            'order',
            $operatorId,
            $operatorType,
        );
    }

    /**
     * 再次购买（V1.1 E02-D / T-004）
     *
     * 按历史订单行项目批量加入购物车：
     * - 逐行校验商品上架状态、SKU 启用状态与可用库存；
     * - 失效行跳过并返回原因；
     * - 库存不足时按「最大可购数量」加入（不超卖）；
     * - 同 SKU 已在购物车时合并数量。
     *
     * @return array{added:int, skipped:array<int, array<string,mixed>>, cart_count:int}
     */
    public function rebuy(Order $order, int $userId): array
    {
        if ((int) $order->user_id !== $userId) {
            throw BusinessException::notFound('订单不存在');
        }

        $items = $order->items()->get();
        if ($items->isEmpty()) {
            throw BusinessException::badRequest('订单没有可再次购买的商品');
        }

        $stockMap = $this->inventory->getStockMap(
            $items->pluck('sku_id')->filter()->unique()->all()
        );

        $added = 0;
        $skipped = [];

        DB::transaction(function () use ($items, $userId, $stockMap, &$added, &$skipped) {
            foreach ($items as $item) {
                // 行项目未关联 SKU（历史数据）或无 SKU
                if (! $item->sku_id) {
                    $skipped[] = ['product_id' => $item->product_id, 'title' => $item->product_title, 'reason' => '规格已删除'];

                    continue;
                }

                /** @var ProductSku|null $sku */
                $sku = ProductSku::with('product:id,title,status')->find($item->sku_id);

                if (! $sku || ! $sku->product) {
                    $skipped[] = ['product_id' => $item->product_id, 'title' => $item->product_title, 'reason' => '商品已删除'];

                    continue;
                }
                if ((int) $sku->product->status !== 1) {
                    $skipped[] = ['product_id' => $sku->product_id, 'title' => $sku->product->title, 'reason' => '商品已下架'];

                    continue;
                }
                if ((int) $sku->status !== 1) {
                    $skipped[] = ['product_id' => $sku->product_id, 'title' => $sku->product->title, 'reason' => '规格已失效'];

                    continue;
                }

                $stock = (int) ($stockMap[$sku->id] ?? 0);
                if ($stock <= 0) {
                    $skipped[] = ['product_id' => $sku->product_id, 'title' => $sku->product->title, 'reason' => '已售罄'];

                    continue;
                }

                $existing = CartItem::where('user_id', $userId)->where('sku_id', $sku->id)->first();
                $currentQty = (int) ($existing?->quantity ?? 0);

                // 按最大可购数量加入：已购 + 本次 不超过可用库存
                $targetQty = min($currentQty + (int) $item->quantity, $stock);

                if ($targetQty <= $currentQty) {
                    $skipped[] = ['product_id' => $sku->product_id, 'title' => $sku->product->title, 'reason' => '库存不足'];

                    continue;
                }

                if ($existing) {
                    $existing->quantity = $targetQty;
                    $existing->save();
                } else {
                    CartItem::create([
                        'user_id' => $userId,
                        'sku_id' => $sku->id,
                        'quantity' => $targetQty,
                    ]);
                }

                $added++;
            }
        });

        $cartCount = (int) CartItem::where('user_id', $userId)->sum('quantity');

        return ['added' => $added, 'skipped' => $skipped, 'cart_count' => $cartCount];
    }

    /**
     * 用户确认收货（V1.1 E02-A / T-002）
     *
     * shipped → completed，写 completed_at 与流水（operator_type=user）。
     * 幂等：已是 completed 时直接返回，不重复写时间与流水。
     */
    public function confirm(Order $order, int $userId): Order
    {
        if ((int) $order->user_id !== $userId) {
            throw BusinessException::notFound('订单不存在');
        }

        // 幂等：已完成的订单直接返回成功
        if ($order->status === Order::STATUS_COMPLETED) {
            return $order->load('items');
        }

        return $this->transitionTo(
            $order,
            Order::STATUS_COMPLETED,
            '用户确认收货',
            'order',
            $userId,
            OrderLog::OPERATOR_USER,
        )->load('items');
    }

    /**
     * 系统自动确认收货（V1.1 E02-A / T-003）
     *
     * shipped → completed，operator_type=system，并置 auto_completed 标记。
     * 幂等：状态已变（并发手动确认/已取消）时返回 false，不产生副作用。
     */
    public function autoComplete(Order $order): bool
    {
        try {
            DB::transaction(function () use ($order) {
                $done = $this->transitionTo(
                    $order,
                    Order::STATUS_COMPLETED,
                    '系统自动确认收货',
                    'order',
                    null,
                    OrderLog::OPERATOR_SYSTEM,
                );
                $done->auto_completed = true;
                $done->save();
            });

            return true;
        } catch (BusinessException) {
            return false; // 状态已变，幂等跳过
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * 状态机流转（Roadmap P4：禁止非法流转；V1.1 T-001：唯一流水写入点）
     *
     * @param  string  $bizType  库存流水 biz_type
     * @param  string  $operatorType  操作人类型（user/admin/system），写入 order_logs
     */
    public function transitionTo(
        Order $order,
        string $target,
        ?string $reason = null,
        string $bizType = 'order',
        ?int $operatorId = null,
        string $operatorType = OrderLog::OPERATOR_SYSTEM,
    ): Order {
        return DB::transaction(function () use ($order, $target, $reason, $bizType, $operatorId, $operatorType) {
            // 行锁 + 重读，防并发双取消
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            if (! $locked->canTransitTo($target)) {
                throw BusinessException::conflict(sprintf(
                    '订单当前状态「%s」不允许变更为「%s」',
                    Order::STATUS_LABELS[$locked->status] ?? $locked->status,
                    Order::STATUS_LABELS[$target] ?? $target,
                ));
            }

            $fromStatus = $locked->status;

            // 取消待支付订单 → 释放锁定库存 + 关闭未完成支付单
            if ($target === Order::STATUS_CANCELLED && $locked->isPendingPayment()) {
                $items = $locked->items()->get();
                foreach ($items as $item) {
                    if ($item->sku_id) {
                        $this->inventory->release($item->sku_id, $item->quantity, $bizType, $locked->id, $reason);
                    }
                }
                \App\Services\Payment\PaymentService::closePendingForOrder($locked->id);
            }

            $locked->status = $target;

            // 状态对应的时间戳字段
            $timestampField = match ($target) {
                Order::STATUS_PAID => 'paid_at',
                Order::STATUS_SHIPPED => 'shipped_at',
                Order::STATUS_COMPLETED => 'completed_at',
                Order::STATUS_CANCELLED => 'cancelled_at',
                default => null,
            };
            if ($timestampField) {
                $locked->{$timestampField} = now();
            }

            if ($target === Order::STATUS_CANCELLED) {
                $locked->cancel_reason = $reason;
            }

            $locked->save();

            // V1.1 T-001：状态流水落库（唯一写入点）
            $this->orderLog->record(
                order: $locked,
                toStatus: $target,
                operatorType: $operatorType,
                operatorId: $operatorId,
                remark: $reason,
                fromStatus: $fromStatus,
            );

            $this->operationLog->record($operatorId, 'order', 'status_'.$target, 'order', $locked->id, [
                'order_no' => $locked->order_no,
                'from' => $fromStatus,
                'to' => $target,
                'reason' => $reason,
            ]);

            return $locked;
        });
    }

    /**
     * 运费计算：满 free_shipping_threshold 免运费，否则收 freight_default
     */
    private function calcFreight(string $totalAmount): string
    {
        $default = $this->config->getDecimal('order.freight_default', '10.00');
        $threshold = $this->config->getDecimal('order.free_shipping_threshold', '0.00');

        if (bccomp($threshold, '0.00', 2) === 1 && bccomp($totalAmount, $threshold, 2) !== -1) {
            return '0.00';
        }

        return $default;
    }
}
