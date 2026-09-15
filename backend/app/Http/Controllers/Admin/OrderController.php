<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Order\OrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 后台订单管理（API 文档 8.3 / Roadmap P6）
 */
class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OrderService $orders,
    ) {}

    /**
     * 订单列表（多条件筛选，权限 order.view）
     * GET /admin/orders?order_no=&user_id=&status=&start_time=&end_time=&page=&page_size=
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_no' => ['nullable', 'string', 'max:32'],
            'user_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_keys(Order::STATUS_LABELS))],
            'start_time' => ['nullable', 'date'],
            'end_time' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->buildQuery($data)
            ->orderByDesc('id')
            ->paginate(min($data['page_size'] ?? 20, 100), ['*'], 'page', $data['page'] ?? 1);

        $paginator->through(fn (Order $order) => $this->brief($order));

        return $this->paginated($paginator);
    }

    /**
     * 订单详情（权限 order.view）
     * GET /admin/orders/{id}
     */
    public function show(int $id): JsonResponse
    {
        $order = Order::query()->with('items')->find($id);

        if (! $order) {
            throw BusinessException::notFound('订单不存在');
        }

        return $this->success($this->detail($order));
    }

    /**
     * 发货（paid → shipped，权限 order.ship）
     * POST /admin/orders/{id}/ship  body: { remark? }
     */
    public function ship(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'remark' => ['nullable', 'string', 'max:200'],
        ]);

        $order = Order::query()->find($id);
        if (! $order) {
            throw BusinessException::notFound('订单不存在');
        }

        $order = $this->orders->transitionTo(
            $order,
            Order::STATUS_SHIPPED,
            $data['remark'] ?? '商家已发货',
            'ship',
            $request->user()->id,
            \App\Models\OrderLog::OPERATOR_ADMIN,
        );

        // V1.1 F02 / T-018：发货后通知买家（失败不影响发货结果）
        event(new \App\Events\OrderShipped($order));

        return $this->success($this->detail($order->load('items')), '发货成功');
    }

    /**
     * 订单导出（CSV，UTF-8 BOM，Excel 可直接打开；权限 order.export）
     * GET /admin/orders/export（同列表筛选条件，最多导出 5000 条）
     */
    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'order_no' => ['nullable', 'string', 'max:32'],
            'user_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_keys(Order::STATUS_LABELS))],
            'start_time' => ['nullable', 'date'],
            'end_time' => ['nullable', 'date'],
        ]);

        $orders = $this->buildQuery($data)
            ->with('items')
            ->orderByDesc('id')
            ->limit(5000)
            ->get();

        $filename = 'orders-'.now()->format('YmdHis').'.csv';

        return response()->streamDownload(function () use ($orders) {
            // UTF-8 BOM：保证 Excel 直接打开不乱码
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');

            fputcsv($out, ['订单号', '用户ID', '状态', '商品', '商品合计', '运费', '实付金额', '收货人', '联系电话', '收货地址', '备注', '下单时间', '支付时间', '发货时间', '取消原因']);

            foreach ($orders as $order) {
                $goods = $order->items->map(fn ($item) => sprintf('%s x%d', $item->product_title, $item->quantity))->implode('；');
                $addr = $order->address_snapshot ?? [];

                fputcsv($out, [
                    $order->order_no,
                    $order->user_id,
                    Order::STATUS_LABELS[$order->status] ?? $order->status,
                    $goods,
                    (string) $order->total_amount,
                    (string) $order->freight_amount,
                    (string) $order->pay_amount,
                    $addr['contact_name'] ?? '',
                    $addr['contact_phone'] ?? '',
                    $addr['full_address'] ?? '',
                    (string) $order->remark,
                    $order->created_at?->format('Y-m-d H:i:s'),
                    $order->paid_at?->format('Y-m-d H:i:s'),
                    $order->shipped_at?->format('Y-m-d H:i:s'),
                    (string) $order->cancel_reason,
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** 列表/导出共用筛选 */
    private function buildQuery(array $data)
    {
        return Order::query()
            ->when($data['order_no'] ?? null, fn ($q, $no) => $q->where('order_no', 'like', '%'.$no.'%'))
            ->when($data['user_id'] ?? null, fn ($q, $uid) => $q->where('user_id', $uid))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['start_time'] ?? null, fn ($q, $start) => $q->where('created_at', '>=', $start))
            ->when($data['end_time'] ?? null, fn ($q, $end) => $q->where('created_at', '<=', $end));
    }

    /** 列表项结构 */
    private function brief(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'user_id' => $order->user_id,
            'status' => $order->status,
            'status_label' => Order::STATUS_LABELS[$order->status] ?? $order->status,
            'total_amount' => $order->total_amount,
            'freight_amount' => $order->freight_amount,
            'pay_amount' => $order->pay_amount,
            'item_count' => (int) $order->items->sum('quantity'),
            'items' => $order->items->map(fn ($item) => [
                'product_title' => $item->product_title,
                'sku_specs' => $item->sku_specs ?? [],
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
        return $this->brief($order) + [
            'remark' => $order->remark,
            'address_snapshot' => $order->address_snapshot,
            'cancel_reason' => $order->cancel_reason,
            'paid_at' => $order->paid_at?->format('Y-m-d H:i:s'),
            'shipped_at' => $order->shipped_at?->format('Y-m-d H:i:s'),
            'completed_at' => $order->completed_at?->format('Y-m-d H:i:s'),
            'cancelled_at' => $order->cancelled_at?->format('Y-m-d H:i:s'),
        ];
    }
}
