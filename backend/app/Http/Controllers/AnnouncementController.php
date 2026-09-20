<?php

namespace App\Http\Controllers;

use App\Models\CsFaqArticle;
use App\Models\CsFaqCategory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 用户端公告控制器（P-Announcement，公开无需登录）
 *
 * CMS-204 起，公告**软并入内容中心**（决策 D9 选项①）：数据住在 CMS 的「公告」栏目下
 * （迁移 000098 把 `cs_announcement` 存量行拷了过来），但本接口的**响应契约一字不改**
 * —— 字段名、类型、可见口径、排序、SEC-04 的分页策略全部沿用，前台公告页与首页公告位
 * 零改动。
 *
 * ⚠️ 一处刻意的语义变化：`id` 由原来的 ULID 换成**文章的数值 id（字符串形式）**。
 * 对外仍是字符串，且前端只回传列表里拿到的值，因此不影响任何调用方；只有并入前
 * 存下来的旧链接会 404（页面本身已做「公告不存在或已下架」的兜底）。
 *
 * 可见口径与并入前完全一致（公告从来就没有「启用/停用」开关，故这里不看栏目 is_active）：
 * - 列表：已发布 + 发布时间已到，置顶优先、发布时间倒序；
 * - 详情：按 id 解析且必须可见，否则 404。
 *
 * 「公告」栏目按**名字**定位 —— 后台改名等价于清空前台公告位，这是刻意的：
 * 与其猜一个 id，不如让改名这件事有一个明确的、可观察的后果。
 */
class AnnouncementController extends Controller
{
    use ApiResponse;

    /** GET /api/announcements —— 列表 */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 5), 20);
        $page = max((int) $request->input('page', 1), 1);

        $paginator = CsFaqArticle::query()
            // 栏目缺失（迁移没跑 / 被删）时用 0 兜底：查出空列表而不是 500
            ->where('category_id', $this->categoryId() ?? 0)
            ->where('status', CsFaqArticle::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderByDesc('is_hot')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($perPage, page: $page);

        $paginator->getCollection()->transform(fn (CsFaqArticle $a) => [
            'id' => (string) $a->id,
            'title' => $a->title,
            // 公告的「置顶」= CMS 的「热门」
            'is_top' => (bool) $a->is_hot,
            'published_at' => $a->published_at?->toDateTimeString(),
            // 后台填了摘要就用它，否则按正文现算 —— 并入前的行摘要为空，输出与旧版逐字一致
            'summary' => $a->summary !== null && $a->summary !== ''
                ? $a->summary
                : $this->summarize($a->content),
        ]);

        return $this->paginated($paginator);
    }

    /** GET /api/announcements/{id} —— 详情 */
    public function show(string $id): JsonResponse
    {
        $categoryId = $this->categoryId();

        // 非数字 id（并入前的 ULID 旧链接）直接视为不存在：id 已改由文章承载
        $a = ($categoryId === null || ! ctype_digit($id)) ? null : CsFaqArticle::query()
            ->where('category_id', $categoryId)
            ->where('id', (int) $id)
            ->first();

        if (! $a || $a->status !== CsFaqArticle::STATUS_PUBLISHED
            || ! $a->published_at || $a->published_at->gt(now())) {
            abort(404, '公告不存在或已下架');
        }

        return $this->success([
            'announcement' => [
                'id' => (string) $a->id,
                'title' => $a->title,
                'content' => $a->content,
                'is_top' => (bool) $a->is_hot,
                'published_at' => $a->published_at?->toDateTimeString(),
                'created_at' => $a->created_at?->toDateTimeString(),
                'updated_at' => $a->updated_at?->toDateTimeString(),
            ],
        ]);
    }

    /** 「公告」栏目的 id；未播种（或已被删）返回 null */
    private function categoryId(): ?int
    {
        return CsFaqCategory::announcementCarrierId();
    }

    /** 正文纯文本摘要（去标签 + 截断，多字节安全） */
    private function summarize(?string $html, int $length = 80): string
    {
        $text = trim(strip_tags((string) $html));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length).'…' : $text;
    }
}
