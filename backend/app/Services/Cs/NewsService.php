<?php

namespace App\Services\Cs;

use App\Exceptions\BusinessException;
use App\Models\CsFaqArticle;
use App\Models\CsFaqCategory;
use App\Models\Product;
use App\Support\CmsListStyle;
use App\Support\ProductEmbed;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 新闻中心服务（CMS 新闻中心）
 *
 * 与 `FaqService`（帮助中心）对称，但**完全独立**——不复用其排除逻辑，否则会把自己过滤掉。
 * 数据载体就是 CMS 的栏目+文章：新闻中心=根栏目、图文/列表=子栏目、新闻=文章。
 *
 * 关键约束：
 * - 全部公开（新闻必须未登录可看、可被搜索引擎抓）。
 * - 列表字段裁剪（不带正文 content），详情才给全文。
 * - 排除逻辑在 `FaqService` 侧（帮助中心/article 接口不出现新闻），本服务只管新闻自己的事。
 *
 * 后期增强（§7）已并入：slug 语义化 URL、文章级 SEO、标签聚合、热门排行、商品种草关联。
 */
class NewsService
{
    public const MAX_KEYWORD_LENGTH = 50;
    public const MAX_PER_PAGE = 50;
    public const MAX_HOT = 20;
    public const MAX_RELATED_PRODUCTS = 8;

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
     * @param  string|null  $tag  标签过滤（专题页 / tag 侧栏用）
     * @return LengthAwarePaginator
     */
    public function articles(?int $channelId, ?string $keyword, int $perPage = 10, ?string $tag = null): LengthAwarePaginator
    {
        $keyword = $this->normalizeKeyword($keyword);
        $tag = $this->normalizeKeyword($tag);

        $query = $this->baseQuery()
            ->with('category')
            ->when($channelId !== null, fn ($q) => $q->where('category_id', $channelId))
            ->when($channelId === null, function ($q) {
                $rootId = CsFaqCategory::newsRootId();
                if ($rootId !== null) {
                    $q->whereIn('category_id', CsFaqCategory::subtreeIds($rootId));
                }
            })
            ->when($keyword !== null, fn ($q) => $this->applyKeyword($q, $keyword))
            ->when($tag !== null, fn ($q) => $q->whereJsonContains('tags', $tag))
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
            'slug' => $article->slug,
            'title' => $article->title,
            'summary' => $article->summary,
            'cover_image' => $article->cover_image,
            'tags' => $article->tagList(),
            'published_at' => $article->published_at?->format('Y-m-d H:i:s'),
            'view_count' => $article->view_count,
            'channel_id' => $article->category_id,
            'channel_name' => $article->category?->name,
        ];
    }

    /**
     * 新闻详情：全文 + 同栏目相关 + 同栏目上一篇/下一篇 + 关联商品
     *
     * `$key` 可为 slug 或整数 id（后期增强：slug 语义化 URL，id 兜底）。
     *
     * @return array{article: CsFaqArticle, related: list<CsFaqArticle>, prev: array<string, mixed>|null, next: array<string, mixed>|null, products: list<array<string, mixed>>, embedded_products: list<array<string, mixed>>}
     */
    public function detail(string|int $key): array
    {
        $article = CsFaqArticle::findBySlugOrId((string) $key);

        if ($article === null || $article->status !== CsFaqArticle::STATUS_PUBLISHED) {
            throw BusinessException::notFound('文章不存在或已下架');
        }

        $article->load('category');
        $article->increment('view_count');

        $related = $this->baseQuery()
            ->where('category_id', $article->category_id)
            ->where('id', '<>', $article->id)
            ->orderByDesc('is_hot')
            ->orderBy('sort')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        [$prev, $next] = $this->neighbors($article);

        return [
            'article' => $article->fresh()->load('category'),
            'related' => $related,
            'prev' => $prev,
            'next' => $next,
            ...$this->splitProducts($article),
        ];
    }

    /**
     * 关联商品按「正文内联」与「底部列表」拆开（后期增强：正文任意位置插商品卡）
     *
     * 一件商品只应出现在一个位置：
     * - 作者把商品卡内联进了正文 → 归 `embedded_products`，底部不再重复列一遍；
     * - 其余 → 归 `products`（底部「相关商品」区块）。
     *
     * 正文里只存了「这里有一张商品卡」的占位（见 App\Support\ProductEmbed），真正的卡片数据
     * 由前台用本方法返回的实时商品数据渲染 —— 因此价格/主图不会冻结在保存那一刻。
     *
     * ⚠️ 占位符引用但**不在关联集里**（作者手写的 id、或商品已下架/被移除关联）的商品直接忽略：
     * 出口只认「已发布的关联商品」，不给悬空占位补数据（前台相应地不渲染该占位）。
     *
     * @return array{products: list<array<string, mixed>>, embedded_products: list<array<string, mixed>>}
     */
    private function splitProducts(CsFaqArticle $article): array
    {
        $inlined = ProductEmbed::extractIds($article->content);

        $products = [];
        $embedded = [];

        foreach ($article->products()->where('products.status', 1)->limit(self::MAX_RELATED_PRODUCTS)->get() as $product) {
            // 内联判定按 public_id：占位符里存的就是对外标识，前后台同一口径
            if (in_array($product->public_id, $inlined, true)) {
                $embedded[] = $this->toProductItem($product);

                continue;
            }

            $products[] = $this->toProductItem($product);
        }

        return ['products' => $products, 'embedded_products' => $embedded];
    }

    /**
     * 某商品关联的已发布新闻（商品详情页「相关资讯/种草」反查）
     *
     * @return list<array<string, mixed>>
     */
    public function articlesOfProduct(int $productId, int $limit = 6): array
    {
        $rootId = CsFaqCategory::newsRootId();
        if ($rootId === null) {
            return [];
        }

        return $this->baseQuery()
            ->with('category')
            ->whereIn('category_id', CsFaqCategory::subtreeIds($rootId))
            ->whereHas('products', fn ($q) => $q->where('products.id', $productId))
            ->orderByDesc('is_hot')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (CsFaqArticle $a) => $this->toListItem($a))
            ->all();
    }

    /**
     * 热门排行（按浏览量倒序，只为已发布新闻；频道级可选过滤）
     *
     * @return list<array<string, mixed>>
     */
    public function hot(?int $channelId = null, int $limit = 5): array
    {
        $rootId = CsFaqCategory::newsRootId();
        if ($rootId === null) {
            return [];
        }

        return $this->baseQuery()
            ->with('category')
            ->whereIn('category_id', $channelId !== null ? [$channelId] : CsFaqCategory::subtreeIds($rootId))
            ->orderByDesc('view_count')
            ->orderByDesc('id')
            ->limit(min($limit, self::MAX_HOT))
            ->get()
            ->map(fn (CsFaqArticle $a) => $this->toListItem($a))
            ->all();
    }

    /**
     * 全部标签（新闻子树内已发布文章），按出现次数倒序
     *
     * @return list<array{tag: string, count: int}>
     */
    public function tags(): array
    {
        $rootId = CsFaqCategory::newsRootId();
        if ($rootId === null) {
            return [];
        }

        $rows = $this->baseQuery()
            ->whereIn('category_id', CsFaqCategory::subtreeIds($rootId))
            ->get(['tags']);

        $counter = [];
        foreach ($rows as $row) {
            foreach ($row->tagList() as $tag) {
                $counter[$tag] = ($counter[$tag] ?? 0) + 1;
            }
        }

        arsort($counter);

        $list = [];
        foreach ($counter as $tag => $count) {
            $list[] = ['tag' => (string) $tag, 'count' => (int) $count];
        }

        return $list;
    }

    /**
     * slug 生成：由标题派生；调用方负责唯一化（`uniqueSlug`）。
     *
     * 中文标题无 ASCII 词元时回落 `news-{id}`（此处给随机/时间兜底，见 uniqueSlug）。
     */
    public function slugify(string $title): string
    {
        $slug = strtolower(trim($title));
        // 非「ASCII 字母数字 + 常用汉字」统一压成连字符（PCRE 用 \x{} 表示码点）
        $slug = preg_replace('/[^a-z0-9\x{4e00}-\x{9fa5}]+/u', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug;
    }

    /**
     * 生成全表唯一 slug：`$base` 已占用则追加 -2/-3…；$base 为空则用 `news-$ignoreId`。
     *
     * @param  int|null  $ignoreId  编辑自身时排除自己（避免把自己判成冲突）
     */
    public function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = $this->slugify($title);
        if ($base === '') {
            $base = $ignoreId !== null ? 'news-'.$ignoreId : 'news-'.now()->format('YmdHis');
        }

        $slug = $base;
        $suffix = 2;
        while ($this->slugExists($slug, $ignoreId)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /** slug 是否已被占用（排除 $ignoreId 自身） */
    public function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        return CsFaqArticle::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '<>', $ignoreId))
            ->exists();
    }

    // ---------- 内部辅助 ----------

    /** 新闻范围内「已发布 + 属于 channel 栏目」的基础查询（列表/详情/热门/标签共用） */
    private function baseQuery()
    {
        return CsFaqArticle::query()
            ->published()
            ->whereHas('category', fn ($q) => $q->where('type', CsFaqCategory::TYPE_CHANNEL));
    }

    /** 商品出口裁剪（P2-11：id 为 public_id） */
    private function toProductItem(Product $p): array
    {
        return [
            'id' => $p->public_id,
            'title' => $p->title,
            'subtitle' => $p->subtitle,
            'main_image' => $p->main_image,
            'price' => (string) $p->price,
        ];
    }

    /**
     * 同栏目上一篇/下一篇（只在同栏目内取，跨栏目不串）
     *
     * 排序口径与列表一致：`is_hot desc, sort asc, id desc`；
     * 上一篇=序在前的那条，下一篇=序在后的那条。
     */
    private function neighbors(CsFaqArticle $article): array
    {
        $siblings = $this->baseQuery()
            ->where('category_id', $article->category_id)
            ->orderByDesc('is_hot')
            ->orderBy('sort')
            ->orderByDesc('id')
            ->get(['id', 'title', 'slug']);

        $ids = $siblings->pluck('id')->all();
        $pos = array_search($article->id, $ids, true);

        if ($pos === false) {
            return [null, null];
        }

        $map = fn (?CsFaqArticle $a): ?array => $a === null
            ? null
            : ['id' => $a->id, 'slug' => $a->slug, 'title' => $a->title];

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
