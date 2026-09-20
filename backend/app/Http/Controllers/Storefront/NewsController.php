<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Cs\NewsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 新闻中心 · 用户端（CMS 新闻中心，一期）
 *
 * 全部接口**公开**（无鉴权）：新闻必须未登录可看、可被搜索引擎抓取。
 * 与帮助中心完全解耦——走独立的 `/api/news/*`，字段裁剪、将来真拆独立表时前端零改动。
 */
class NewsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly NewsService $news) {}

    /** GET /api/news/channels —— 新闻中心根栏目（名称/SEO）+ 子栏目（list_style/文章数） */
    public function channels(): JsonResponse
    {
        return $this->success($this->news->channels());
    }

    /** GET /api/news/articles —— 新闻列表（按子栏目/关键词分页，字段裁剪不带正文） */
    public function articles(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel_id' => ['nullable', 'integer', 'exists:cs_faq_category,id'],
            'keyword' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $result = $this->news->articles(
            isset($data['channel_id']) ? (int) $data['channel_id'] : null,
            $data['keyword'] ?? null,
            (int) ($data['per_page'] ?? 10),
        );

        $result->getCollection()->transform(fn ($article) => $this->news->toListItem($article));

        return $this->paginated($result);
    }

    /** GET /api/news/articles/{id} —— 新闻详情（全文 + 相关 + 上一篇/下一篇） */
    public function detail(int $id): JsonResponse
    {
        $result = $this->news->detail($id);

        return $this->success([
            'article' => $result['article'],
            'related' => $result['related'],
            'prev' => $result['prev'],
            'next' => $result['next'],
        ]);
    }
}
