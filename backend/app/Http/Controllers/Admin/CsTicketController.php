<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CsTicket;
use App\Models\CsTicketMessage;
use App\Models\CsTicketType;
use App\Models\Notification;
use App\Models\SysOperationLog;
use App\Models\SysUser;
use App\Models\User;
use App\Services\Cs\CsTicketService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 后台工单控制器（CS-108 列表/详情 + CS-109 处理动作）
 *
 * 所有状态写入一律通过 CsTicketService（唯一状态写入点，决策 D5）。
 * 写操作（回复/状态/转交/优先级/批量转交）均记 sys_operation_log。
 */
class CsTicketController extends Controller
{
    use ApiResponse;

    public function __construct(private CsTicketService $service)
    {
    }

    /** GET /api/admin/cs/ticket-types —— 工单类型选项（筛选/展示用） */
    public function ticketTypes(): JsonResponse
    {
        $types = CsTicketType::query()
            ->where('is_active', true)
            ->orderBy('sort')->orderBy('id')
            ->get(['id', 'name', 'code', 'require_order']);

        return $this->success($types);
    }

    /**
     * GET /api/admin/cs/assignees —— 可转交的客服列表（cs.ticket.handle）
     *
     * 与 `/admin/accounts`（需 account.manage）刻意分离：客服角色没有账号管理权限，
     * 但「转交给同事」是工作台的核心动作。这里只返回**持有 cs.ticket.view 且启用的账号**，
     * 即可见范围＝可转交范围，不额外暴露后台全部账号（最小权限，CS-117 缺陷 #4）。
     */
    public function assignees(): JsonResponse
    {
        $users = SysUser::query()
            ->permission('cs.ticket.view')
            ->where('status', 1)
            ->orderBy('id')
            ->get(['id', 'username', 'nickname']);

        return $this->success($users->map(fn (SysUser $user) => [
            'id' => $user->id,
            'username' => $user->username,
            'nickname' => $user->nickname,
        ])->all());
    }

    /** GET /api/admin/cs/tickets —— 多条件筛选列表 + 待处理统计 */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,processing,waiting_user,completed,closed'],
            'type_id' => ['nullable', 'integer'],
            'assignee_id' => ['nullable', 'integer'],
            'priority' => ['nullable', 'integer', 'in:0,1'],
            'keyword' => ['nullable', 'string', 'max:50'],
            'created_start' => ['nullable', 'date'],
            'created_end' => ['nullable', 'date', 'after_or_equal:created_start'],
            'sort_by' => ['nullable', 'string', 'in:last_message_at,created_at,priority'],
            'sort_dir' => ['nullable', 'string', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filters = array_filter([
            'status' => $data['status'] ?? null,
            'type_id' => $data['type_id'] ?? null,
            'assignee_id' => $data['assignee_id'] ?? null,
            'priority' => $data['priority'] ?? null,
            'keyword' => $data['keyword'] ?? null,
            'created_start' => $data['created_start'] ?? null,
            'created_end' => $data['created_end'] ?? null,
        ], fn ($v) => $v !== null);

        $perPage = (int) ($data['per_page'] ?? 20);
        $sortBy = $data['sort_by'] ?? 'last_message_at';
        $sortDir = $data['sort_dir'] ?? 'desc';

        $query = $this->service->staffQuery($filters);
        if ($sortBy === 'priority') {
            $query->orderByDesc('priority')->orderByDesc('last_message_at');
        } elseif ($sortDir === 'asc') {
            $query->orderBy('created_at')->orderBy('id');
        } else {
            $query->orderByDesc($sortBy)->orderByDesc('id');
        }

        $paginator = $query->paginate($perPage);
        $pendingCount = $this->service->pendingCount();

        return $this->success([
            'list' => $paginator->items(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
            'meta' => ['pending_count' => $pendingCount],
        ]);
    }

    /** GET /api/admin/cs/tickets/{id} —— 详情（含内部备注、用户/订单快照、可用操作） */
    public function show(int $id): JsonResponse
    {
        $ticket = CsTicket::with(['type', 'assignee'])->findOrFail($id);
        $ticket->load(['user', 'order']);

        // CS-202：订单/商品/脱敏收货/物流最新轨迹/退款记录（实时聚合，服务层负责预加载防 N+1）
        $snapshot = $this->service->orderSnapshot($ticket, forStaff: true);

        return $this->success([
            'ticket' => $this->toDetail($ticket),
            'user_summary' => $this->userSummary($ticket),
            'order_snapshot' => $snapshot,
            // @deprecated 旧契约形状（CS-114 订单卡 + 既有用例），新代码用 order_snapshot
            'order' => $this->service->legacyOrderSummary($snapshot),
            'actions' => [
                'can_reply' => $ticket->canReply(),
                'can_complete' => in_array($ticket->status, [CsTicket::STATUS_PROCESSING, CsTicket::STATUS_WAITING_USER], true),
                'can_close' => $ticket->canClose(),
            ],
        ]);
    }

    /** POST /api/admin/cs/tickets/{id}/messages —— 客服回复 / 内部备注 */
    public function messages(Request $request, int $id): JsonResponse
    {
        $ticket = CsTicket::findOrFail($id);

        $data = $request->validate([
            'content' => ['nullable', 'string', 'max:2000'],
            'images' => ['nullable', 'array', 'max:9'],
            'images.*' => ['string'],
            'is_internal' => ['nullable', 'boolean'],
        ]);

        if (blank($data['content'] ?? null) && empty($data['images'])) {
            return $this->fail('消息内容与图片不能同时为空', 40000);
        }

        $message = $this->service->addMessage(
            $ticket,
            CsTicketMessage::SENDER_STAFF,
            $request->user()->id,
            $data['content'] ?? null,
            $data['images'] ?? null,
            (bool) ($data['is_internal'] ?? false),
        );

        $this->log($request, 'cs_ticket_reply', 'cs_ticket', $id, '回复工单 #'.$ticket->ticket_no);

        return $this->success([
            'message' => $message,
            'ticket' => $this->toDetail($ticket->fresh()),
        ], '已回复');
    }

    /** PUT /api/admin/cs/tickets/{id}/status —— 状态变更（唯一写入点） */
    public function status(Request $request, int $id): JsonResponse
    {
        $ticket = CsTicket::findOrFail($id);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:processing,waiting_user,completed,closed'],
        ]);

