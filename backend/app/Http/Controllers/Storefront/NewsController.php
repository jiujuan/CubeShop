<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Cs\NewsService;
use App\Support\ApiResponse;
use App\Support\PublicId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 新闻中心 · 用户端（CMS 新闻中心）
 *
 * 全部接口**公开**（无鉴权）：新闻必须未登录可看、可被搜索引擎抓取。
 * 与帮助中心完全解耦——走独立的 `/api/news/*`，字段裁剪、将来真拆独立表时前端零改动。
 *
 * 后期增强（§7）：slug 语义化详情（id 兜底）、标签聚合、热门排行、商品种草反查。
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

    /** GET /api/news/articles —— 新闻列表（按子栏目/关键词/标签分页，字段裁剪不带正文） */
    public function articles(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel_id' => ['nullable', 'integer', 'exists:cs_faq_category,id'],
            'keyword' => ['nullable', 'string', 'max:50'],
            'tag' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $result = $this->news->articles(
            isset($data['channel_id']) ? (int) $data['channel_id'] : null,
            $data['keyword'] ?? null,
            (int) ($data['per_page'] ?? 10),
            $data['tag'] ?? null,
        );

        $result->getCollection()->transform(fn ($article) => $this->news->toListItem($article));

        return $this->paginated($result);
    }

    /**
     * GET /api/news/articles/{key} —— 新闻详情（全文 + 相关 + 上一篇/下一篇 + 关联商品）
     *
     * `{key}` 为 slug 或整数 id（后期增强：slug 语义化 URL，id 向后兼容）。
     *
     * 商品分两处出口（后期增强：正文任意位置插卡）：
     * - `embedded_products`：正文里内联的卡片（作者用 `[[product:…]]` 指定位置），按正文顺序；
     * - `products`：其余关联商品，供底部「相关商品」区块（已内联的不再重复）。
     */
    public function detail(string $key): JsonResponse
    {
        $result = $this->news->detail($key);

        return $this->success([
            'article' => $result['article'],
            'related' => $result['related'],
            'prev' => $result['prev'],
            'next' => $result['next'],
            'products' => $result['products'],
            'embedded_products' => $result['embedded_products'],
        ]);
    }

    /** GET /api/news/tags —— 全部标签（含出现次数，供专题/标签云） */
    public function tags(): JsonResponse
    {
        return $this->success($this->news->tags());
    }

    /** GET /api/news/hot —— 热门排行（按浏览量，可选 channel_id/limit） */
    public function hot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel_id' => ['nullable', 'integer', 'exists:cs_faq_category,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        return $this->success($this->news->hot(
            isset($data['channel_id']) ? (int) $data['channel_id'] : null,
            (int) ($data['limit'] ?? 5),
        ));
    }

    /**
     * GET /api/news/by-product/{id} —— 某商品关联的种草新闻（商品详情页用）
     *
     * P2-11：`{id}` 接受商品 public_id 或历史 int 主键，解析不到返回空列表（不 404，
     * 商品详情页不应因这个附属区块而报错）。
     */
    public function byProduct(string $id): JsonResponse
    {
        $productId = PublicId::resolve(PublicId::SCOPE_PRODUCT, $id);

        return $this->success($productId === null ? [] : $this->news->articlesOfProduct($productId));
    }
}
