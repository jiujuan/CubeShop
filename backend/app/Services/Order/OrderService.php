<?php

namespace App\Services\Order;

use App\Exceptions\BusinessException;
use App\Events\OrderAcceptedForShipment;
use App\Events\OrderShipped;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLog;
use App\Models\ProductSku;
use App\Models\Shipping;
use App\Models\SysOperationLog;
use App\Models\UserAddress;
use App\Models\UserCoupon;
use App\Services\Common\ConfigService;
use App\Services\Common\NoGeneratorService;
use App\Services\Common\OperationLogService;
use App\Services\Inventory\InventoryService;
use App\Services\Marketing\CouponService;
use App\Services\Marketing\PromotionService;
use App\Services\Shipping\FreightService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 订单服务（Roadmap P4：订单与结算闭环；V1.1 F06 / T-035：优惠金额链路）
 *
 * 职责：
 * - 创建订单：地址快照 + 商品快照 + 运费计算 + 库存锁定（事务）
 * - 优惠：券校验 → 满减匹配 → 金额分摊（`amount_details`）→ 券核销锁定
 * - 状态机校验：禁止非法流转
 * - 取消订单：释放库存 + 返还券 + 记录原因
 * - 超时取消：供 Scheduler 命令调用（order.timeout_minutes）
 *
 * ── 券核销时机（T-035 评审口径，T-036 据此做退款回退）────────────────
 * 下单事务内即把 `user_coupons` 由 `unused` 原子置为 `used` 并回填 `used_order_id`，
 * 同时 `coupons.used_count + 1`。理由：
 *  ① 一张券**同一时刻只能被一个订单占用**，条件更新天然防并发重复使用（T-041 场景 C）；
 *  ② 未支付订单取消/超时时券**原样返还**（`releaseCoupon`，见 T-036 同源逻辑）。
 * 即 `used_count` 语义 = 「已被订单占用（含待支付）」，取消即回退，不产生泄漏。
 */
class OrderService
{
    public function __construct(
        private InventoryService $inventory,
        private NoGeneratorService $noGenerator,
        private ConfigService $config,
        private OperationLogService $operationLog,
        private OrderLogService $orderLog,
        private CouponService $coupons,
        private PromotionService $promotions,
        private FreightService $freight,
    ) {
    }

