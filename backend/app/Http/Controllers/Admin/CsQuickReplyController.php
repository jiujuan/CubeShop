<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CsQuickReply;
use App\Services\Cs\CsQuickReplyService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台快捷回复模板管理 + 工作台取用（CS-203 / CS-204）
 *
 * 权限：
 * - 读取（管理列表 / 工作台下拉）挂 `role_or_permission:cs.faq.manage|cs.ticket.handle`
 *   → 客服（cs.ticket.handle，如 operator）可取用；管理员/客服主管（cs.faq.manage）可取用+管理
 * - 写入（增/改/删）挂 `permission:cs.faq.manage`
 *   → 仅管理员/客服主管可维护模板（AC-203.1）
 */
class CsQuickReplyController extends Controller
{
    use ApiResponse;

    public function __construct(private CsQuickReplyService $service)
    {
    }

    /**
     * GET /api/admin/cs/quick-replies
     * - 无 type_id：管理列表（全量，按 sort/id 排序，带 type_name）
     * - 有 type_id：工作台下拉（通用 + 该类型专属，按 sort/id 排序）
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type_id' => ['nullable', 'integer'],
        ]);

        $typeId = $data['type_id'] ?? null;

        $list = $typeId === null
            ? CsQuickReply::query()->ordered()->get()
            : $this->service->forType((int) $typeId);

        $list = $list->load('type')->map(function (CsQuickReply $r) {
            return array_merge($r->toArray(), [
                'type_name' => $r->type?->name,
            ]);
        })->values();

        return $this->success($list);
    }

    /** POST /api/admin/cs/quick-replies */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:64'],
            'content' => ['required', 'string', 'max:2000'],
            'type_id' => ['nullable', 'integer', 'exists:cs_ticket_type,id'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $reply = CsQuickReply::create([
            'title' => $data['title'],
            'content' => $data['content'],
            'type_id' => $data['type_id'] ?? null,
            'sort' => $data['sort'] ?? 0,
            'created_by' => $request->user()->id,
        ]);

        $this->log($request, 'cs_quick_reply_create', 'cs_quick_reply', $reply->id, '创建快捷回复 '.$reply->title);

        return $this->success($reply, '已创建', 201);
    }

    /** PUT /api/admin/cs/quick-replies/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $reply = CsQuickReply::findOrFail($id);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:64'],
            'content' => ['sometimes', 'string', 'max:2000'],
            'type_id' => ['nullable', 'integer', 'exists:cs_ticket_type,id'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        // 过滤 null（保留 0 / false 这类合法值），并补 updated_by
        $update = collect($data)->filter(fn ($v) => $v !== null)->all();
        $update['updated_by'] = $request->user()->id;
        $reply->update($update);

        $this->log($request, 'cs_quick_reply_update', 'cs_quick_reply', $id, '编辑快捷回复 '.$reply->title);

        return $this->success($reply, '已更新');
    }

    /** DELETE /api/admin/cs/quick-replies/{id} */
    public function destroy(int $id): JsonResponse
    {
        $reply = CsQuickReply::findOrFail($id);
        $reply->delete();

        $this->log(request(), 'cs_quick_reply_delete', 'cs_quick_reply', $id, '删除快捷回复 '.$reply->title);

        return $this->success(null, '已删除');
    }

    private function log(Request $request, string $action, string $targetType, int $targetId, string $content): void
    {
        \App\Models\SysOperationLog::create([
            'user_id' => $request->user()->id,
            'actor_type' => \App\Models\SysOperationLog::ACTOR_ADMIN,
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