        $ticket = $this->service->transitionTo($ticket, $data['status'], $request->user()->id);
        $this->log($request, 'cs_ticket_status', 'cs_ticket', $id, '状态变更 → '.$data['status']);

        return $this->success($this->toDetail($ticket), '状态已更新');
    }

    /** PUT /api/admin/cs/tickets/{id}/assign —— 转交客服 */
    public function assign(Request $request, int $id): JsonResponse
    {
        $ticket = CsTicket::findOrFail($id);

        $data = $request->validate([
            'assignee_id' => ['nullable', 'integer', 'exists:sys_user,id'],
        ]);

        $assigneeName = $data['assignee_id'] ? SysUser::find($data['assignee_id'])?->username : null;
        $ticket = $this->service->assign($ticket, $data['assignee_id'], $request->user()->id, $assigneeName);
        $this->log($request, 'cs_ticket_assign', 'cs_ticket', $id, '转交 → '.$assigneeName);

        return $this->success($this->toDetail($ticket), '已转交');
    }

    /** PUT /api/admin/cs/tickets/{id}/priority —— 优先级标记 */
    public function priority(Request $request, int $id): JsonResponse
    {
        $ticket = CsTicket::findOrFail($id);

        $data = $request->validate([
            'priority' => ['required', 'integer', 'in:0,1'],
        ]);

        $ticket = $this->service->setPriority($ticket, $data['priority'], $request->user()->id);
        $this->log($request, 'cs_ticket_priority', 'cs_ticket', $id, '优先级 → '.($data['priority'] ? '紧急' : '普通'));

        return $this->success($this->toDetail($ticket), '优先级已更新');
    }

    /** POST /api/admin/cs/tickets/batch-assign —— 批量转交（原子） */
    public function batchAssign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'assignee_id' => ['nullable', 'integer', 'exists:sys_user,id'],
        ]);

        $assigneeName = $data['assignee_id'] ? SysUser::find($data['assignee_id'])?->username : null;

        $affected = DB::transaction(function () use ($data, $request, $assigneeName) {
            $count = 0;
            foreach ($data['ids'] as $tid) {
                $ticket = CsTicket::find($tid);
                if ($ticket === null) {
                    continue;
                }
                $this->service->assign($ticket, $data['assignee_id'], $request->user()->id, $assigneeName);
                $count++;
            }

            return $count;
        });

        $this->log($request, 'cs_ticket_batch_assign', 'cs_ticket', 0, '批量转交 '.$affected.' 条 → '.$assigneeName);

        return $this->success(['affected' => $affected], '批量转交完成');
    }

    // ---------- 内部辅助 ----------

    private function toDetail(CsTicket $ticket): array
    {
        $messages = $this->service->messagesFor($ticket, true); // 后台可见内部备注

        return [
            'id' => $ticket->id,
            'ticket_no' => $ticket->ticket_no,
            'type_id' => $ticket->type_id,
            'type_name' => $ticket->type?->name,
            'user_id' => $ticket->user_id,
            'user_name' => $ticket->user?->nickname ?: $ticket->user?->username,
            'order_id' => $ticket->order_id,
            'title' => $ticket->title,
            'content' => $ticket->content,
            'contact' => $ticket->contact,
            'status' => $ticket->status,
            'status_label' => $ticket->statusLabel(),
            'priority' => $ticket->priority,
            'priority_label' => $ticket->priorityLabel(),
            'assignee_id' => $ticket->assignee_id,
            'assignee_name' => $ticket->assignee?->username,
            'closed_at' => $ticket->closed_at,
            'close_reason' => $ticket->close_reason,
            'first_replied_at' => $ticket->first_replied_at,
            'last_message_at' => $ticket->last_message_at,
            'created_at' => $ticket->created_at,
            'messages' => $messages->map(function (CsTicketMessage $m) {
                return [
                    'id' => $m->id,
                    'sender_type' => $m->sender_type,
                    'sender_name' => CsTicketMessage::SENDER_LABELS[$m->sender_type] ?? $m->sender_type,
                    'content' => $m->content,
                    'images' => $m->images,
                    'is_internal' => $m->is_internal,
                    'created_at' => $m->created_at,
                ];
            })->all(),
        ];
    }

    private function userSummary(CsTicket $ticket): array
    {
        $user = $ticket->user;
        $phone = $user?->phone ?? '';

        return [
            'id' => $user?->id,
            'nickname' => $user?->nickname,
            'phone_masked' => $phone ? substr($phone, 0, 3).'****'.substr($phone, -4) : '',
            'registered_at' => $user?->created_at,
            'ticket_count' => CsTicket::where('user_id', $ticket->user_id)->count(),
        ];
    }

    private function log(Request $request, string $action, string $targetType, int $targetId, string $content): void
    {
        SysOperationLog::create([
            'user_id' => $request->user()->id,
            'actor_type' => SysOperationLog::ACTOR_ADMIN,
            'module' => 'cs',
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'content' => $content,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
