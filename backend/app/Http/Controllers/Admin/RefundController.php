<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Refund;
use App\Models\RefundLog;
use App\Models\SysOperationLog;
use App\Services\Refund\RefundService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台退款处理（API 文档 8.4 / Roadmap P5）
 */
class RefundController extends Controller
{
    use ApiResponse;

    public function __construct(private RefundService $refunds)
    {
    }

    /** 退款列表：GET /admin/refunds */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'refund_no' => ['nullable', 'string'],
            'order_no' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:'.implode(',', [Refund::STATUS_PENDING, Refund::STATUS_APPROVED, Refund::STATUS_REJECTED, Refund::STATUS_SUCCESS, Refund::STATUS_FAILED])],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = Refund::query()
            ->with(['order:id,status', 'processor:id,username,nickname'])
            ->when($data['refund_no'] ?? null, fn ($q, $v) => $q->where('refund_no', $v))
            ->when($data['order_no'] ?? null, fn ($q, $v) => $q->where('order_no', $v))
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')
            ->paginate(min($data['page_size'] ?? 20, 100), ['*'], 'page', $data['page'] ?? 1);

        $paginator->through(fn (Refund $refund) => $this->row($refund));

        return $this->paginated($paginator);
    }

    /**
     * 退款详情：GET /admin/refunds/{id}
     *
     * 在列表行基础上补齐：用户信息、订单摘要、订单商品明细（产品图/链接）、处理流水。
     * 供后台「详情」弹层展示用户退款的产品图、产品链接、理由、凭证图片与后台处理记录。
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $refund = Refund::with(['processor:id,username,nickname'])->find($id);
        if (! $refund) {
            throw BusinessException::notFound('退款单不存在');
        }

        $order = Order::with(['items.product', 'items.sku'])->find($refund->order_id);
        $user = $refund->user;

        $items = $order
            ? $order->items->map(fn ($item) => [
                // 后台商品详情路由 /products/{id} 用 int 主键；同时给出 public_id 供前台链接
                'product_id' => $item->product?->id,
                'product_public_id' => $item->product?->public_id,
                'product_title' => $item->product_title,
                'sku_id' => $item->sku_id,
                'sku_public_id' => $item->sku?->public_id,
                'sku_specs' => $item->sku_specs ?? [],
                'sku_image' => $item->sku_image,
                'price' => (string) $item->price,
                'quantity' => (int) $item->quantity,
                'total_amount' => (string) $item->total_amount,
            ])->values()->all()
            : [];

        // 后台处理流水（本退款单的审计记录）
        $logs = SysOperationLog::query()
            ->with(['admin:id,username,nickname', 'customer:id,username,nickname'])
            ->where('target_type', 'refund')
            ->where('target_id', $refund->id)
            ->orderBy('id')
            ->get()
            ->map(fn (SysOperationLog $log) => [
                'id' => $log->id,
                'actor_type' => $log->actor_type ?? SysOperationLog::ACTOR_ADMIN,
                'operator' => ($log->actor_type === SysOperationLog::ACTOR_CUSTOMER ? $log->customer : $log->admin)
                    ?->only(['id', 'username', 'nickname']),
                'action' => $log->action,
                // content 为 JSON 字符串；content_data 为解码后的结构，供后台友好渲染（中文键值 / 图片）
                'content' => $log->content,
                'content_data' => $this->decodeLogContent($log->content),
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
            ])->all();

        return $this->success(array_merge($this->row($refund), [
            'user' => $user ? [
                'id' => $user->id,
                'username' => $user->username,
                'nickname' => $user->nickname,
                'phone' => $user->phone,
            ] : null,
            'order' => $order ? [
                'order_no' => $order->order_no,
                'status' => $order->status,
                'pay_amount' => (string) $order->pay_amount,
                'created_at' => $order->created_at?->format('Y-m-d H:i:s'),
            ] : null,
            'items' => $items,
            'logs' => $logs,
        ]));
    }

    /** 审核退款：POST /admin/refunds/{id}/process {action, admin_remark, admin_images} */
    public function process(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:approve,reject'],
            'admin_remark' => ['nullable', 'string', 'max:255'],
            'admin_images' => ['nullable', 'array', 'max:9'],
            'admin_images.*' => ['string', 'max:500'],
        ]);

        $refund = Refund::find($id);
        if (! $refund) {
            throw BusinessException::notFound('退款单不存在');
        }

        $refund = $this->refunds->process(
            refund: $refund,
            adminId: $request->user()->id,
            action: $data['action'],
            adminRemark: $data['admin_remark'] ?? null,
            adminImages: $data['admin_images'] ?? [],
        );

        return $this->success($this->row($refund->fresh()), '处理成功');
    }

    /**
     * 确认收货（退货退款专用）：POST /admin/refunds/{id}/receive {received_details, exception_reason}
     * 等效菜鸟回传收货；按实收正品回库存 → 退款完成。
     */
    public function receive(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'received_details' => ['required', 'array', 'min:1'],
            'received_details.*.sku_id' => ['required'],
            'received_details.*.quantity' => ['required', 'integer', 'min:0'],
            'received_details.*.condition' => ['required', 'string', 'in:good,defective'],
            'exception_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $refund = Refund::find($id);
        if (! $refund) {
            throw BusinessException::notFound('退款单不存在');
        }

        $refund = $this->refunds->receiveReturn(
            refund: $refund,
            adminId: $request->user()->id,
            receivedDetails: $data['received_details'],
            exceptionReason: $data['exception_reason'] ?? null,
        );

        return $this->success($this->row($refund->fresh()), '已确认收货，退款已完成');
    }

    /**
     * 后台重试退款（失败态）：POST /admin/refunds/{id}/retry
     *
     * 仅 failed 态可重试；复用 out_refund_no 幂等，达 MAX_RETRY(3) 转人工。
     */
    public function retry(Request $request, int $id): JsonResponse
    {
        $refund = Refund::find($id);
        if (! $refund) {
            throw BusinessException::notFound('退款单不存在');
        }

        $refund = $this->refunds->retry($refund, $request->user()->id);

        return $this->success($this->row($refund->fresh()), '已发起重试');
    }

    /**
     * 退款全链路事件日志：GET /admin/refunds/{id}/logs
     *
     * 读取 refund_logs（Phase 1 引入，append-only），覆盖申请→审核→调渠道→回调→查单→重试→终态
     * 每个重要节点，供后台「退款日志」抽屉追溯。与 SysOperationLog（后台处理流水）互补。
     */
    public function logs(Request $request, int $id): JsonResponse
    {
        $refund = Refund::find($id);
        if (! $refund) {
            throw BusinessException::notFound('退款单不存在');
        }

        $logs = RefundLog::query()
            ->where('refund_id', $refund->id)
            ->orderBy('id')
            ->get()
            ->map(fn (RefundLog $log) => [
                'id' => $log->id,
                'type' => $log->type,
                'channel' => $log->channel,
                'out_refund_no' => $log->out_refund_no,
                'channel_status' => $log->channel_status,
                'actor_type' => $log->actor_type,
                'actor_id' => $log->actor_id,
                'note' => $log->note,
                'request' => $log->request,
                'response' => $log->response,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
            ])->all();

        return $this->success(['refund_id' => $refund->id, 'logs' => $logs]);
    }

    /**
     * 解码操作日志 content（JSON 字符串）为结构化数据
     *
     * 后台「处理记录」需要按中文键值渲染（含图片），直接展示原始 JSON 不可读。
     * 非 JSON 内容返回 null，由前端回退展示原文。
     */
    private function decodeLogContent(?string $content): mixed
    {
        if ($content === null || $content === '') {
            return null;
        }

        $decoded = json_decode($content, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function row(Refund $refund): array
    {
        return [
            'id' => $refund->id,
            'refund_no' => $refund->refund_no,
            'order_id' => $refund->order_id,
            'order_no' => $refund->order_no,
            'user_id' => $refund->user_id,
            'type' => $refund->type,
            'amount' => (string) $refund->amount,
            'reason' => $refund->reason,
            'images' => $refund->images ?? [],
            'status' => $refund->status,
            'return_status' => $refund->return_status,
            'return_tracking_no' => $refund->return_tracking_no,
            'return_express_company' => $refund->return_express_company,
            'return_details' => $refund->return_details,
            'return_received_details' => $refund->return_received_details,
            'return_received_at' => $refund->return_received_at?->format('Y-m-d H:i:s'),
            'return_exception_reason' => $refund->return_exception_reason,
            'admin_remark' => $refund->admin_remark,
            'admin_images' => $refund->admin_images ?? [],
            'processed_by' => $refund->processed_by,
            'processed_by_name' => $refund->processor?->nickname ?: $refund->processor?->username,
            'processed_at' => $refund->processed_at?->format('Y-m-d H:i:s'),
            'channel' => $refund->channel,
            'out_refund_no' => $refund->out_refund_no,
            'channel_refund_no' => $refund->channel_refund_no,
            'refund_status' => $refund->refund_status,
            'failed_reason' => $refund->failed_reason,
            'retry_count' => (int) $refund->retry_count,
            'refunded_at' => $refund->refunded_at?->format('Y-m-d H:i:s'),
            'max_retry' => RefundService::MAX_RETRY,
            'created_at' => $refund->created_at?->format('Y-m-d H:i:s'),
            'order_status' => $refund->order?->status,
        ];
    }
}
