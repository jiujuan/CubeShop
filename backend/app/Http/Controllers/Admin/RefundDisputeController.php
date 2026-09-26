<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\RefundDispute;
use App\Services\Refund\RefundDisputeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台退款纠纷管理（permission 分组内，主键 {id}）
 */
class RefundDisputeController extends Controller
{
    use ApiResponse;

    public function __construct(private RefundDisputeService $service)
    {
    }

    /** 列表：GET /admin/refund-disputes */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:24'],
            'reason_code' => ['nullable', 'string', 'max:32'],
            'keyword' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->service->listForAdmin($data)->through(fn (RefundDispute $d) => $this->row($d));

        return $this->paginated($paginator);
    }

    /** 详情：GET /admin/refund-disputes/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $dispute = RefundDispute::with([
            'refund:id,refund_no,order_id,order_no,status,amount,type,reason,admin_remark,channel',
            'user:id,username,nickname,phone',
            'assignee:id,username,nickname',
            'resolver:id,username,nickname',
            'messages' => fn ($q) => $q->orderBy('id'),
        ])->find($id);

        if (! $dispute) {
            throw BusinessException::notFound('纠纷单不存在');
        }

        return $this->success($this->row($dispute, true));
    }

    /** 指派：POST /admin/refund-disputes/{id}/assign */
    public function assign(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'admin_id' => ['required', 'integer', 'min:1'],
        ]);

        $dispute = RefundDispute::find($id);
        if (! $dispute) {
            throw BusinessException::notFound('纠纷单不存在');
        }

        $this->service->assign($dispute, $request->user()->id, $data['admin_id']);

        return $this->success($this->row($dispute->fresh()), '已指派');
    }

    /** 裁决：POST /admin/refund-disputes/{id}/resolve */
    public function resolve(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'resolution' => ['required', 'string', 'in:'.implode(',', [RefundDispute::RESOLUTION_REFUND, RefundDispute::RESOLUTION_REJECT])],
            'refund_action' => ['nullable', 'string', 'in:'.implode(',', [
                RefundDispute::ACTION_RE_OPEN,
                RefundDispute::ACTION_APPROVE,
                RefundDispute::ACTION_FORCE_RECEIVE,
                RefundDispute::ACTION_NONE,
            ])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $dispute = RefundDispute::find($id);
        if (! $dispute) {
            throw BusinessException::notFound('纠纷单不存在');
        }

        $this->service->resolve($dispute, $request->user()->id, $data['resolution'], $data['refund_action'] ?? null, $data['note'] ?? null);

        return $this->success($this->row($dispute->fresh()), '已裁决');
    }

    /** 留言列表：GET /admin/refund-disputes/{id}/messages */
    public function messages(Request $request, int $id): JsonResponse
    {
        $dispute = RefundDispute::find($id);
        if (! $dispute) {
            throw BusinessException::notFound('纠纷单不存在');
        }

        $list = $dispute->messages()->orderBy('id')->get()->map(fn ($m) => [
            'id' => $m->public_id,
            'sender_type' => $m->sender_type,
            'sender_id' => $m->sender_id,
            'body' => $m->body,
            'attachments' => $m->attachments ?? [],
            'created_at' => $m->created_at?->format('Y-m-d H:i:s'),
        ])->all();

        return $this->success($list);
    }

    /** 运营留言：POST /admin/refund-disputes/{id}/messages */
    public function postMessage(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:9'],
            'attachments.*' => ['string', 'max:500'],
        ]);

        $dispute = RefundDispute::find($id);
        if (! $dispute) {
            throw BusinessException::notFound('纠纷单不存在');
        }

        $msg = $this->service->addMessage($dispute, 'admin', $request->user()->id, $data['body'], $data['attachments'] ?? []);

        return $this->success(['id' => $msg->public_id], '已发送');
    }

    private function row(RefundDispute $d, bool $withMessages = false): array
    {
        $row = [
            'id' => $d->id,
            'public_id' => $d->public_id,
            'refund_no' => $d->refund?->refund_no,
            'refund_status' => $d->refund?->status,
            'order_no' => $d->refund?->order_no,
            'buyer' => $d->user ? ['id' => $d->user->id, 'username' => $d->user->username, 'nickname' => $d->user->nickname] : null,
            'reason_code' => $d->reason_code,
            'reason_label' => RefundDispute::REASON_LABELS[$d->reason_code] ?? $d->reason_code,
            'description' => $d->description,
            'evidence' => $d->evidence ?? [],
            'status' => $d->status,
            'status_label' => $d->status_label,
            'assignee' => $d->assignee ? ['id' => $d->assignee->id, 'username' => $d->assignee->username, 'nickname' => $d->assignee->nickname] : null,
            'resolution' => $d->resolution,
            'resolution_note' => $d->resolution_note,
            'refund_action' => $d->refund_action,
            'created_at' => $d->created_at?->format('Y-m-d H:i:s'),
            'resolved_at' => $d->resolved_at?->format('Y-m-d H:i:s'),
        ];

        if ($withMessages) {
            $row['refund'] = $d->refund ? [
                'refund_no' => $d->refund->refund_no,
                'status' => $d->refund->status,
                'amount' => (string) $d->refund->amount,
                'type' => $d->refund->type,
                'reason' => $d->refund->reason,
                'admin_remark' => $d->refund->admin_remark,
                'channel' => $d->refund->channel,
            ] : null;
            $row['messages'] = $d->messages->map(fn ($m) => [
                'id' => $m->public_id,
                'sender_type' => $m->sender_type,
                'sender_id' => $m->sender_id,
                'body' => $m->body,
                'attachments' => $m->attachments ?? [],
                'created_at' => $m->created_at?->format('Y-m-d H:i:s'),
            ])->all();
        }

        return $row;
    }
}
