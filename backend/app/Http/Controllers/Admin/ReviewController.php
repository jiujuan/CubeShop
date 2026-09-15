<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\Common\ConfigService;
use App\Services\Common\OperationLogService;
use App\Services\Review\ReviewService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 评价管理（V1.1 F01 / T-017）
 * 权限：review.manage
 */
class ReviewController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ReviewService $reviews,
        private ConfigService $config,
        private OperationLogService $opLog,
    ) {
    }

    /** 评价列表 */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->reviews->adminList($data);
        $paginator->through(fn (Review $r) => $this->format($r));

        return $this->success([
            'stats' => $this->reviews->adminStats(),
            'audit_mode' => $this->reviews->auditMode(),
            'list' => $paginator->items(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $review = Review::with(['user', 'product:id,title'])->find($id);
        if (! $review) {
            throw BusinessException::notFound('评价不存在');
        }

        return $this->success($this->format($review) + [
            'product' => $review->product?->only(['id', 'title']),
            'order_id' => $review->order_id,
            'order_item_id' => $review->order_item_id,
        ]);
    }

    /** 审核通过 */
    public function approve(Request $request, int $id): JsonResponse
    {
        $review = $this->find($id);
        $before = $review->status;
        $this->reviews->approve($review);
        $this->opLog->record($request->user()?->id, 'review', 'approve', 'Review', $id, ['before' => $before, 'after' => $review->status]);

        return $this->success(null, '已通过审核');
    }

    /** 审核驳回 */
    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $review = $this->find($id);
        $before = $review->status;
        $this->reviews->reject($review, $data['reason']);
        $this->opLog->record($request->user()?->id, 'review', 'reject', 'Review', $id, [
            'before' => $before, 'after' => $review->status, 'reason' => $data['reason'],
        ]);

        return $this->success(null, '已驳回');
    }

    /** 商家回复 */
    public function reply(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['content' => ['required', 'string', 'max:500']]);
        $review = $this->find($id);
        $this->reviews->reply($review, $data['content']);
        $this->opLog->record($request->user()?->id, 'review', 'reply', 'Review', $id, ['content' => $data['content']]);

        return $this->success(null, '回复成功');
    }

    /** 删除评价 */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $review = $this->find($id);
        $this->reviews->delete($review);
        $this->opLog->record($request->user()?->id, 'review', 'delete', 'Review', $id, ['order_item_id' => $review->order_item_id]);

        return $this->success(null, '删除成功');
    }

    /** 审核模式开关（config.manage 权限由路由控制） */
    public function updateAuditMode(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $this->config->set('review.audit_mode', $data['enabled'] ? '1' : '0');
        $this->config->flush();

        return $this->success(['audit_mode' => $data['enabled']], '已更新审核模式');
    }

    private function find(int $id): Review
    {
        $review = Review::find($id);
        if (! $review) {
            throw BusinessException::notFound('评价不存在');
        }

        return $review;
    }

    private function format(Review $r): array
    {
        return [
            'id' => $r->id,
            'order_id' => $r->order_id,
            'product_id' => $r->product_id,
            'product_title' => $r->product?->title,
            'user_id' => $r->user_id,
            'nickname' => $r->user?->nickname ?? $r->user?->username,
            'is_anonymous' => (bool) $r->is_anonymous,
            'rating' => $r->rating,
            'content' => $r->content,
            'images' => $r->images ?? [],
            'status' => $r->status,
            'status_label' => Review::STATUS_LABELS[$r->status] ?? $r->status,
            'reject_reason' => $r->reject_reason,
            'reply_content' => $r->reply_content,
            'reply_at' => $r->reply_at?->format('Y-m-d H:i:s'),
            'created_at' => $r->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
