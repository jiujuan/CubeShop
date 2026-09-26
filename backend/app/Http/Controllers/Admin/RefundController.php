<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Refund;
use App\Models\RefundLog;
use App\Models\SysOperationLog;
use App\Services\Common\ConfigService;
use App\Services\Common\OperationLogService;
use App\Services\Refund\RefundService;
use App\Services\Refund\RefundSettings;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 后台退款处理（API 文档 8.4 / Roadmap P5）
 */
class RefundController extends Controller
{
    use ApiResponse;

    public function __construct(
        private RefundService $refunds,
        private ConfigService $configs,
        private RefundSettings $settings,
        private OperationLogService $operationLog,
    ) {
    }

    /** 退款列表：GET /admin/refunds */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'refund_no' => ['nullable', 'string'],
            'order_no' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:'.implode(',', [Refund::STATUS_PENDING, Refund::STATUS_APPROVED, Refund::STATUS_PROCESSING, Refund::STATUS_REJECTED, Refund::STATUS_SUCCESS, Refund::STATUS_FAILED])],
            'aged_hours' => ['nullable', 'integer', 'min:0'],
            'retry_exhausted' => ['nullable', 'in:1'],
            'type' => ['nullable', 'string', 'in:'.implode(',', [Refund::TYPE_REFUND, Refund::TYPE_RETURN_REFUND])],
            'return_status' => ['nullable', 'string', 'in:'.implode(',', [Refund::RETURN_STATUS_WAITING_RETURN, Refund::RETURN_STATUS_SHIPPING, Refund::RETURN_STATUS_RECEIVED, Refund::RETURN_STATUS_EXCEPTION])],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = Refund::query()
            ->with(['order:id,status', 'processor:id,username,nickname'])
            ->when($data['refund_no'] ?? null, fn ($q, $v) => $q->where('refund_no', $v))
            ->when($data['order_no'] ?? null, fn ($q, $v) => $q->where('order_no', $v))
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($data['return_status'] ?? null, fn ($q, $v) => $q->where('return_status', $v))
            ->when(isset($data['aged_hours']), fn ($q) => $q->where('created_at', '<=', now()->subHours((int) $data['aged_hours'])))
            ->when(isset($data['retry_exhausted']), fn ($q) => $q
                ->where('status', Refund::STATUS_FAILED)
                ->where('retry_count', '>=', $this->settings->maxRetry()))
            ->orderByDesc('id')
            ->paginate(min($data['page_size'] ?? 20, 100), ['*'], 'page', $data['page'] ?? 1);

        $paginator->through(fn (Refund $refund) => $this->row($refund));

