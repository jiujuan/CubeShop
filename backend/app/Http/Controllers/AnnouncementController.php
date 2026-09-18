<?php

namespace App\Http\Controllers;

use App\Models\CsAnnouncement;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 用户端公告控制器（P-Announcement，公开无需登录）
 *
 * - index：前台可见公告列表（已发布 + 发布时间到达），置顶优先、发布时间倒序；
 *   支持 limit/page 翻页，列表项含正文纯文本摘要 summary。
 * - show：公告详情，按 public_id 解析且必须可见，否则 404。
 *
 * 列表走默认分页响应：公开资源不暴露精确总量（SEC-04），仅返回 has_more。
 */
class AnnouncementController extends Controller
{
    use ApiResponse;

    /** GET /api/announcements —— 列表 */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 5), 20);
        $page = max((int) $request->input('page', 1), 1);

        $paginator = CsAnnouncement::visible()
            ->ordered()
            ->paginate($perPage, page: $page);

        $paginator->getCollection()->transform(fn (CsAnnouncement $a) => [
            'id' => $a->public_id,
            'title' => $a->title,
            'is_top' => (bool) $a->is_top,
            'published_at' => $a->published_at?->toDateTimeString(),
            'summary' => $this->summarize($a->content),
        ]);

        return $this->paginated($paginator);
    }

    /** GET /api/announcements/{id} —— 详情 */
    public function show(string $id): JsonResponse
    {
        $a = CsAnnouncement::resolvePublicId($id);

        if (! $a || $a->status !== CsAnnouncement::STATUS_PUBLISHED
            || ! $a->published_at || $a->published_at->gt(now())) {
            abort(404, '公告不存在或已下架');
        }

        return $this->success([
            'announcement' => [
                'id' => $a->public_id,
                'title' => $a->title,
                'content' => $a->content,
                'is_top' => (bool) $a->is_top,
                'published_at' => $a->published_at?->toDateTimeString(),
                'created_at' => $a->created_at?->toDateTimeString(),
                'updated_at' => $a->updated_at?->toDateTimeString(),
            ],
        ]);
    }

    /** 正文纯文本摘要（去标签 + 截断，多字节安全） */
    private function summarize(?string $html, int $length = 80): string
    {
        $text = trim(strip_tags((string) $html));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length).'…' : $text;
    }
}
