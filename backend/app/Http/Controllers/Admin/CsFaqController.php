<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CsFaqArticle;
use App\Models\CsFaqCategory;
use App\Services\Cms\CmsCategoryService;
use App\Services\Common\FileUploadService;
use App\Support\ApiResponse;
use App\Support\CmsBlock;
use App\Support\CmsListStyle;
use App\Support\CmsPageTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 后台内容管理（原 CS-110 帮助中心 FAQ 管理；CMS-104/105 扩展为通用 CMS）
 *
 * 两类主体：
 * - **栏目**：树形（parent_id / level / path），`type=channel` 挂文章列表、`type=page` 是单页
 * - **文章**：栏目下的内容行；单页的内容（`page_fields`）同样寄生于文章行
 *
 * 全部接口挂 `permission:cs.faq.manage`（决策 D2：沿用既有权限码，不做权限迁移）。
 * 写操作记 sys_operation_log。
 */
class CsFaqController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CmsCategoryService $categoryService,
        private readonly FileUploadService $uploader,
    ) {}

    // ---------- 栏目 ----------

    /** GET /api/admin/cs/faq/categories —— 栏目树（含文章计数） */
    public function categories(): JsonResponse
    {
        return $this->success($this->categoryService->tree());
    }

    /** POST /api/admin/cs/faq/categories */
    public function storeCategory(Request $request): JsonResponse
    {
        $parentId = (int) $request->input('parent_id', 0);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:64',
                // 父子化后唯一性只约束同父（不同父下允许重名）
                Rule::unique('cs_faq_category', 'name')->where('parent_id', $parentId),
            ],
            'parent_id' => ['nullable', 'integer', 'min:0'],
            'type' => ['nullable', 'string', Rule::in([CsFaqCategory::TYPE_CHANNEL, CsFaqCategory::TYPE_PAGE])],
            'slug' => ['nullable', 'string', 'max:64'],
            'template' => ['nullable', 'string', 'max:32', Rule::in(CmsPageTemplate::templateKeys())],
            'show_in_nav' => ['nullable', 'boolean'],
            'icon' => ['nullable', 'string', 'max:32'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
            // CMS 新闻中心：列表形态（仅 channel 有意义；page 忽略）
            'list_style' => ['nullable', 'string', Rule::in(CmsListStyle::values())],
            // CMS-202：SEO 三列。⚠️ 用 nullable 而非 required —— 空串会被
            // ConvertEmptyStringsToNull 转成 null，required 会导致「清空 SEO」永远 422。
            'seo_title' => ['nullable', 'string', 'max:128'],
            'seo_keywords' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:255'],
        ]);

        $data = $this->normalizeCategoryData($data, $data['type'] ?? CsFaqCategory::TYPE_CHANNEL);

        $category = $this->categoryService->create($data);

        $this->log($request, 'cs_faq_category_create', 'cs_faq_category', $category->id, '创建栏目 '.$category->name);

        return $this->success($category, '已创建', 201);
    }

    /** PUT /api/admin/cs/faq/categories/{id} */
    public function updateCategory(Request $request, int $id): JsonResponse
    {
        $category = CsFaqCategory::findOrFail($id);
        $parentId = (int) $request->input('parent_id', $category->parent_id);

        // CMS 新闻中心：根栏目 slug 已锁定（前端 /news 依赖它），运营改了会让前台路由 404
        if (
            $category->id === CsFaqCategory::newsRootId()
            && $request->has('slug')
            && (string) ($request->input('slug') ?? '') !== (string) $category->slug
        ) {
            throw ValidationException::withMessages(['slug' => ['新闻中心根栏目的 slug 已锁定（前端 /news 依赖），不可修改']]);
        }

        $data = $request->validate([
            'name' => [
                'sometimes', 'string', 'max:64',
                Rule::unique('cs_faq_category', 'name')->where('parent_id', $parentId)->ignore($id),
            ],
            'type' => ['nullable', 'string', Rule::in([CsFaqCategory::TYPE_CHANNEL, CsFaqCategory::TYPE_PAGE])],
            'slug' => ['nullable', 'string', 'max:64'],
            'template' => ['nullable', 'string', 'max:32', Rule::in(CmsPageTemplate::templateKeys())],
            'show_in_nav' => ['nullable', 'boolean'],
            'icon' => ['nullable', 'string', 'max:32'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
            // CMS 新闻中心：列表形态（仅 channel 有意义；page 忽略）
            'list_style' => ['nullable', 'string', Rule::in(CmsListStyle::values())],
            // CMS-202：SEO 三列（nullable ⇒ 可清空）
            'seo_title' => ['nullable', 'string', 'max:128'],
            'seo_keywords' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:255'],
        ]);

        $type = $data['type'] ?? $category->type;
        $data = $this->normalizeCategoryData($data, $type, $category);

        $category = $this->categoryService->update($id, $data);

        $this->log($request, 'cs_faq_category_update', 'cs_faq_category', $id, '编辑栏目 '.$category->name);

        return $this->success($category, '已更新');
    }

    /** POST /api/admin/cs/faq/categories/{id}/move —— 换父（防环与子树级联由服务保证） */
    public function moveCategory(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['required', 'integer', 'min:0'],
        ]);

        $category = $this->categoryService->move($id, (int) $data['parent_id']);

        $this->log(
            $request, 'cs_faq_category_move', 'cs_faq_category', $id,
            '移动栏目 '.$category->name.' 至父级 '.$data['parent_id'],
        );

        return $this->success($category, '已移动');
    }

    /** POST /api/admin/cs/faq/categories/sort —— 批量排序 */
    public function sortCategories(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer', 'exists:cs_faq_category,id'],
            'items.*.sort' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['items'] as $item) {
                CsFaqCategory::where('id', $item['id'])->update(['sort' => $item['sort']]);
            }
        });

        return $this->success(null, '排序已保存');
    }

    /** DELETE /api/admin/cs/faq/categories/{id} */
    public function destroyCategory(int $id): JsonResponse
    {
        $category = CsFaqCategory::findOrFail($id);
        $name = $category->name;

        $this->categoryService->delete($id);

        $this->log(request(), 'cs_faq_category_delete', 'cs_faq_category', $id, '删除栏目 '.$name);

        return $this->success(null, '已删除');
    }

    // ---------- 文章 ----------

    /** GET /api/admin/cs/faq/articles —— 文章列表（不含单页承载行） */
    public function articles(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:draft,published,offline'],
            'keyword' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = CsFaqArticle::query()
            ->with('category')
            // 单页的内容行由单页编辑器维护，不进文章列表（避免"多出一篇同名文章"）
            ->whereHas('category', fn ($q) => $q->where('type', '!=', CsFaqCategory::TYPE_PAGE))
            ->when(! empty($data['category_id']), fn ($q) => $q->where('category_id', $data['category_id']))
            ->when(! empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when(! empty($data['keyword']), fn ($q) => $q->where(function ($q) use ($data) {
                $q->where('title', 'like', '%'.$data['keyword'].'%')
                    ->orWhere('content', 'like', '%'.$data['keyword'].'%');
            }))
            ->orderByDesc('is_hot')->orderBy('sort')->orderByDesc('id');

        $paginator = $query->paginate((int) ($data['per_page'] ?? 15));

        $list = collect($paginator->items())->map(fn (CsFaqArticle $a) => array_merge($a->toArray(), [
            'helpful_rate' => $a->helpfulRate(),
        ]))->all();

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

    /** POST /api/admin/cs/faq/articles */
    public function storeArticle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:cs_faq_category,id'],
            'title' => ['required', 'string', 'max:191'],
            'summary' => ['nullable', 'string', 'max:255'],
            // 正文的源是 markdown（后台 md-editor-v3 编辑），HTML 产物由模型写入器渲染派生；
            // 刻意不再接受 content 入参 —— 两套正文写法只会制造分叉（见 CS-117 缺陷 #3 的教训）
            'content_md' => ['required', 'string'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'is_hot' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'in:draft,published,offline'],
            // CMS 新闻中心：封面图（图文新闻卡片用；存上传返回的 URL/相对路径，长度宽松）
            'cover_image' => ['nullable', 'string', 'max:512'],
        ]);

        $data['status'] ??= CsFaqArticle::STATUS_DRAFT;
        $data['published_at'] = $data['status'] === CsFaqArticle::STATUS_PUBLISHED ? now() : null;

        $article = CsFaqArticle::create($data);
        $this->log($request, 'cs_faq_article_create', 'cs_faq_article', $article->id, '创建文章 '.$article->title);

        return $this->success($article, '已创建', 201);
    }

    /** PUT /api/admin/cs/faq/articles/{id} */
    public function updateArticle(Request $request, int $id): JsonResponse
    {
        $article = CsFaqArticle::findOrFail($id);

        $data = $request->validate([
            'category_id' => ['sometimes', 'integer', 'exists:cs_faq_category,id'],
            'title' => ['sometimes', 'string', 'max:191'],
            'summary' => ['nullable', 'string', 'max:255'],
            // 允许空串（作者清空正文）；用 sometimes 表示「不传即不改」
            'content_md' => ['sometimes', 'string'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'is_hot' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'in:draft,published,offline'],
            // CMS 新闻中心：封面图（图文新闻卡片用；存上传返回的 URL/相对路径）
            'cover_image' => ['nullable', 'string', 'max:512'],
        ]);

        if (! empty($data['status'])) {
            $data['published_at'] = $data['status'] === CsFaqArticle::STATUS_PUBLISHED
                ? ($article->published_at ?? now())
                : null;
        }

        $article->update(array_filter($data, fn ($v) => $v !== null));
        $this->log($request, 'cs_faq_article_update', 'cs_faq_article', $id, '编辑文章 '.$article->title);

        return $this->success($article, '已更新');
    }

    /** DELETE /api/admin/cs/faq/articles/{id} */
    public function destroyArticle(int $id): JsonResponse
    {
        $article = CsFaqArticle::findOrFail($id);
        $article->delete();
        $this->log(request(), 'cs_faq_article_delete', 'cs_faq_article', $id, '删除文章 '.$article->title);

        return $this->success(null, '已删除');
    }

    /** POST /api/admin/cs/faq/articles/{id}/publish */
    public function publishArticle(int $id): JsonResponse
    {
        $article = CsFaqArticle::findOrFail($id);
        $article->update([
            'status' => CsFaqArticle::STATUS_PUBLISHED,
            'published_at' => $article->published_at ?? now(),
        ]);

        return $this->success($article, '已发布');
    }

    /** POST /api/admin/cs/faq/articles/{id}/offline */
    public function offlineArticle(int $id): JsonResponse
    {
        $article = CsFaqArticle::findOrFail($id);
        $article->update(['status' => CsFaqArticle::STATUS_OFFLINE, 'published_at' => null]);

        return $this->success($article, '已下架');
    }

    /** GET /api/admin/cs/faq/articles/{id}/preview */
    public function previewArticle(int $id): JsonResponse
    {
        $article = CsFaqArticle::with('category')->findOrFail($id);

        return $this->success([
            'id' => $article->id,
            'title' => $article->title,
            'summary' => $article->summary,
            // content_md 供编辑器回显（markdown 源），content 是渲染产物供预览 v-html
            'content_md' => $article->content_md,
            'content' => $article->content,
            'category_name' => $article->category?->name,
            'is_hot' => $article->is_hot,
            'status' => $article->status,
            'view_count' => $article->view_count,
            'helpful_count' => $article->helpful_count,
            'unhelpful_count' => $article->unhelpful_count,
            'helpful_rate' => $article->helpfulRate(),
        ]);
    }

    // ---------- 单页（CMS-105） ----------

    /** POST /api/admin/cs/faq/upload —— CMS 图片上传（单页字段用，落 uploads/cms） */
    public function uploadImage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'image', 'max:5120'], // 5MB
        ]);

        $url = $this->uploader->uploadImage($data['file'], 'cms');

        return $this->success(['url' => $url], '上传成功');
    }

    /** GET /api/admin/cs/faq/page-templates —— 单页模板下拉（真源在后端注册表） */
    public function pageTemplates(): JsonResponse
    {
        $list = collect(CmsPageTemplate::labels())
            ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
            ->values()
            ->all();

        return $this->success($list);
    }

    /**
     * GET /api/admin/cs/faq/page-blocks —— 区块库（CMS-203）
     *
     * 与 page-templates 分开：区块库只在选中「自由区块」模板后才需要，
     * 合进模板接口会让每次开栏目弹窗都多传一份用不上的 schema。
     */
    public function pageBlocks(): JsonResponse
    {
        return $this->success(CmsBlock::options());
    }

    /**
     * GET /api/admin/cs/faq/pages/{id} —— 单页模板 schema + 当前字段值
     *
     * values 由后端与 schema 默认值合并后下发，前端不做默认值兜底。
     * `template=blocks` 的单页改下发 `blocks`（已归一化，含每块 data 的默认值补齐）。
     */
    public function showPage(int $id): JsonResponse
    {
        $category = $this->resolvePageCategory($id);
        $template = (string) $category->template;

        $article = $category->articles()->first();

        return $this->success([
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'template' => $category->template,
                'is_active' => $category->is_active,
            ],
            'template' => [
                'key' => $template,
                'label' => CmsPageTemplate::labels()[$template] ?? $template,
                'fields' => CmsPageTemplate::schema($template),
                'is_blocks' => CmsPageTemplate::isBlocks($template),
            ],
            'values' => CmsPageTemplate::filterPayload(
                $template,
                (array) ($article?->page_fields ?? []),
            ),
            'blocks' => CmsBlock::filterPayload($article?->blocks ?? []),
            'updated_at' => $article?->updated_at?->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * PUT /api/admin/cs/faq/pages/{id} —— 保存单页内容
     *
     * 两套载体按模板分流：固定模板写 `fields`，`blocks` 模板写 `blocks`。
     * 区块类型非法 → 422（结构问题应当报错）；区块内的字段值按各块 schema
     * 静默过滤（与固定模板同一口径）。
     */
    public function savePage(Request $request, int $id): JsonResponse
    {
        $category = $this->resolvePageCategory($id);
        $template = (string) $category->template;
        $isBlocks = CmsPageTemplate::isBlocks($template);

        if ($isBlocks) {
            $request->validate(CmsBlock::indexedRules($request->input('blocks')));
        } else {
            $request->validate(['fields' => ['required', 'array']] + CmsPageTemplate::rules($template));
        }

        $article = $category->articles()->first();

        if (! $article) {
            // 单页内容寄生于文章行：首存时建行，标题取栏目名，直接发布
            $article = new CsFaqArticle([
                'category_id' => $category->id,
                'title' => $category->name,
                'content_md' => '',
                'status' => CsFaqArticle::STATUS_PUBLISHED,
            ]);
        }

        if ($isBlocks) {
            $article->blocks = CmsBlock::filterPayload($request->input('blocks'));
        } else {
            $article->page_fields = CmsPageTemplate::filterPayload($template, (array) $request->input('fields', []));
        }

        $article->status = CsFaqArticle::STATUS_PUBLISHED;
        $article->published_at = $article->published_at ?? now();
        $article->save();

        $this->log($request, 'cms_page_update', 'cs_faq_category', $category->id, '更新单页 '.$category->name);

        return $this->success(null, '单页内容已保存');
    }

    // ---------- 内部 ----------

    /**
     * 单页/栏目字段的互斥与必需性校验
     *
     * - `channel`：清空 slug/template（避免栏目挂着单页身份）
     * - `page`   ：slug 与 template 必须齐备（缺一不可访问）
     *
     * ⚠️ page 分支用「入参 ?: 现值」判断，否则「只改单页名称」会被误拒。
     */
    private function normalizeCategoryData(array $data, string $type, ?CsFaqCategory $existing = null): array
    {
        if ($type !== CsFaqCategory::TYPE_PAGE) {
            $data['slug'] = null;
            $data['template'] = null;

            return $data;
        }

        $slug = $data['slug'] ?? $existing?->slug;
        $template = $data['template'] ?? $existing?->template;

        if (empty($slug)) {
            throw ValidationException::withMessages(['slug' => ['单页必须填写 slug（前台 /p/{slug} 访问）']]);
        }

        if (empty($template)) {
            throw ValidationException::withMessages(['template' => ['单页必须选择模板']]);
        }

        return $data;
    }

    /** 取单页栏目；非单页或模板缺失时抛 422 */
    private function resolvePageCategory(int $id): CsFaqCategory
    {
        $category = CsFaqCategory::findOrFail($id);

        if (! $category->isPage()) {
            throw ValidationException::withMessages(['id' => ['该栏目不是单页类型，无法编辑单页字段']]);
        }

        if (! CmsPageTemplate::exists((string) $category->template)) {
            throw ValidationException::withMessages(
                ['template' => ['单页模板「'.$category->template.'」不存在，请先为该单页选择有效模板']]
            );
        }

        return $category;
    }

    private function log(Request $request, string $action, string $targetType, int $targetId, string $content): void
    {
        \App\Models\SysOperationLog::create([
            'user_id' => $request->user()->id,
            'actor_type' => \App\Models\SysOperationLog::ACTOR_ADMIN,
            'module' => 'cs',
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'content' => $content,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
