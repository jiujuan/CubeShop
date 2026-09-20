<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Models\CsFaqArticle;
use App\Models\CsFaqCategory;
use App\Services\Cms\CmsCategoryService;
use App\Support\ApiResponse;
use App\Support\CmsBlock;
use App\Support\CmsPageTemplate;
use App\Support\HtmlSanitizer;
use App\Support\MarkdownRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 内容中心 CMS · 用户端（CMS-106）
 *
 * 全部接口**公开**（无鉴权中间件）：站点单页（关于我们/联系我们）与帮助中心
 * 都属于「未登录也要能看」的内容（决策 D4）。
 *
 * 单页对外一律以 `slug` 标识，不暴露内部 id。
 */
class CmsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CmsCategoryService $categoryService) {}

    /** GET /api/cms/nav —— 导航栏目树（只出 show_in_nav 的根，其子孙随根带出） */
    public function nav(): JsonResponse
    {
        return $this->success(
            $this->categoryService->tree(['active_only' => true, 'nav_only' => true])
        );
    }

    /** GET /api/cms/categories —— 指定父下的栏目树（默认根，供帮助中心侧栏） */
    public function categories(Request $request): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'min:0'],
        ]);

        return $this->success(
            $this->categoryService->tree([
                'active_only' => true,
                'parent_id' => (int) ($data['parent_id'] ?? 0),
                // CMS-204：「公告」走独立入口，不作为用户端类目出现（与 /api/cs/faq/categories 同口径）
                'exclude_ids' => array_filter([CsFaqCategory::announcementCarrierId()]),
            ])
        );
    }

    /** GET /api/cms/pages/{slug} —— 单页内容（模板 + 字段值） */
    public function page(string $slug): JsonResponse
    {
        $category = CsFaqCategory::query()
            ->where('type', CsFaqCategory::TYPE_PAGE)
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (! $category) {
            throw BusinessException::notFound('页面不存在或已下线');
        }

        $template = (string) $category->template;

        if (! CmsPageTemplate::exists($template)) {
            throw BusinessException::notFound('页面模板不存在');
        }

        $article = $category->articles()->first();

        // 字段值（源）：与 schema 默认值合并后下发，前端不做默认值兜底
        $fields = CmsPageTemplate::filterPayload($template, (array) ($article?->page_fields ?? []));

        return $this->success([
            'name' => $category->name,
            'slug' => $category->slug,
            'template' => $template,
            'fields' => $fields,
            // markdown 字段的渲染产物（已净化），前端直接 v-html
            'html' => $this->renderMarkdownFields($template, $fields),
            // CMS-203：区块化单页的内容（固定模板单页为空数组）
            'blocks' => $this->renderBlocks($article?->blocks ?? []),
            // CMS-202：SEO（三个字段都可为空，title 回落栏目名 / 描述回落正文首段）
            'seo' => $this->seoOf($category, $article, $fields),
            'updated_at' => $article?->updated_at?->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 区块 → 下发结构（CMS-203）
     *
     * 每个区块在源数据（type + data）之上补两项：
     * - `html`：该块内 markdown 字段的渲染产物（已净化），前端直接 v-html；
     * - `items`：`faq_embed` 专用 —— 把所选栏目下的已发布文章一并带出，
     *   让前台渲染器**零请求**。⚠️ 只加在响应里，不写回存储（`blocks` 列保持纯源）。
     *
     * @param  mixed  $blocks
     * @return list<array<string, mixed>>
     */
    private function renderBlocks(mixed $blocks): array
    {
        $rendered = [];

        foreach (CmsBlock::filterPayload($blocks) as $block) {
            $type = $block['type'];
            $data = $block['data'];

            $html = [];
            foreach (CmsBlock::schema($type) as $field) {
                if (($field['type'] ?? null) === 'markdown') {
                    $key = $field['key'];
                    $html[$key] = HtmlSanitizer::clean(MarkdownRenderer::toHtml((string) ($data[$key] ?? '')));
                }
            }

            $rendered[] = [
                'type' => $type,
                'data' => $data,
                'html' => $html,
                'items' => $type === 'faq_embed' ? $this->faqEmbedItems($data) : [],
            ];
        }

        return $rendered;
    }

    /**
     * `faq_embed` 区块的文章列表（已发布 + 与帮助中心一致的排序）
     *
     * 上限取 schema 里 limit 字段的取值；栏目不存在或不是 channel 时返回空数组
     * （前台据此整块不渲染，不留空壳）。
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function faqEmbedItems(array $data): array
    {
        $categoryId = (int) ($data['category_id'] ?? 0);
        if ($categoryId <= 0) {
            return [];
        }

        $limit = max(1, min((int) ($data['limit'] ?? 5), 20));

        $exists = CsFaqCategory::query()
            ->where('id', $categoryId)
            ->where('type', CsFaqCategory::TYPE_CHANNEL)
            ->exists();

        if (! $exists) {
            return [];
        }

        return CsFaqArticle::query()
            ->published()
            ->where('category_id', $categoryId)
            ->orderByDesc('is_hot')
            ->orderBy('sort')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'title', 'summary'])
            ->map(fn (CsFaqArticle $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'summary' => $a->summary,
            ])
            ->all();
    }

    /**
     * 单页 SEO 三元组（CMS-202）
     *
     * 三项都在后台可空，这里做**出口回落**而不是让前端写默认值逻辑：
     * - title：seo_title → 栏目名
     * - description：seo_description → 正文首个有内容的 markdown 字段（截 120 字）
     * - keywords：seo_keywords → 空串（不编造关键词）
     *
     * @param  array<string, mixed>  $fields
     * @return array{title: string, keywords: string, description: string}
     */
    private function seoOf(CsFaqCategory $category, ?CsFaqArticle $article, array $fields): array
    {
        $description = (string) ($category->seo_description ?? '');

        if ($description === '') {
            foreach ($this->markdownSources((string) $category->template, $article, $fields) as $markdown) {
                // ⚠️ 先渲染成 HTML 再取纯文本：直接 strip_tags(markdown 源) 会把 `##`
                // 之类的标记残留进 meta description。
                $html = MarkdownRenderer::toHtml($markdown);
                $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

                if ($text !== '') {
                    $description = mb_strlen($text) > 120 ? mb_substr($text, 0, 120).'…' : $text;
                    break;
                }
            }
        }

        return [
            'title' => (string) (($category->seo_title ?? '') ?: $category->name),
            'keywords' => (string) ($category->seo_keywords ?? ''),
            'description' => $description,
        ];
    }

    /**
     * 按优先级列出单页里所有 markdown 正文源
     *
     * 固定模板取 schema 顺序；区块模板按区块顺序展开 —— 两者都只是「可能的描述来源」，
     * 调用方取第一个有内容的。
     *
     * @param  array<string, mixed>  $fields
     * @return list<string>
     */
    private function markdownSources(string $template, ?CsFaqArticle $article, array $fields): array
    {
        $sources = [];

        foreach (CmsPageTemplate::schema($template) as $field) {
            if (($field['type'] ?? null) === 'markdown') {
                $sources[] = (string) ($fields[$field['key']] ?? '');
            }
        }

        if (CmsPageTemplate::isBlocks($template)) {
            foreach (CmsBlock::filterPayload($article?->blocks ?? []) as $block) {
                foreach (CmsBlock::schema($block['type']) as $field) {
                    if (($field['type'] ?? null) === 'markdown') {
                        $sources[] = (string) ($block['data'][$field['key']] ?? '');
                    }
                }
            }
        }

        return $sources;
    }

    /**
     * markdown 字段 → 净化后的 HTML
     *
     * 单页 markdown 字段存的是**源**（与文章正文一致，见 CmsPageTemplate），展示侧所需的
     * HTML 在这里渲染：`MarkdownRenderer` 渲染 → `HtmlSanitizer` 白名单净化。
     * 前端因此不引入 markdown 依赖，安全边界仍只落在 HtmlSanitizer 一处。
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, string>
     */
    private function renderMarkdownFields(string $template, array $fields): array
    {
        $html = [];
        foreach (CmsPageTemplate::schema($template) as $field) {
            if (($field['type'] ?? null) !== 'markdown') {
                continue;
            }

            $key = $field['key'];
            $html[$key] = HtmlSanitizer::clean(MarkdownRenderer::toHtml((string) ($fields[$key] ?? '')));
        }

        return $html;
    }
}
