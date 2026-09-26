<?php

namespace App\Http\Controllers\Refund;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Refund;
use App\Models\RefundDispute;
use App\Services\Refund\RefundDisputeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 买家侧退款纠纷（sanctum 分组内，public_id 路由）
 */
class RefundDisputeController extends Controller
{
    use ApiResponse;

    public function __construct(private RefundDisputeService $service)
    {
    }

    /**
     * 解析并校验归属：public_id → Refund，且属于当前登录用户。
     */
    private function resolveRefund(Request $request, string $publicId): Refund
    {
        $refund = Refund::where('public_id', $publicId)->first();
        if (! $refund) {
            throw BusinessException::notFound('退款单不存在');
        }
        if ($refund->user_id !== $request->user()->id) {
            throw BusinessException::forbidden('无权访问该退款单');
        }

        return $refund;
    }

    /** 发起纠纷：POST /api/refunds/{refund}/dispute */
    public function open(Request $request, string $refund): JsonResponse
    {
        $data = $request->validate([
            'reason_code' => ['required', 'string', 'max:32'],
            'description' => ['nullable', 'string', 'max:1000'],
            'evidence' => ['nullable', 'array', 'max:9'],
            'evidence.*' => ['string', 'max:500'],
        ]);

        $refund = $this->resolveRefund($request, $refund);
        $dispute = $this->service->open(
            $refund,
            $request->user()->id,
            $data['reason_code'],
            $data['description'] ?? null,
            $data['evidence'] ?? [],
        );

        return $this->success($this->row($dispute), '纠纷已提交');
    }

    /** 纠纷列表：GET /api/refunds/{refund}/disputes */
    public function index(Request $request, string $refund): JsonResponse
    {
        $refund = $this->resolveRefund($request, $refund);
        $list = RefundDispute::where('refund_id', $refund->id)->orderByDesc('id')->get();

        return $this->success($list->map(fn (RefundDispute $d) => $this->row($d))->all());
    }

    /** 纠纷详情：GET /api/refunds/{refund}/disputes/{dispute} */
    public function show(Request $request, string $refund, string $dispute): JsonResponse
    {
        $refund = $this->resolveRefund($request, $refund);
        $dispute = RefundDispute::where('public_id', $dispute)
            ->where('refund_id', $refund->id)
            ->firstOrFail();

        return $this->success($this->row($dispute, true));
    }

    /** 买家留言：POST /api/refunds/{refund}/disputes/{dispute}/messages */
    public function message(Request $request, string $refund, string $dispute): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:9'],
            'attachments.*' => ['string', 'max:500'],
        ]);

        $refund = $this->resolveRefund($request, $refund);
        $dispute = RefundDispute::where('public_id', $dispute)
            ->where('refund_id', $refund->id)
            ->firstOrFail();

        $msg = $this->service->addMessage($dispute, 'customer', $request->user()->id, $data['body'], $data['attachments'] ?? []);

        return $this->success(['id' => $msg->public_id], '已发送');
    }

    private function row(RefundDispute $d, bool $withMessages = false): array
    {
        $row = [
            'id' => $d->public_id,
            'refund_id' => $d->refund?->public_id,
            'reason_code' => $d->reason_code,
            'reason_label' => RefundDispute::REASON_LABELS[$d->reason_code] ?? $d->reason_code,
            'description' => $d->description,
            'evidence' => $d->evidence ?? [],
            'status' => $d->status,
            'status_label' => $d->status_label,
            'resolution' => $d->resolution,
            'resolution_note' => $d->resolution_note,
            'refund_action' => $d->refund_action,
            'created_at' => $d->created_at?->format('Y-m-d H:i:s'),
            'resolved_at' => $d->resolved_at?->format('Y-m-d H:i:s'),
        ];

        if ($withMessages) {
            $row['messages'] = $d->messages()->orderBy('id')->get()->map(fn ($m) => [
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
