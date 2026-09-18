<?php

namespace App\Http\Controllers;

use App\Models\CsTicket;
use App\Models\CsTicketMessage;
use App\Models\CsTicketType;
use App\Services\Common\FileUploadService;
use App\Services\Cs\CsTicketService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * 用户端服务工单控制器（CS-106 / CS-107）
 *
 * 路由挂 `auth:sanctum` + `account.active` 分组（`/api/cs` 前缀），
 * 建单与追加回复加 10/min 限流防刷。
 */
class CsTicketController extends Controller
{
    use ApiResponse;

    public function __construct(
        private CsTicketService $service,
        private FileUploadService $uploader,
    ) {
    }

    /** GET /api/cs/ticket-types —— 激活工单类型（含是否必须关联订单） */
    public function ticketTypes(): JsonResponse
    {
        $types = CsTicketType::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get([
            'id', 'name', 'code', 'require_order',
        ]);

        return $this->success($types);
    }

    /** POST /api/cs/tickets —— 提交工单 */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type_id' => ['required', 'integer', 'exists:cs_ticket_type,id'],
            'title' => ['required', 'string', 'max:128'],
            'content' => ['required', 'string', 'max:2000'],
            'order_id' => ['nullable'],
            'images' => ['nullable', 'array', 'max:9'],
            'images.*' => ['string'],
            'contact' => ['nullable', 'string', 'max:64'],
        ]);

        // 订单标识兼容 public_id 与历史 int 主键，统一解析为内部 id（P2-11 终态：public_id 为 ULID）
        $data['order_id'] = ! empty($data['order_id'])
            ? \App\Support\PublicId::resolve(\App\Support\PublicId::SCOPE_ORDER, $data['order_id'])
            : null;

        // 必须关联订单的类型缺 order_id → 422（早于服务层业务校验，与历史校验行为一致）
        $type = CsTicketType::find($data['type_id']);
        if ($type !== null && $type->require_order) {
            $request->validate(['order_id' => ['required']]);
        }

        $ticket = $this->service->createTicket($request->user(), $data);

        return $this->success(['ticket' => $this->toDetail($ticket)], '提交成功', 201);
    }

    /** GET /api/cs/tickets —— 我的工单列表（状态筛选 + 分页） */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:all,pending,processing,waiting_user,completed,closed'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $result = $this->service->forUser(
            $request->user(),
            $data['status'] ?? null,
            (int) ($data['per_page'] ?? 10),
        );

        return $this->paginated($result->through(fn ($t) => $this->toListItem($t)));
    }

    /** GET /api/cs/tickets/{id} —— 工单详情（消息流过滤内部备注 + 订单快照） */
    public function show(Request $request, string $id): JsonResponse
    {
        $ticket = $this->ownTicket($request, $id);
        $ticket->load('order');

        // CS-202：买家端精简快照——不含客服内部字段（refunds[].admin_remark）与后台跳转参数（jump）
        $snapshot = $this->service->orderSnapshot($ticket, forStaff: false);

        return $this->success([
            'ticket' => $this->toDetail($ticket, withMessages: true),
            'order_snapshot' => $snapshot,
            // @deprecated 旧契约形状，新代码用 order_snapshot
            'order' => $this->service->legacyOrderSummary($snapshot),
        ]);
    }

    /** POST /api/cs/tickets/{id}/messages —— 追加回复 */
    public function messages(Request $request, string $id): JsonResponse
    {
        $ticket = $this->ownTicket($request, $id);

        $data = $request->validate([
            'content' => ['nullable', 'string', 'max:2000'],
            'images' => ['nullable', 'array', 'max:9'],
            'images.*' => ['string'],
        ]);

        if (blank($data['content'] ?? null) && empty($data['images'])) {
            return $this->fail('消息内容与图片不能同时为空', 40000);
        }

        $message = $this->service->addMessage(
            $ticket,
            CsTicketMessage::SENDER_USER,
            $request->user()->id,
            $data['content'] ?? null,
            $data['images'] ?? null,
        );

        return $this->success([
            'message' => $message,
            'ticket' => $this->toDetail($ticket->fresh(), withMessages: true),
        ], '回复成功');
    }

    /** POST /api/cs/tickets/{id}/close —— 用户关闭工单 */
    public function close(Request $request, string $id): JsonResponse
    {
        $ticket = $this->ownTicket($request, $id);

        if ($ticket->status === CsTicket::STATUS_CLOSED) {
            throw \App\Exceptions\BusinessException::conflict('工单已关闭');
        }

        $ticket = $this->service->transitionTo($ticket, CsTicket::STATUS_CLOSED, null, CsTicket::CLOSE_REASON_USER);

        return $this->success($this->toDetail($ticket), '工单已关闭');
    }

    /** POST /api/cs/upload-image —— 工单凭证/回复图片上传（复用 FileUploadService） */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'max:5120'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('image');
        $url = $this->uploader->uploadImage($file, 'cs');

        return $this->success(['url' => $url]);
    }

    // ---------- 内部辅助 ----------

    private function ownTicket(Request $request, string $id): CsTicket
    {
        $query = CsTicket::query()
            ->ofUser($request->user()->id)
            ->with(['type'])
            ->where(function ($q) use ($id) {
                $q->where('public_id', $id);
                // P2-11 终态：兼容历史 int 主键过渡（必须与归属人同作用域，避免越权）
                if (ctype_digit($id)) {
                    $q->orWhere('id', (int) $id);
                }
            });
        $ticket = $query->first();

        if ($ticket === null) {
            abort(404);
        }

        return $ticket;
    }

    private function toListItem(CsTicket $ticket): array
    {
        return [
            // P2-11 终态：对外只给 public_id（ULID），不再暴露 int 主键
            'id' => $ticket->public_id,
            'ticket_no' => $ticket->ticket_no,
            'type_id' => $ticket->type_id,
            'type_name' => $ticket->type?->name,
            'order_id' => \App\Support\PublicId::encodeNullable(\App\Support\PublicId::SCOPE_ORDER, $ticket->order_id),
            'title' => $ticket->title,
            'status' => $ticket->status,
            'status_label' => $ticket->statusLabel(),
            'priority' => $ticket->priority,
            'priority_label' => $ticket->priorityLabel(),
            'contact' => $ticket->contact,
            'can_reply' => $ticket->canReply(),
            'can_close' => $ticket->canClose(),
            'message_count' => $ticket->messages()->count(),
            'last_message_at' => $ticket->last_message_at,
            'created_at' => $ticket->created_at,
            'closed_at' => $ticket->closed_at,
        ];
    }

    private function toDetail(CsTicket $ticket, bool $withMessages = false): array
    {
        $base = $this->toListItem($ticket);
        unset($base['message_count']);

        if ($withMessages) {
            $base['messages'] = $this->service->messagesFor($ticket, false)->map(function (CsTicketMessage $m) {
                return [
                    'id' => $m->id,
                    'sender_type' => $m->sender_type,
                    'sender_name' => CsTicketMessage::SENDER_LABELS[$m->sender_type] ?? $m->sender_type,
                    'content' => $m->content,
                    'images' => $m->images,
                    'is_internal' => $m->is_internal,
                    'created_at' => $m->created_at,
                ];
            })->all();
        }

        return $base;
    }
}