        return $this->paginated($paginator);
    }

    /**
     * 退款概览统计：GET /admin/refunds/stats
     *
     * 指标卡：各状态计数 + 金额汇总；账龄分桶（未完结单 <24h / 24-72h / >72h，failed 仍需人工闭环故计入）；
     * 异常队列：processing 超 24h（疑似渠道回调丢失）、failed 且 retry_count 达上限（待人工）、
     * return_refund 待退货超 7 天未发货。只读聚合，不触碰退款状态。
     */
    public function stats(): JsonResponse
    {
        $statuses = [
            Refund::STATUS_PENDING, Refund::STATUS_APPROVED, Refund::STATUS_PROCESSING,
            Refund::STATUS_SUCCESS, Refund::STATUS_FAILED, Refund::STATUS_REJECTED,
        ];

        $rows = Refund::query()
            ->selectRaw('status, count(*) as cnt, sum(amount) as total')
            ->whereIn('status', $statuses)
            ->groupBy('status')
            ->get();

        $counts = array_fill_keys($statuses, 0);
        $amounts = array_fill_keys($statuses, '0.00');
        foreach ($rows as $row) {
            $counts[$row->status] = (int) $row->cnt;
            $amounts[$row->status] = number_format((float) $row->total, 2, '.', '');
        }

        $unfinished = Refund::query()->whereIn('status', [
            Refund::STATUS_PENDING, Refund::STATUS_APPROVED, Refund::STATUS_PROCESSING, Refund::STATUS_FAILED,
        ]);
        $aging = [
            'lt_24h' => (clone $unfinished)->where('created_at', '>=', now()->subDay())->count(),
            'h24_72' => (clone $unfinished)->where('created_at', '<', now()->subDay())->where('created_at', '>=', now()->subDays(3))->count(),
            'gt_72h' => (clone $unfinished)->where('created_at', '<', now()->subDays(3))->count(),
        ];

        $queues = [
            'processing_stuck' => Refund::query()
                ->where('status', Refund::STATUS_PROCESSING)
                ->where('created_at', '<', now()->subDay())->count(),
            'failed_maxed' => Refund::query()
                ->where('status', Refund::STATUS_FAILED)
                ->where('retry_count', '>=', $this->settings->maxRetry())->count(),
            'return_waiting_overdue' => Refund::query()
                ->where('type', Refund::TYPE_RETURN_REFUND)
                ->where('return_status', Refund::RETURN_STATUS_WAITING_RETURN)
                ->where('created_at', '<', now()->subDays(7))->count(),
        ];

        return $this->success([
            'status_counts' => $counts,
            'status_amounts' => $amounts,
            'aging' => $aging,
            'queues' => $queues,
        ]);
    }

    /**
     * 退款策略读取：GET /admin/refunds/policy（refund.* 配置唯一出口，RefundSettings）
     */
    public function policy(): JsonResponse
    {
        return $this->success($this->policyPayload());
    }

    /**
     * 退款策略更新：PUT /admin/refunds/policy（permission: refund.process）
     *
     * 只更新请求中出现的键；数值键 null = 不改动（清空自动同意请传 0）。
     * return_address_template 允许传 null 清空（ConvertEmptyStringsToNull 已把 '' 转 null）。
     * 逐键走 ConfigService::set（自带缓存 flush）。
     */
    public function updatePolicy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'auto_approve_amount' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'max_retry' => ['nullable', 'integer', 'min:0', 'max:10'],
            'dispute_sla_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'return_address_template' => ['nullable', 'string', 'max:1000'],
        ]);

        $before = $this->policyPayload();

        if (isset($data['auto_approve_amount'])) {
            $this->configs->set('refund.auto_approve_amount', number_format((float) $data['auto_approve_amount'], 2, '.', ''));
        }
        if (isset($data['max_retry'])) {
            $this->configs->set('refund.max_retry', (string) (int) $data['max_retry']);
        }
        if (isset($data['dispute_sla_hours'])) {
            $this->configs->set('refund.dispute_sla_hours', (string) (int) $data['dispute_sla_hours']);
        }
        if (array_key_exists('return_address_template', $data)) {
            $this->configs->set('refund.return_address_template', (string) ($data['return_address_template'] ?? ''));
        }

        $this->operationLog->record($request->user()->id, 'refund', 'update_policy', 'system_configs', null, [
            'before' => $before,
            'after' => $this->policyPayload(),
        ]);

        return $this->success($this->policyPayload(), '已保存');
    }

    private function policyPayload(): array
    {
        return [
            'auto_approve_amount' => $this->settings->autoApproveAmount(),
            'max_retry' => $this->settings->maxRetry(),
            'dispute_sla_hours' => $this->settings->disputeSlaHours(),
            'return_address_template' => $this->settings->returnAddressTemplate(),
        ];
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
     * 批量审核：POST /admin/refunds/batch-process {ids[], action: approve|reject, remark?}
     *
     * 循环复用 RefundService::process（每单独立事务，含渠道退款驱动/事件/操作日志）。
     * 非 pending 单不中断整批，逐单返回成功/失败结果，便于运营定位问题单。
     */
    public function batchProcess(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
            'action' => ['required', 'string', 'in:approve,reject'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        $adminId = $request->user()->id;
        $succeeded = [];
        $failed = [];

        $refunds = Refund::whereIn('id', $data['ids'])->orderByDesc('id')->get();

        foreach ($refunds as $refund) {
            try {
                /** @var Refund $processed */
                $processed = $this->refunds->process($refund, $adminId, $data['action'], $data['remark'] ?? null);
                $succeeded[] = [
                    'id' => $refund->id,
                    'refund_no' => $refund->refund_no,
                    'status' => $processed->status,
                    'status_label' => Refund::STATUS_LABELS[$processed->status] ?? $processed->status,
                ];
            } catch (\Throwable $e) {
                $failed[] = [
                    'id' => $refund->id,
                    'refund_no' => $refund->refund_no,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        // 入参里有但库里不存在的 id 也如实回报
        $foundIds = $refunds->pluck('id')->all();
        foreach (array_diff($data['ids'], $foundIds) as $missingId) {
            $failed[] = ['id' => (int) $missingId, 'refund_no' => '', 'reason' => '退款单不存在'];
        }

        return $this->success([
            'total' => count($data['ids']),
            'succeeded_count' => count($succeeded),
            'failed_count' => count($failed),
            'succeeded' => $succeeded,
            'failed' => $failed,
        ], sprintf('批量审核完成：成功 %d 单，失败 %d 单', count($succeeded), count($failed)));
    }

    /**
     * 退款列表导出：GET /admin/refunds/export（CSV，随当前筛选全量导出）
     *
     * 与 index 同一组筛选条件；流式输出 + UTF-8 BOM（Excel 直接打开不乱码）。
     */
    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'refund_no' => ['nullable', 'string'],
            'order_no' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:'.implode(',', [Refund::STATUS_PENDING, Refund::STATUS_APPROVED, Refund::STATUS_PROCESSING, Refund::STATUS_REJECTED, Refund::STATUS_SUCCESS, Refund::STATUS_FAILED])],
            'aged_hours' => ['nullable', 'integer', 'min:0'],
            'retry_exhausted' => ['nullable', 'in:1'],
            'type' => ['nullable', 'string', 'in:'.implode(',', [Refund::TYPE_REFUND, Refund::TYPE_RETURN_REFUND])],
            'return_status' => ['nullable', 'string', 'in:'.implode(',', [Refund::RETURN_STATUS_WAITING_RETURN, Refund::RETURN_STATUS_SHIPPING, Refund::RETURN_STATUS_RECEIVED, Refund::RETURN_STATUS_EXCEPTION])],
        ]);

        $query = Refund::query()
            ->with(['user:id,nickname'])
            ->when($data['refund_no'] ?? null, fn ($q, $v) => $q->where('refund_no', $v))
            ->when($data['order_no'] ?? null, fn ($q, $v) => $q->where('order_no', $v))
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($data['return_status'] ?? null, fn ($q, $v) => $q->where('return_status', $v))
            ->when(isset($data['aged_hours']), fn ($q) => $q->where('created_at', '<=', now()->subHours((int) $data['aged_hours'])))
            ->when(isset($data['retry_exhausted']), fn ($q) => $q
                ->where('status', Refund::STATUS_FAILED)
                ->where('retry_count', '>=', $this->settings->maxRetry()))
            ->orderByDesc('id');

        $filename = 'refunds-'.now()->format('YmdHis').'.csv';

        return response()->streamDownload(function () use ($query) {
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            fputcsv($out, ['退款单号', '订单号', '买家', '类型', '状态', '退货状态', '退款金额',
                '退款渠道', '渠道退款状态', '失败原因', '申请原因', '审核备注', '处理人',
                '申请时间', '审核时间', '退款完成时间']);

            $query->cursor()->each(function (Refund $refund) use ($out) {
                fputcsv($out, [
                    $refund->refund_no,
                    $refund->order_no,
                    $refund->user?->nickname,
                    Refund::TYPE_LABELS[$refund->type] ?? $refund->type,
                    Refund::STATUS_LABELS[$refund->status] ?? $refund->status,
                    $refund->return_status === null ? '' : (Refund::RETURN_STATUS_LABELS[$refund->return_status] ?? $refund->return_status),
                    (string) $refund->amount,
                    $refund->channel ?? '',
                    $refund->refund_status ?? '',
                    $refund->failed_reason ?? '',
                    $refund->reason ?? '',
                    $refund->admin_remark ?? '',
                    $refund->processor?->nickname ?? '',
                    $refund->created_at?->format('Y-m-d H:i:s'),
                    $refund->processed_at?->format('Y-m-d H:i:s'),
                    $refund->refunded_at?->format('Y-m-d H:i:s'),
                ]);
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
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
            'processed_by_name' => $refund->processed_by === 0 ? '系统自动' : ($refund->processor?->nickname ?: $refund->processor?->username),
            'processed_at' => $refund->processed_at?->format('Y-m-d H:i:s'),
            'channel' => $refund->channel,
            'out_refund_no' => $refund->out_refund_no,
            'channel_refund_no' => $refund->channel_refund_no,
            'refund_status' => $refund->refund_status,
            'failed_reason' => $refund->failed_reason,
            'retry_count' => (int) $refund->retry_count,
            'refunded_at' => $refund->refunded_at?->format('Y-m-d H:i:s'),
            'max_retry' => $this->settings->maxRetry(),
            'created_at' => $refund->created_at?->format('Y-m-d H:i:s'),
            'order_status' => $refund->order?->status,
        ];
    }
}
