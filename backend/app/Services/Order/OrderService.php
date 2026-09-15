<?php

namespace App\Services\Order;

use App\Exceptions\BusinessException;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
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

            return $order;
        });

        $this->operationLog->record($userId, 'order', 'create', 'order', $order->id, [
            'order_no' => $order->order_no,
            'pay_amount' => $order->pay_amount,
            'items' => $cartItems->count(),
        ]);

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

        return $this->transitionTo($order, Order::STATUS_CANCELLED, $reason ?? '用户主动取消', 'cancel');
    }

    /**
     * 超时自动取消（Scheduler 命令调用）：释放库存，幂等
     */
    public function cancelExpired(Order $order): bool
    {
        try {
            $this->transitionTo($order, Order::STATUS_CANCELLED, '超时未支付，系统自动取消', 'cancel', operatorId: null);

            return true;
        } catch (BusinessException) {
            return false; // 状态已变（并发取消/已支付），幂等跳过
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * 状态机流转（Roadmap P4：禁止非法流转）
     *
     * @param  string  $bizType  库存流水 biz_type
     */
    public function transitionTo(Order $order, string $target, ?string $reason = null, string $bizType = 'order', ?int $operatorId = null): Order
    {
        return DB::transaction(function () use ($order, $target, $reason, $bizType, $operatorId) {
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

            // 取消待支付订单 → 释放锁定库存
            if ($target === Order::STATUS_CANCELLED && $locked->isPendingPayment()) {
                $items = $locked->items()->get();
                foreach ($items as $item) {
                    if ($item->sku_id) {
                        $this->inventory->release($item->sku_id, $item->quantity, $bizType, $locked->id, $reason);
                    }
                }
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

            $this->operationLog->record($operatorId, 'order', 'status_'.$target, 'order', $locked->id, [
                'order_no' => $locked->order_no,
                'from' => $order->status,
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
