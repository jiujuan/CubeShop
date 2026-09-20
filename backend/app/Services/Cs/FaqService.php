<?php

namespace App\Services\Cs;

use App\Models\CsFaqArticle;
use App\Models\CsFaqCategory;
use App\Services\Cms\CmsCategoryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 帮助中心 FAQ 服务层（CS-104）
 *
 * 只面向「用户端」：分类/文章列表/详情/是否有帮助反馈。
 * 关键词搜索用 LIKE（决策 D3：SQLite/PG 双库兼容，不用专有全文索引）。
 * 浏览量自增用 increment()（避免并发下读改写丢失，AC-104.4）。
 */
class FaqService
{
    public const MAX_KEYWORD_LENGTH = 50;

    public const MAX_PER_PAGE = 50;

    public function __construct(private readonly CmsCategoryService $categoryService) {}

    /**
     * 帮助中心栏目树（激活栏目 + 每类已发布文章数）
     *
     * CMS-201：由「平铺一级分类」升级为**带 children 的树**（栏目已支持父子化）。
     * 树的构建复用 `CmsCategoryService::tree()`（树逻辑的唯一入口），这里只做
     * 「面向用户端的字段裁剪」——把 is_active/path/template/时间戳等后台字段挡在接口之外。
     *
     * ⚠️ 只出 `type=channel` 的栏目：单页（关于我们/联系我们）虽然也寄居在
     * cs_faq_category，但不属于帮助中心分类导航（CMS-106）。
     * ⚠️ 兼容式扩展：原有 `id/name/sort/published_count` 全部保留，只新增
     * `level/parent_id/children`，老前端不会因为多出字段而坏。
     *
     * @return list<array<string, mixed>>
     */
    public function categories(): array
    {
        return $this->mapNodes(
            $this->categoryService->tree([
                'active_only' => true,
                'type' => CsFaqCategory::TYPE_CHANNEL,
                // CMS-204：「公告」有自己的前台入口，不作为帮助中心类目出现（连同子树一并摘掉）
                'exclude_ids' => array_filter([CsFaqCategory::announcementCarrierId()]),
            ])
        );
    }

    /**
     * 递归裁剪栏目节点
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function mapNodes(array $nodes): array
    {
        return array_map(fn (array $node) => [
            'id' => (int) $node['id'],
            'name' => $node['name'],
            'sort' => (int) $node['sort'],
            'level' => (int) ($node['level'] ?? 1),
            'parent_id' => (int) ($node['parent_id'] ?? 0),
            'published_count' => (int) ($node['published_count'] ?? 0),
            'children' => $this->mapNodes($node['children'] ?? []),
        ], $nodes);
    }

    /**
     * 已发布文章列表（按分类/关键词分页）
     *
     * 同样排除单页承载行（它们不进帮助中心文章流）。
     *
     * @param  int|null  $categoryId
     * @param  string|null  $keyword
     * @param  int  $perPage
     * @return LengthAwarePaginator
     */
    public function articles(?int $categoryId, ?string $keyword, int $perPage = 10): LengthAwarePaginator
    {
        $keyword = $this->normalizeKeyword($keyword);

        // 分类存在性由控制器用 exists 规则校验（非法 → 422）；此处只做查询。
        $query = CsFaqArticle::query()
            ->published()
            ->with('category')
            ->whereHas('category', fn ($q) => $q->where('type', CsFaqCategory::TYPE_CHANNEL))
            ->when($categoryId !== null, fn ($q) => $q->where('category_id', $categoryId))
            ->when($keyword !== null, fn ($q) => $this->applyKeyword($q, $keyword))
            ->orderByDesc('is_hot')
            ->orderBy('sort')
            ->orderByDesc('id');

        return $query->paginate(min($perPage, self::MAX_PER_PAGE));
    }

    /**
     * 文章详情：浏览量自增 + 同分类推荐（≤5，不含自身）
     */
    public function detail(int $id): array
    {
        $article = CsFaqArticle::query()
            ->published()
            ->with('category')
            ->find($id);

        if ($article === null) {
            throw \App\Exceptions\BusinessException::notFound('文章不存在或已下架');
        }

        // 并发安全自增：直接用 SQL increment，不读后写
        $article->increment('view_count');

        $related = CsFaqArticle::query()
            ->published()
            ->where('category_id', $article->category_id)
            ->where('id', '<>', $article->id)
            ->orderByDesc('is_hot')
            ->orderBy('sort')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return [
            'article' => $article->fresh(),
            'related' => $related,
        ];
    }

    /**
     * 「是否有帮助」反馈：helpful=true 累加 helpful_count，否则累加 unhelpful_count
     */
    public function feedback(int $id, bool $helpful): CsFaqArticle
    {
        $article = CsFaqArticle::query()
            ->published()
            ->find($id);

        if ($article === null) {
            throw \App\Exceptions\BusinessException::notFound('文章不存在或已下架');
        }

        if ($helpful) {
            $article->increment('helpful_count');
        } else {
            $article->increment('unhelpful_count');
        }

        return $article->fresh();
    }

    // ---------- 内部辅助 ----------

    /**
     * 关键词归一化：去首尾空格，超长截断（决策 D3 兼容双库 LIKE）
     */
    public function normalizeKeyword(?string $keyword): ?string
    {
        if ($keyword === null) {
            return null;
        }

        $keyword = trim($keyword);
        if ($keyword === '') {
            return null;
        }

        if (mb_strlen($keyword) > self::MAX_KEYWORD_LENGTH) {
            $keyword = mb_substr($keyword, 0, self::MAX_KEYWORD_LENGTH);
        }

        return $keyword;
    }

    private function applyKeyword(\Illuminate\Database\Eloquent\Builder $query, string $keyword): \Illuminate\Database\Eloquent\Builder
    {
        $like = '%'.$keyword.'%';

        return $query->where(function (\Illuminate\Database\Eloquent\Builder $q) use ($like) {
            $q->where('title', 'like', $like)
                ->orWhere('summary', 'like', $like)
                ->orWhere('content', 'like', $like);
        });
    }
}
