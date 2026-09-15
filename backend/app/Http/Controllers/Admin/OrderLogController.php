<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\SysUser;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台订单状态流水（order_logs，API 文档 8.13）
 *
 * 只读：order_logs 的唯一写入点是 OrderService::transitionTo()，
 * 后台不提供任何新增 / 修改 / 删除入口，保证流水可审计。
 */
class OrderLogController extends Controller
{
    use ApiResponse;

    /**
     * 流水列表（全库维度，支持按订单号 / 状态 / 操作人类型 / 时间筛选）
     * GET /admin/order-logs?order_no=&order_id=&to_status=&operator_type=&start_time=&end_time=
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_no' => ['nullable', 'string', 'max:32'],
            'order_id' => ['nullable', 'integer'],
            'to_status' => ['nullable', 'string', 'in:'.implode(',', array_keys(Order::STATUS_LABELS))],
            'operator_type' => ['nullable', 'string', 'in:'.implode(',', array_keys(OrderLog::OPERATOR_LABELS))],
            'start_time' => ['nullable', 'date'],
            'end_time' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->buildQuery($data)
            ->orderByDesc('id')
            ->paginate(min($data['page_size'] ?? 20, 100), ['*'], 'page', $data['page'] ?? 1);

        $paginator->through(fn (OrderLog $log) => $this->row($log));

        return $this->paginated($paginator);
    }

    /**
     * 单笔订单的完整流水（时间正序）
     * GET /admin/orders/{orderId}/logs
     */
    public function orderIndex(int $orderId): JsonResponse
    {
        $order = Order::query()->find($orderId);

        if (! $order) {
            throw BusinessException::notFound('订单不存在');
        }

        $list = OrderLog::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get()
            ->map(fn (OrderLog $log) => $this->row($log))
            ->all();

        return $this->success([
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'list' => $list,
        ]);
    }

    private function buildQuery(array $data)
    {
        return OrderLog::query()
            ->when($data['order_id'] ?? null, fn ($q, $v) => $q->where('order_id', $v))
            ->when($data['order_no'] ?? null, fn ($q, $v) => $q->whereHas(
                'order',
                fn ($q2) => $q2->where('order_no', 'like', '%'.$v.'%'),
            ))
            ->when($data['to_status'] ?? null, fn ($q, $v) => $q->where('to_status', $v))
            ->when($data['operator_type'] ?? null, fn ($q, $v) => $q->where('operator_type', $v))
            ->when($data['start_time'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($data['end_time'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v));
    }

    private function row(OrderLog $log): array
    {
        $operatorName = null;
        if ($log->operator_id) {
            $operatorName = SysUser::withTrashed()->whereKey($log->operator_id)->value('nickname')
                ?: SysUser::withTrashed()->whereKey($log->operator_id)->value('username');
        }

        return [
            'id' => $log->id,
            'order_id' => $log->order_id,
            'order_no' => $log->order?->order_no,
            'from_status' => $log->from_status,
            'from_status_label' => $log->from_status_label,
            'to_status' => $log->to_status,
            'to_status_label' => $log->to_status_label,
            'operator_type' => $log->operator_type,
            'operator_type_label' => $log->operator_type_label,
            'operator_id' => $log->operator_id,
            'operator_name' => $operatorName,
            'remark' => $log->remark,
            'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
