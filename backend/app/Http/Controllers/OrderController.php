<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Services\Order\OrderService;
use App\Services\Refund\RefundService;
use App\Support\ApiResponse;
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

    public function __construct(private OrderService $orders)
    {
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
            'order_id' => $order->id,
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
            ->with('items')
            ->orderByDesc('id')
            ->paginate(min($data['page_size'] ?? 20, 100), ['*'], 'page', $data['page'] ?? 1);

        $paginator->through(fn (Order $order) => $this->brief($order));

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
    public function rebuy(Request $request, int $id): JsonResponse
    {
        $order = Order::where('user_id', $request->user()->id)->find($id);
        if (! $order) {
            throw BusinessException::notFound('订单不存在');
        }

        $result = $this->orders->rebuy($order, $request->user()->id);

        $message = $result['added'] > 0
            ? sprintf('已加入购物车 %d 件商品', $result['added'])
            : '所选商品均已失效，未能加入购物车';

        return $this->success($result, $message);
    }

    /** 订单详情（仅本人） */
    public function show(Request $request, int $id): JsonResponse
    {
        $order = Order::where('user_id', $request->user()->id)
            ->with('items')
            ->find($id);

        if (! $order) {
            throw BusinessException::notFound('订单不存在');
        }

        return $this->success($this->detail($order));
    }

    /** 订单详情（按订单号，仅本人） */
    public function showByNo(Request $request, string $orderNo): JsonResponse
    {
        $order = Order::where('user_id', $request->user()->id)
            ->where('order_no', $orderNo)
            ->with('items')
            ->first();

        if (! $order) {
            throw BusinessException::notFound('订单不存在');
        }

        return $this->success($this->detail($order));
    }

    /** 取消订单（释放库存） */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:100'],
        ]);

        $order = Order::where('user_id', $request->user()->id)->find($id);
        if (! $order) {
            throw BusinessException::notFound('订单不存在');
        }

        $order = $this->orders->cancel($order, $request->user()->id, $data['reason'] ?? null);

        return $this->success($this->detail($order->load('items')), '订单已取消');
    }

    /** 确认收货（V1.1 E02-A / T-002）：shipped → completed，幂等 */
    public function confirm(Request $request, int $id): JsonResponse
    {
        $order = Order::where('user_id', $request->user()->id)->find($id);
        if (! $order) {
            throw BusinessException::notFound('订单不存在');
        }

        $order = $this->orders->confirm($order, $request->user()->id);

        return $this->success([
            'id' => $order->id,
            'order_no' => $order->order_no,
            'status' => $order->status,
            'status_label' => Order::STATUS_LABELS[$order->status] ?? $order->status,
            'completed_at' => $order->completed_at?->format('Y-m-d H:i:s'),
        ], '确认收货成功');
    }

    /** 提交评价（V1.1 F01 / T-015）：POST /orders/{orderId}/items/{itemId}/review */
    public function review(Request $request, int $orderId, int $itemId, \App\Services\Review\ReviewService $reviews): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'content' => ['nullable', 'string', 'max:500'],
            'images' => ['nullable', 'array', 'max:9'],
            'images.*' => ['string', 'max:512'],
            'is_anonymous' => ['nullable', 'boolean'],
        ]);

        $order = Order::where('user_id', $request->user()->id)->find($orderId);
        if (! $order) {
            throw BusinessException::notFound('订单不存在');
        }

        $item = \App\Models\OrderItem::where('order_id', $order->id)->find($itemId);
        if (! $item) {
            throw BusinessException::notFound('订单行项目不存在');
        }

        $review = $reviews->submit($order, $item, $request->user()->id, $data);

        $message = $review->status === \App\Models\Review::STATUS_PENDING
            ? '评价已提交，审核通过后展示'
            : '评价成功';

        return $this->success([
            'id' => $review->id,
            'rating' => $review->rating,
            'status' => $review->status,
        ], $message);
    }

    /** 申请退款（API 文档 6.5 / Roadmap P5） */
    public function refund(Request $request, int $id, RefundService $refunds): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:200'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $order = Order::where('user_id', $request->user()->id)->find($id);
        if (! $order) {
            throw BusinessException::notFound('订单不存在');
        }

        $refund = $refunds->apply(
            order: $order,
            userId: $request->user()->id,
            reason: $data['reason'] ?? null,
            amount: $data['amount'] ?? null,
        );

        return $this->success([
            'refund_id' => $refund->id,
            'refund_no' => $refund->refund_no,
            'amount' => (string) $refund->amount,
            'status' => $refund->status,
        ], '退款申请已提交，等待审核');
    }

    /** 列表项简要结构 */
    private function brief(Order $order): array
    {
        $items = $order->items->map(fn ($item) => [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'sku_id' => $item->sku_id,
            'product_title' => $item->product_title,
            'sku_specs' => $item->sku_specs ?? [],
            'sku_image' => $item->sku_image,
            'price' => $item->price,
            'quantity' => $item->quantity,
            'total_amount' => $item->total_amount,
            // V1.1 F06 / T-035：行级优惠分摊（退款按行实付计算，T-036 使用）
            'coupon_share' => $item->coupon_share,
            'promotion_share' => $item->promotion_share,
            'payable_amount' => number_format($item->payableAmount(), 2, '.', ''),
        ])->all();

        // V1.1 T-004：前 3 个商品缩略预览（避免列表页传输整单明细）
        $preview = array_slice(array_map(fn ($i) => [
            'product_id' => $i['product_id'],
            'product_title' => $i['product_title'],
            'sku_image' => $i['sku_image'],
            'quantity' => $i['quantity'],
        ], $items), 0, 3);

        return [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'status' => $order->status,
            'status_label' => Order::STATUS_LABELS[$order->status] ?? $order->status,
            'total_amount' => $order->total_amount,
            'freight_amount' => $order->freight_amount,
            'pay_amount' => $order->pay_amount,
            // V1.1 F06 / T-035：优惠汇总与分摊快照（历史无券订单为 0 / null，前端兼容）
            'coupon_id' => $order->coupon_id,
            'discount_amount' => $order->discount_amount ?? '0.00',
            'promotion_discount' => $order->promotion_discount ?? '0.00',
            'amount_details' => $order->amount_details,
            'item_count' => (int) $order->items->sum('quantity'),
            'items_preview' => $preview,
            'items' => $items,
            'actions' => $order->actions(),
            'created_at' => $order->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /** 详情结构 */
    private function detail(Order $order): array
    {
        $refunds = $order->refunds()->orderByDesc('id')->get()->map(fn ($r) => [
            'refund_no' => $r->refund_no,
            'amount' => (string) $r->amount,
            'reason' => $r->reason,
            'status' => $r->status,
            'admin_remark' => $r->admin_remark,
            'created_at' => $r->created_at?->format('Y-m-d H:i:s'),
        ])->all();

        // V1.1 T-001/T-005：状态流水，供前端时间轴渲染
        $logs = app(\App\Services\Order\OrderLogService::class)->timeline($order);

        // V1.1 F01 / T-016：行项目评价状态（用于「评价 / 修改评价」按钮）
        $reviews = \App\Models\Review::where('order_id', $order->id)->get()->keyBy('order_item_id');
        $detail = $this->brief($order);
        $detail['items'] = array_map(function ($item) use ($reviews) {
            $review = $reviews->get($item['id']);
            $item['review'] = $review ? [
                'id' => $review->id,
                'rating' => $review->rating,
                'content' => $review->content,
                'status' => $review->status,
                'can_edit' => $review->canEdit(),
            ] : null;

            return $item;
        }, $detail['items']);

        return $detail + [
            'remark' => $order->remark,
            'address_snapshot' => $order->address_snapshot,
            'cancel_reason' => $order->cancel_reason,
            'refunds' => $refunds,
            'logs' => $logs,
            'paid_at' => $order->paid_at?->format('Y-m-d H:i:s'),
            'shipped_at' => $order->shipped_at?->format('Y-m-d H:i:s'),
            'completed_at' => $order->completed_at?->format('Y-m-d H:i:s'),
            'cancelled_at' => $order->cancelled_at?->format('Y-m-d H:i:s'),
        ];
    }
}
