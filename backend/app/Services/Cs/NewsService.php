<?php

namespace App\Services\Cs;

use App\Exceptions\BusinessException;
use App\Models\CsFaqArticle;
use App\Models\CsFaqCategory;
use App\Support\CmsListStyle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 新闻中心服务（CMS 新闻中心，一期）
 *
 * 与 `FaqService`（帮助中心）对称，但**完全独立**——不复用其排除逻辑，否则会把自己过滤掉。
 * 数据载体就是 CMS 的栏目+文章：新闻中心=根栏目、图文/列表=子栏目、新闻=文章。
 *
 * 关键约束：
 * - 全部公开（新闻必须未登录可看、可被搜索引擎抓）。
 * - 列表字段裁剪（不带正文 content），详情才给全文。
 * - 排除逻辑在 `FaqService` 侧（帮助中心/article 接口不出现新闻），本服务只管新闻自己的事。
 */
class NewsService
{
    public const MAX_KEYWORD_LENGTH = 50;
    public const MAX_PER_PAGE = 50;

    /** 新闻根栏目（含 SEO）；未播种返回 null */
    public function root(): ?CsFaqCategory
    {
        $id = CsFaqCategory::newsRootId();

        return $id === null ? null : CsFaqCategory::find($id);
    }

    /**
     * 新闻中心根栏目 + 子栏目（图文/列表），带 list_style 与已发布文章数
     *
     * @return array{root: array<string, mixed>|null, channels: list<array<string, mixed>>}
     */
    public function channels(): array
    {
        $root = $this->root();

        if ($root === null) {
            return ['root' => null, 'channels' => []];
        }

        $children = CsFaqCategory::query()
            ->where('parent_id', $root->id)
            ->withCount(['articles', 'publishedArticles as published_count'])
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        return [
            'root' => [
                'id' => $root->id,
                'name' => $root->name,
                'seo_title' => $root->seo_title,
                'seo_keywords' => $root->seo_keywords,
                'seo_description' => $root->seo_description,
            ],
            'channels' => $children->map(fn (CsFaqCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'list_style' => $c->list_style ?? CmsListStyle::DEFAULT,
                'published_count' => (int) ($c->published_count ?? 0),
            ])->all(),
        ];
    }

    /**
     * 新闻文章分页列表（字段裁剪，不带正文）
     *
     * @param  int|null  $channelId  子栏目 id；null ⇒ 取新闻根下所有子栏目的文章
     * @return LengthAwarePaginator
     */
    public function articles(?int $channelId, ?string $keyword, int $perPage = 10): LengthAwarePaginator
    {
        $keyword = $this->normalizeKeyword($keyword);

        $query = CsFaqArticle::query()
            ->published()
            ->with('category')
            ->when($channelId !== null, fn ($q) => $q->where('category_id', $channelId))
            ->when($channelId === null, function ($q) {
                $rootId = CsFaqCategory::newsRootId();
                if ($rootId !== null) {
                    $q->whereIn('category_id', CsFaqCategory::subtreeIds($rootId));
                }
            })
            ->when($keyword !== null, fn ($q) => $this->applyKeyword($q, $keyword))
            ->orderByDesc('is_hot')
            ->orderBy('sort')
            ->orderByDesc('id');

        return $query->paginate(min($perPage, self::MAX_PER_PAGE));
    }

    /** 列表字段裁剪（供出口，不含正文 content） */
    public function toListItem(CsFaqArticle $article): array
    {
        return [
            'id' => $article->id,
            'title' => $article->title,
            'summary' => $article->summary,
            'cover_image' => $article->cover_image,
            'published_at' => $article->published_at?->format('Y-m-d H:i:s'),
            'view_count' => $article->view_count,
            'channel_id' => $article->category_id,
            'channel_name' => $article->category?->name,
        ];
    }

    /**
     * 新闻详情：全文 + 同栏目相关 + 同栏目上一篇/下一篇
     *
     * @return array{article: CsFaqArticle, related: list<CsFaqArticle>, prev: array<string, mixed>|null, next: array<string, mixed>|null}
     */
    public function detail(int $id): array
    {
        $article = CsFaqArticle::query()
            ->published()
            ->with('category')
            ->find($id);

        if ($article === null) {
            throw BusinessException::notFound('文章不存在或已下架');
        }

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

        [$prev, $next] = $this->neighbors($article);

        return [
            'article' => $article->fresh(),
            'related' => $related,
            'prev' => $prev,
            'next' => $next,
        ];
    }

    // ---------- 内部辅助 ----------

    /**
     * 同栏目上一篇/下一篇（只在同栏目内取，跨栏目不串）
     *
     * 排序口径与列表一致：`is_hot desc, sort asc, id desc`；
     * 上一篇=序在前的那条，下一篇=序在后的那条。
     */
    private function neighbors(CsFaqArticle $article): array
    {
        $siblings = CsFaqArticle::query()
            ->published()
            ->where('category_id', $article->category_id)
            ->orderByDesc('is_hot')
            ->orderBy('sort')
            ->orderByDesc('id')
            ->get(['id', 'title']);

        $ids = $siblings->pluck('id')->all();
        $pos = array_search($article->id, $ids, true);

        if ($pos === false) {
            return [null, null];
        }

        $map = fn (?CsFaqArticle $a): ?array => $a === null ? null : ['id' => $a->id, 'title' => $a->title];

        return [
            $map($pos > 0 ? $siblings[$pos - 1] : null),
            $map($pos < count($siblings) - 1 ? $siblings[$pos + 1] : null),
        ];
    }

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
