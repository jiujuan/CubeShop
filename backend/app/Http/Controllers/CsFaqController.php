<?php

namespace App\Http\Controllers;

use App\Services\Cs\FaqService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 用户端帮助中心 FAQ 控制器（CS-104）
 *
 * 路由挂 `auth:sanctum` + `account.active` 分组，与 `/me/notifications` 同风格。
 */
class CsFaqController extends Controller
{
    use ApiResponse;

    public function __construct(private FaqService $faq)
    {
    }

    /**
     * GET /api/cs/faq/categories —— 帮助中心栏目树（激活 channel + 每类已发布文章数）
     *
     * CMS-201：返回带 children 的树。字段裁剪在 FaqService 内完成（不暴露后台字段）。
     */
    public function categories(): JsonResponse
    {
        return $this->success($this->faq->categories());
    }

    /** GET /api/cs/faq/articles —— 列表（分类/关键词分页） */
    public function articles(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:cs_faq_category,id'],
            'keyword' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $result = $this->faq->articles(
            isset($data['category_id']) ? (int) $data['category_id'] : null,
            $data['keyword'] ?? null,
            (int) ($data['per_page'] ?? 10),
        );

        return $this->paginated($result);
    }

    /** GET /api/cs/faq/articles/{id} —— 详情（浏览量自增 + 同分类推荐） */
    public function detail(int $id): JsonResponse
    {
        $result = $this->faq->detail($id);

        return $this->success([
            'article' => $result['article'],
            'related' => $result['related'],
        ]);
    }

    /** POST /api/cs/faq/articles/{id}/feedback —— 是否有帮助反馈 */
    public function feedback(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'helpful' => ['required', 'boolean'],
        ]);

        $article = $this->faq->feedback($id, (bool) $data['helpful']);

        return $this->success([
            'helpful_count' => $article->helpful_count,
            'unhelpful_count' => $article->unhelpful_count,
        ], '感谢您的反馈');
    }
}
