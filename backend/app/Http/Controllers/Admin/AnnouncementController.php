<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CsAnnouncement;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台公告管理（P-Announcement，权限 announcement.manage）
 *
 * 写操作记 sys_operation_log（module=announcement）。状态机与用户端一致：
 * draft → published（发布，置 published_at）/ offline（下架，清 published_at）。
 */
class AnnouncementController extends Controller
{
    use ApiResponse;

    /** GET /api/admin/announcements —— 列表（筛选 + 分页） */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,published,offline'],
            'keyword' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = CsAnnouncement::query()
            ->when(! empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when(! empty($data['keyword']), fn ($q) => $q->where(function ($q) use ($data) {
                $q->where('title', 'like', '%'.$data['keyword'].'%')
                    ->orWhere('content_md', 'like', '%'.$data['keyword'].'%');
            }))
            ->orderByDesc('is_top')->orderByDesc('published_at')->orderByDesc('id');

        $paginator = $query->paginate((int) ($data['per_page'] ?? 15));

        $list = collect($paginator->items())->map(fn (CsAnnouncement $a) => [
            'id' => $a->id,
            'title' => $a->title,
            'is_top' => (bool) $a->is_top,
            'status' => $a->status,
            'status_label' => $a->statusLabel(),
            'published_at' => $a->published_at?->toDateTimeString(),
            'created_at' => $a->created_at?->toDateTimeString(),
            'summary' => $this->summarize($a->content),
        ])->all();

        return $this->success([
            'list' => $list,
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    /** POST /api/admin/announcements —— 新建 */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:128'],
            'content_md' => ['required', 'string'],
            'is_top' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'in:draft,published,offline'],
        ]);

        $data['status'] ??= CsAnnouncement::STATUS_DRAFT;
        $data['is_top'] = $data['is_top'] ?? false;
        $data['published_at'] = $data['status'] === CsAnnouncement::STATUS_PUBLISHED ? now() : null;
        $data['created_by'] = $request->user()->id;

        $announcement = CsAnnouncement::create($data);
        $this->log($request, 'announcement_create', 'cs_announcement', $announcement->id, '发布公告 '.$announcement->title);

        return $this->success($this->toArray($announcement), '已创建', 201);
    }

    /** PUT /api/admin/announcements/{id} —— 编辑 */
    public function update(Request $request, int $id): JsonResponse
    {
        $announcement = CsAnnouncement::findOrFail($id);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:128'],
            'content_md' => ['sometimes', 'string'],
            'is_top' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', 'in:draft,published,offline'],
        ]);

        if (! empty($data['status'])) {
            $data['published_at'] = $data['status'] === CsAnnouncement::STATUS_PUBLISHED
                ? ($announcement->published_at ?? now())
                : null;
        }

        $announcement->update(array_filter($data, fn ($v) => $v !== null));
        $this->log($request, 'announcement_update', 'cs_announcement', $id, '编辑公告 '.$announcement->title);

        return $this->success($this->toArray($announcement), '已更新');
    }

    /** POST /api/admin/announcements/{id}/publish —— 发布 */
    public function publish(Request $request, int $id): JsonResponse
    {
        $announcement = CsAnnouncement::findOrFail($id);
        $announcement->update([
            'status' => CsAnnouncement::STATUS_PUBLISHED,
            'published_at' => $announcement->published_at ?? now(),
        ]);
        $this->log($request, 'announcement_publish', 'cs_announcement', $id, '发布公告 '.$announcement->title);

        return $this->success($this->toArray($announcement), '已发布');
    }

    /** POST /api/admin/announcements/{id}/offline —— 下架 */
    public function offline(Request $request, int $id): JsonResponse
    {
        $announcement = CsAnnouncement::findOrFail($id);
        $announcement->update(['status' => CsAnnouncement::STATUS_OFFLINE, 'published_at' => null]);
        $this->log($request, 'announcement_offline', 'cs_announcement', $id, '下架公告 '.$announcement->title);

        return $this->success($this->toArray($announcement), '已下架');
    }

    /** DELETE /api/admin/announcements/{id} —— 删除 */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $announcement = CsAnnouncement::findOrFail($id);
        $announcement->delete();
        $this->log($request, 'announcement_delete', 'cs_announcement', $id, '删除公告 '.$announcement->title);

        return $this->success(null, '已删除');
    }

    /** GET /api/admin/announcements/{id}/preview —— 编辑回显 */
    public function preview(int $id): JsonResponse
    {
        $announcement = CsAnnouncement::findOrFail($id);

        return $this->success([
            'id' => $announcement->id,
            'title' => $announcement->title,
            'content_md' => $announcement->content_md,
            'content' => $announcement->content,
            'is_top' => (bool) $announcement->is_top,
            'status' => $announcement->status,
            'published_at' => $announcement->published_at?->toDateTimeString(),
            'created_at' => $announcement->created_at?->toDateTimeString(),
        ]);
    }

    /** 列表/详情统一输出（管理端用 int 主键） */
    private function toArray(CsAnnouncement $a): array
    {
        return [
            'id' => $a->id,
            'title' => $a->title,
            'content_md' => $a->content_md,
            'content' => $a->content,
            'is_top' => (bool) $a->is_top,
            'status' => $a->status,
            'status_label' => $a->statusLabel(),
            'published_at' => $a->published_at?->toDateTimeString(),
            'created_at' => $a->created_at?->toDateTimeString(),
            'updated_at' => $a->updated_at?->toDateTimeString(),
        ];
    }

    /** 正文纯文本摘要 */
    private function summarize(?string $html, int $length = 80): string
    {
        $text = trim(strip_tags((string) $html));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length).'…' : $text;
    }

    private function log(Request $request, string $action, string $targetType, int $targetId, string $content): void
    {
        \App\Models\SysOperationLog::create([
            'user_id' => $request->user()->id,
            'actor_type' => \App\Models\SysOperationLog::ACTOR_ADMIN,
            'module' => 'announcement',
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'content' => $content,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