    /**
     * 从购物车创建订单（API 文档 6.1；V1.1 F06 / T-035 支持券与满减）
     *
     * @param  int  $userId  下单用户
     * @param  int  $addressId  收货地址
     * @param  array<int, int>|null  $cartItemIds  要结算的购物车项；null = 全部有效项
     * @param  string|null  $remark  用户备注
     * @param  int|null  $userCouponId  用户券 id（不传 = 不用券，行为与 V1.0 一致）
     * @param  int|null  $promotionId  满减活动 id（不传 = 自动匹配最优满减）
     * @return Order 待支付订单
     *
     * @throws BusinessException 地址无效 / 无有效商品 / 库存不足 / 券不可用
     */
    public function createFromCart(
        int $userId,
        int $addressId,
        ?array $cartItemIds,
        ?string $remark,
        ?int $userCouponId = null,
        ?int $promotionId = null,
    ): Order {
        // 1. 地址校验（本人地址）
        $address = UserAddress::where('user_id', $userId)->find($addressId);
        if (! $address) {
            throw BusinessException::notFound('收货地址不存在');
        }

        // 2. 取购物车项（含分类 id：券/满减按分类命中时需要）
        $query = CartItem::where('user_id', $userId)
            ->with(['sku:id,product_id,specs,price,status', 'sku.product:id,category_id,title,main_image,status,weight,freight_template_id']);
        if ($cartItemIds !== null && $cartItemIds !== []) {
            $query->whereIn('id', $cartItemIds);
        }
        $cartItems = $query->orderBy('id')->get()->values();

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

        // 4. 金额快照（Σ price×qty，作为券/满减的上下文基础）+ 运费
        $itemRows = [];
        foreach ($cartItems as $item) {
            $itemRows[] = [
                'product_id' => $item->sku->product_id,
                'sku_id' => $item->sku_id,
                'category_id' => $item->sku->product->category_id,
                'price' => (float) $item->sku->price,
                'quantity' => (int) $item->quantity,
            ];
        }
        // 4b. 运费（Stage 2：FreightCalculator 引擎——按商品绑定模板分组计费，组间取 max；
        //     无模板时走全局默认规则，与旧「固定运费 + 满额包邮」口径完全一致）
        $freight = $this->freight->calculate(
            lines: $cartItems->map(fn (CartItem $item) => [
                'template_id' => $item->sku->product->freight_template_id !== null
                    ? (int) $item->sku->product->freight_template_id
                    : null,
                'weight_g' => (int) ($item->sku->product->weight ?? 0),
                'quantity' => (int) $item->quantity,
                'price' => (string) $item->sku->price,
            ])->all(),
            provinceName: (string) $address->province,
        );
        if ($freight->notSupport) {
            throw BusinessException::conflict('该地区暂不支持配送，请更换收货地址或联系客服');
        }
        $freightAmount = $freight->freightAmount;

        // 5. 优惠解析：券校验（先券）→ 满减匹配（不传则自动最优）→ 分摊计算
        $ctx = $this->coupons->buildContext($itemRows, $freightAmount);

        $userCoupon = null;
        if ($userCouponId !== null) {
            $userCoupon = UserCoupon::with('coupon')
                ->where('user_id', $userId)
                ->find($userCouponId);
            if (! $userCoupon) {
                // 他人券与不存在的券统一按「不存在」处理，避免枚举他人券 id
                throw BusinessException::notFound('优惠券不存在');
            }
            // 状态/过期/门槛/范围不满足 → 409 + 明确原因
            $this->coupons->validateUse($userCoupon, $ctx);
        }

        $promotion = $promotionId !== null
            ? $this->promotions->resolveUsable($promotionId, $ctx)
            : $this->promotions->match($ctx);

        // amount_details 内含商品总额、券/满减优惠、运费、应付与各行分摊（不变量自检）
        $details = $this->coupons->priceOrder($ctx, $userCoupon, $promotion);

        // 6. 事务：锁定券 → 锁库存 → 建订单（含金额字段）→ 快照明细（含分摊）→ 清购物车
        $order = DB::transaction(function () use (
            $userId, $address, $cartItems, $details, $userCoupon, $remark
        ) {
            // 6.1 原子锁定券（先券后库存）：条件更新保证一券一单，占用失败立即回滚
            if ($userCoupon !== null) {
                $claimed = DB::table('user_coupons')
                    ->where('id', $userCoupon->id)
                    ->where('user_id', $userId)
                    ->where('status', UserCoupon::STATUS_UNUSED)
                    ->where('expire_at', '>=', now())
                    ->update([
                        'status' => UserCoupon::STATUS_USED,
                        'used_at' => now(),
                        'updated_at' => now(),
                    ]);

                if ($claimed === 0) {
                    throw BusinessException::conflict('优惠券已被使用或已过期，请重新选择');
                }

                DB::table('coupons')
                    ->where('id', $userCoupon->coupon_id)
                    ->update(['used_count' => DB::raw('used_count + 1'), 'updated_at' => now()]);
            }

            // 6.2 锁库存
            foreach ($cartItems as $item) {
                $this->inventory->lock($item->sku_id, $item->quantity, 'order', null, '下单锁定库存');
            }

            // 6.3 建订单（金额字段直接取分摊结果，杜绝二次计算口径漂移）
            /** @var Order $order */
            $order = Order::create([
                'order_no' => $this->noGenerator->generateOrderNo(),
                'user_id' => $userId,
                'status' => Order::STATUS_PENDING_PAYMENT,
                'total_amount' => $details['goods_amount'],
                'freight_amount' => $details['freight_amount'],
                'pay_amount' => $details['pay_amount'],
                'coupon_id' => $userCoupon?->coupon_id,
                'discount_amount' => $details['discount_amount'],
                'promotion_discount' => $details['promotion_discount'],
                'amount_details' => $details,
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

            // 6.4 行项目快照 + 分摊（index 与 amount_details.lines 一一对应）
            foreach ($cartItems as $idx => $item) {
                $line = $details['lines'][$idx] ?? null;

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
                    'coupon_share' => $line['coupon_share'] ?? '0.00',
                    'promotion_share' => $line['promotion_share'] ?? '0.00',
                ]);
            }

            // 6.5 回填券的核销订单，便于取消/退款按单返还
            if ($userCoupon !== null) {
                DB::table('user_coupons')
                    ->where('id', $userCoupon->id)
                    ->update(['used_order_id' => $order->id, 'updated_at' => now()]);
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
            'coupon_id' => $order->coupon_id,
            'promotion_discount' => $order->promotion_discount,
            'discount_amount' => $order->discount_amount,
        ], SysOperationLog::ACTOR_CUSTOMER);

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

        $accepted = $this->transitionTo(
            $order,
            Order::STATUS_PENDING_SHIP,
            $reason ?? '订单进入发货队列',
            'order',
            $operatorId,
            $operatorType,
        );

        // WMS 履约挂钩（P1 / Step 5）：transitionTo 内部事务已提交，此处派发天然是
        // afterCommit 语义——监听器建单/派发 Job 不会读到未提交数据，也不阻塞订单流转。
        OrderAcceptedForShipment::dispatch($accepted);

        return $accepted;
    }

    /**
     * 发货（V1.1 T-043，E03）：pending_ship → shipped，写入物流记录与订单冗余双号
     *
     * - `paid → shipped` 状态机流转复用 transitionTo（重复发货/非法状态由其拒绝）；
     * - 同一事务内写 `shippings`（公司名称快照）并回填 `orders.express_company`/`tracking_no`；
     * - 运单号唯一约束（同公司组合唯一）由表级索引兜底，业务层先行校验给友好错误。
     */
    public function shipForShipment(
        Order $order,
        string $companyCode,
        string $companyName,
        string $trackingNo,
        ?string $reason = null,
        ?int $operatorId = null,
        string $operatorType = OrderLog::OPERATOR_ADMIN,
    ): Order {
        $shipped = DB::transaction(function () use ($order, $companyCode, $companyName, $trackingNo, $reason, $operatorId, $operatorType) {
            $result = $this->transitionTo(
                $order,
                Order::STATUS_SHIPPED,
                $reason ?? '商家已发货',
                'ship',
                $operatorId,
                $operatorType,
            );

            Shipping::create([
                'order_id' => $result->id,
                'company_code' => $companyCode,
                'company_name' => $companyName,
                'tracking_no' => $trackingNo,
                'trace_status' => Shipping::TRACE_PENDING,
                'shipped_at' => $result->shipped_at ?? now(),
            ]);

            // 冗余双号（列表/导出直接用）
            $result->express_company = $companyName;
            $result->tracking_no = $trackingNo;
            $result->save();

            return $result;
        });

        // 事务提交后发通知（单笔发货与批量发货共用，T-044）
        OrderShipped::dispatch($shipped);

        return $shipped;
    }

    /**
     * 再次购买（V1.1 E02-D / T-004）
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

            // 取消待支付订单 → 释放锁定库存 + 返还券 + 关闭未完成支付单
            if ($target === Order::STATUS_CANCELLED && $locked->isPendingPayment()) {
                $items = $locked->items()->get();
                foreach ($items as $item) {
                    if ($item->sku_id) {
                        $this->inventory->release($item->sku_id, $item->quantity, $bizType, $locked->id, $reason);
                    }
                }

                // V1.1 T-035：未支付即取消，券原样返还（T-036 复用同一返还逻辑处理退款场景）
                $this->releaseCoupon($locked);

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
            ], $this->actorTypeOfOperator($operatorType));

            return $locked;
        });
    }

    /**
     * `order_logs.operator_type` → `sys_operation_log.actor_type` 映射
     *
     * `sys_operation_log.user_id` 是混合语义列（管理员与买家共写），必须显式声明归属，
     * 否则买家操作（下单/取消/确认收货）会被默认记成管理员，后台审计显示错误。
     * 系统动作（支付回调、超时取消）归入 admin 桶，与既有默认行为一致。
     */
    private function actorTypeOfOperator(string $operatorType): string
    {
        return $operatorType === OrderLog::OPERATOR_USER
            ? SysOperationLog::ACTOR_CUSTOMER
            : SysOperationLog::ACTOR_ADMIN;
    }

    /**
     * 返还订单占用的优惠券（V1.1 T-035，T-036 退款复用）
     *
     * 规则（评审口径，写入 Backend_Design §3.4）：
     *  - 仅返还本单核销中（`status=used` 且 `used_order_id=本单`）的券，幂等；
     *  - 券在占用期间若已过 `expire_at`，则返还为 `expired`（不复活已过期券）；
     *  - 同步回退 `coupons.used_count`（`where used_count > 0` 防负数）。
     */
    public function releaseCoupon(Order $order): void
    {
        if (! $order->coupon_id) {
            return;
        }

        /** @var UserCoupon|null $uc */
        $uc = UserCoupon::where('used_order_id', $order->id)
            ->where('status', UserCoupon::STATUS_USED)
            ->first();

        if (! $uc) {
            return;
        }

        $backStatus = $uc->isExpired() ? UserCoupon::STATUS_EXPIRED : UserCoupon::STATUS_UNUSED;

        DB::table('user_coupons')
            ->where('id', $uc->id)
            ->update([
                'status' => $backStatus,
                'used_order_id' => null,
                'used_at' => null,
                'updated_at' => now(),
            ]);

        DB::table('coupons')
            ->where('id', $order->coupon_id)
            ->where('used_count', '>', 0)
            ->update(['used_count' => DB::raw('used_count - 1'), 'updated_at' => now()]);
    }
}
