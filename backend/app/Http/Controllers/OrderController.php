<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\ProductSku;
use App\Models\Refund;
use App\Models\UserAddress;
use App\Services\Order\OrderService;
use App\Services\Refund\RefundService;
use App\Services\Shipping\FreightService;
use App\Support\ApiResponse;
use App\Support\PublicId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 订单模块（API 文档 6 / Roadmap P4）
 */
class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(private OrderService $orders, private FreightService $freight)
    {
    }

    /**
     * 解析订单对外标识（P2-11 终态）：对外只暴露 public_id（ULID），兼容历史 int 主键；
     * 解析不出一律 404，且始终带 user_id 过滤，避免 public_id 替换过程中引入越权。
     */
    private function ownOrder(Request $request, string $id): Order
    {
        $orderId = PublicId::resolve(PublicId::SCOPE_ORDER, $id);
        if ($orderId === null) {
            throw BusinessException::notFound('订单不存在');
        }

        $order = Order::where('user_id', $request->user()->id)->find($orderId);
        if (! $order) {
            throw BusinessException::notFound('订单不存在');
        }

        return $order;
    }

    /** 创建订单（结算；V1.1 F06 / T-035 支持 user_coupon_id / promotion_id） */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address_id' => ['required', 'integer'],
            'cart_item_ids' => ['nullable', 'array'],
            'cart_item_ids.*' => ['integer'],
            'remark' => ['nullable', 'string', 'max:200'],
            // 不传 = 不用券（与 V1.0 行为完全一致）；传则必须是本人未使用的券
            'user_coupon_id' => ['nullable', 'integer', 'min:1'],
            // 不传 = 自动匹配当前最优满减活动
            'promotion_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $order = $this->orders->createFromCart(
            userId: $request->user()->id,
            addressId: (int) $data['address_id'],
            cartItemIds: $data['cart_item_ids'] ?? null,
            remark: $data['remark'] ?? null,
            userCouponId: isset($data['user_coupon_id']) ? (int) $data['user_coupon_id'] : null,
            promotionId: isset($data['promotion_id']) ? (int) $data['promotion_id'] : null,
        );

        return $this->success([
            'order_id' => $order->public_id,
            'order_no' => $order->order_no,
            'total_amount' => $order->total_amount,
            'discount_amount' => $order->discount_amount,
            'promotion_discount' => $order->promotion_discount,
            'coupon_id' => $order->coupon_id,
            'freight_amount' => $order->freight_amount,
            'pay_amount' => $order->pay_amount,
            'amount_details' => $order->amount_details,
            'status' => $order->status,
        ], '下单成功');
    }

    /**
     * 运费实时预览（Stage 2 / T-053）：结算页选地址后调用，与下单同一套引擎（FreightService）。
     *
     * 入参 items [{sku_id, quantity}]；address_id 可选——传了才能按省 code 计算 region 模板。
     * not_support 不抛错（返回标记），由前端禁用提交并提示；下单时后端仍会拒单兜底。
     *
     * Stage 3 起同时挂公开路由 POST /api/freight/estimate（详情页/购物车预估，游客可用；
     * 未登录或未传 address_id 时 region 模板无法按省匹配 → 走模板 default 或返回 not_support 标记）。
     */
    public function freightPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            // P2-11：web 出口的 sku_id 是 public_id（ULID 字符串），兼容历史 int 主键
            'items.*.sku_id' => ['required'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'address_id' => ['nullable', 'integer'],
        ]);

        // 按 sku 归并数量（前端可能传重复行）；public_id / int 双形态解析
        $quantities = [];
        foreach ($data['items'] as $item) {
            $skuId = PublicId::resolve(PublicId::SCOPE_SKU, $item['sku_id']);
            if ($skuId === null) {
                continue;
            }
            $quantities[$skuId] = ($quantities[$skuId] ?? 0) + (int) $item['quantity'];
        }

        if ($quantities === []) {
            throw BusinessException::badRequest('商品不存在或已失效');
        }

        $skus = ProductSku::query()
            ->whereIn('id', array_keys($quantities))
            ->with('product:id,weight,freight_template_id,status')
            ->get();

        if ($skus->isEmpty()) {
            throw BusinessException::badRequest('商品不存在或已失效');
        }

        $lines = [];
        foreach ($skus as $sku) {
            if (! $sku->product || (int) $sku->product->status !== 1 || (int) $sku->status !== 1) {
                continue; // 失效行不参与运费预估（结算页会另行拦截）
            }
            $lines[] = [
                'template_id' => $sku->product->freight_template_id !== null
                    ? (int) $sku->product->freight_template_id
                    : null,
                'weight_g' => (int) ($sku->product->weight ?? 0),
                'quantity' => $quantities[$sku->id],
                'price' => (string) $sku->price,
            ];
        }

        if ($lines === []) {
            throw BusinessException::badRequest('商品不存在或已失效');
        }

        // region 模式需要省 code：user_addresses.province 存省名，由 FreightService 换算。
        // 公开路由（游客）没有 user，跳过地址换算 → region 无 default 时返回 not_support 标记
        $provinceName = null;
        $user = $request->user();
        if ($user !== null && ! empty($data['address_id'])) {
            $address = UserAddress::where('user_id', $user->id)->find((int) $data['address_id']);
            if ($address) {
                $provinceName = (string) $address->province;
            }
        }

        return $this->success($this->freight->calculate($lines, $provinceName)->toArray());
    }

    /** 订单列表（V1.1 T-004：状态分组 Tab + 关键词/时间检索；仅本人） */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', array_keys(Order::STATUS_LABELS))],
            'tab' => ['nullable', 'string', 'in:'.implode(',', array_keys(Order::TAB_STATUS_MAP))],
            'keyword' => ['nullable', 'string', 'max:50'],
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $userId = $request->user()->id;

        $query = Order::query()->where('user_id', $userId);

        // 兼容 V1.0 的 status 参数；未传 status 时按 tab 过滤
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        } elseif (! empty($data['tab']) && $data['tab'] !== 'all') {
            $statuses = Order::TAB_STATUS_MAP[$data['tab']] ?? null;
            if ($statuses !== null) {
                $query->whereIn('status', $statuses);
            }
        }

        if (! empty($data['keyword'])) {
            $this->applyKeyword($query, $data['keyword']);
        }

        if (! empty($data['start'])) {
            $query->where('created_at', '>=', Carbon::parse($data['start'])->startOfDay());
        }
        if (! empty($data['end'])) {
            // 仅传日期（如 2026-09-15）时按当日 23:59:59 兜底，避免漏掉当天订单
            $end = Carbon::parse($data['end']);
            if (strlen(trim((string) $data['end'])) <= 10) {
                $end->endOfDay();
            }
            $query->where('created_at', '<=', $end);
        }

        $paginator = $query
            ->with('items.product', 'items.sku', 'items.review')
            ->orderByDesc('id')
            ->paginate(min($data['page_size'] ?? 20, 100), ['*'], 'page', $data['page'] ?? 1);

        $paginator->through(fn (Order $order) => new OrderResource($order));

        return $this->paginated($paginator);
    }

    /**
     * 关键词检索：订单号 或 收货人姓名/手机号
     *
     * address_snapshot 是 JSON 列，直接对原文本 LIKE 会因 SQLite 的 unicode 转义
     * （\u4e2d\u6587）而匹配不到中文，因此按驱动取 JSON 字段值再比较：
     * - PG：`address_snapshot->>'contact_name' ILIKE ?`
     * - SQLite：`json_extract(address_snapshot, '$.contact_name') LIKE ?`
     */
    private function applyKeyword($query, string $keyword): void
    {
        $like = '%'.$keyword.'%';
        $isPg = DB::connection()->getDriverName() === 'pgsql';

        $query->where(function ($q) use ($like, $isPg) {
            $q->where('order_no', 'like', $like);

            if ($isPg) {
                $q->orWhereRaw("address_snapshot->>'contact_name' ILIKE ?", [$like])
                    ->orWhereRaw("address_snapshot->>'contact_phone' ILIKE ?", [$like]);
            } else {
                $q->orWhereRaw("json_extract(address_snapshot, '$.contact_name') LIKE ?", [$like])
                    ->orWhereRaw("json_extract(address_snapshot, '$.contact_phone') LIKE ?", [$like]);
            }
        });
    }

    /** 再次购买（V1.1 E02-D / T-004）：按历史订单行项目加入购物车 */
    public function rebuy(Request $request, string $id): JsonResponse
    {
        $order = $this->ownOrder($request, $id);

        $result = $this->orders->rebuy($order, $request->user()->id);

        $message = $result['added'] > 0
            ? sprintf('已加入购物车 %d 件商品', $result['added'])
            : '所选商品均已失效，未能加入购物车';

        return $this->success($result, $message);
    }

    /** 订单详情（仅本人） */
    public function show(Request $request, string $id): JsonResponse
    {
        $order = $this->ownOrder($request, $id);
        $order->load('items.product', 'items.sku', 'items.review', 'refunds', 'logs');

        return $this->success(new OrderResource($order));
    }

    /**
     * 订单物流信息（仅本人，V1.1 T-046）
     * GET /orders/{id}/shipping
     */
    public function shipping(Request $request, string $id): JsonResponse
    {
        $order = $this->ownOrder($request, $id);

        $shipping = $order->shipping()->first();

        if (! $shipping) {
            return $this->success(null);
        }

        $traces = $shipping->traces()->orderByDesc('occurred_at')->orderByDesc('id')->get();

        return $this->success([
            'express_company' => $shipping->company_name,
            'tracking_no' => $shipping->tracking_no,
            'trace_status' => $shipping->trace_status,
            'shipped_at' => $shipping->shipped_at?->toDateTimeString(),
            'delivered_at' => $shipping->delivered_at?->toDateTimeString(),
            'has_trace' => $traces->isNotEmpty(),
            'traces' => $traces->map(fn ($t) => [
                'context' => $t->context,
                'occurred_at' => $t->occurred_at->toDateTimeString(),
            ])->all(),
        ]);
    }

    /** 订单详情（按订单号，仅本人） */
    public function showByNo(Request $request, string $orderNo): JsonResponse
    {
        $order = Order::where('user_id', $request->user()->id)
            ->where('order_no', $orderNo)
            ->with('items.product', 'items.sku', 'items.review', 'refunds', 'logs')
            ->first();

        if (! $order) {
            throw BusinessException::notFound('订单不存在');
        }

        return $this->success(new OrderResource($order));
    }

    /** 取消订单（释放库存） */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:100'],
        ]);

        $order = $this->ownOrder($request, $id);

        $order = $this->orders->cancel($order, $request->user()->id, $data['reason'] ?? null);

        $order->load('items.product', 'items.sku', 'items.review', 'refunds', 'logs');

        return $this->success(new OrderResource($order), '订单已取消');
    }

    /** 确认收货（V1.1 E02-A / T-002）：shipped → completed，幂等 */
    public function confirm(Request $request, string $id): JsonResponse
    {
        $order = $this->ownOrder($request, $id);

        $order = $this->orders->confirm($order, $request->user()->id);

        return $this->success([
            'id' => $order->public_id,
            'order_no' => $order->order_no,
            'status' => $order->status,
            'status_label' => Order::STATUS_LABELS[$order->status] ?? $order->status,
            'completed_at' => $order->completed_at?->format('Y-m-d H:i:s'),
        ], '确认收货成功');
    }

    /** 提交评价（V1.1 F01 / T-015）：POST /orders/{orderId}/items/{itemId}/review */
    public function review(Request $request, string $orderId, string $itemId, \App\Services\Review\ReviewService $reviews): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'content' => ['nullable', 'string', 'max:500'],
            'images' => ['nullable', 'array', 'max:9'],
            'images.*' => ['string', 'max:512'],
            'is_anonymous' => ['nullable', 'boolean'],
        ]);

        $order = $this->ownOrder($request, $orderId);

        $itemId = PublicId::resolve(PublicId::SCOPE_ORDER_ITEM, $itemId);
        $item = $itemId === null
            ? null
            : \App\Models\OrderItem::where('order_id', $order->id)->find($itemId);
        if (! $item) {
            throw BusinessException::notFound('订单行项目不存在');
        }

        $review = $reviews->submit($order, $item, $request->user()->id, $data);

        $message = $review->status === \App\Models\Review::STATUS_PENDING
            ? '评价已提交，审核通过后展示'
            : '评价成功';

        return $this->success([
            'id' => $review->public_id,
            'rating' => $review->rating,
            'status' => $review->status,
        ], $message);
    }

    /** 申请退款（API 文档 6.5 / Roadmap P5） */
    public function refund(Request $request, string $id, RefundService $refunds): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:200'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'type' => ['nullable', 'string', 'in:refund,return_refund'],
            'return_details' => ['nullable', 'array'],
            'return_details.*.sku_id' => ['required'],
            'return_details.*.quantity' => ['required', 'integer', 'min:1'],
            'return_details.*.product_title' => ['nullable', 'string'],
            'return_details.*.sku_specs' => ['nullable', 'array'],
            'return_tracking_no' => ['nullable', 'string', 'max:64'],
            'return_express_company' => ['nullable', 'string', 'max:64'],
        ]);

        $order = $this->ownOrder($request, $id);

        $refund = $refunds->apply(
            order: $order,
            userId: $request->user()->id,
            reason: $data['reason'] ?? null,
            amount: $data['amount'] ?? null,
            opts: [
                'type' => $data['type'] ?? Refund::TYPE_REFUND,
                'return_details' => $data['return_details'] ?? null,
                'return_tracking_no' => $data['return_tracking_no'] ?? null,
                'return_express_company' => $data['return_express_company'] ?? null,
            ],
        );

        return $this->success([
            'refund_id' => $refund->public_id,
            'refund_no' => $refund->refund_no,
            'type' => $refund->type,
            'amount' => (string) $refund->amount,
            'status' => $refund->status,
            'return_status' => $refund->return_status,
        ], '退款申请已提交，等待审核');
    }
}
