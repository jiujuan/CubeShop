<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Services\Order\OrderService;
use App\Services\Refund\RefundService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 订单模块（API 文档 6 / Roadmap P4）
 */
class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(private OrderService $orders)
    {
    }

    /** 创建订单（结算） */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address_id' => ['required', 'integer'],
            'cart_item_ids' => ['nullable', 'array'],
            'cart_item_ids.*' => ['integer'],
            'remark' => ['nullable', 'string', 'max:200'],
        ]);

        $order = $this->orders->createFromCart(
            userId: $request->user()->id,
            addressId: (int) $data['address_id'],
            cartItemIds: $data['cart_item_ids'] ?? null,
            remark: $data['remark'] ?? null,
        );

        return $this->success([
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'total_amount' => $order->total_amount,
            'freight_amount' => $order->freight_amount,
            'pay_amount' => $order->pay_amount,
            'status' => $order->status,
        ], '下单成功');
    }

    /** 订单列表（按状态筛选，仅本人） */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', array_keys(Order::STATUS_LABELS))],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = Order::query()
            ->where('user_id', $request->user()->id)
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->with('items')
            ->orderByDesc('id')
            ->paginate(min($data['page_size'] ?? 20, 100), ['*'], 'page', $data['page'] ?? 1);

        $paginator->through(fn (Order $order) => $this->brief($order));

        return $this->paginated($paginator);
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
        return [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'status' => $order->status,
            'status_label' => Order::STATUS_LABELS[$order->status] ?? $order->status,
            'total_amount' => $order->total_amount,
            'freight_amount' => $order->freight_amount,
            'pay_amount' => $order->pay_amount,
            'item_count' => (int) $order->items->sum('quantity'),
            'items' => $order->items->map(fn ($item) => [
                'product_title' => $item->product_title,
                'sku_specs' => $item->sku_specs ?? [],
                'sku_image' => $item->sku_image,
                'price' => $item->price,
                'quantity' => $item->quantity,
                'total_amount' => $item->total_amount,
            ])->all(),
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

        return $this->brief($order) + [
            'remark' => $order->remark,
            'address_snapshot' => $order->address_snapshot,
            'cancel_reason' => $order->cancel_reason,
            'refunds' => $refunds,
            'paid_at' => $order->paid_at?->format('Y-m-d H:i:s'),
            'shipped_at' => $order->shipped_at?->format('Y-m-d H:i:s'),
            'completed_at' => $order->completed_at?->format('Y-m-d H:i:s'),
            'cancelled_at' => $order->cancelled_at?->format('Y-m-d H:i:s'),
        ];
    }
}
