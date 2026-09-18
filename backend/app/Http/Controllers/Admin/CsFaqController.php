<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CsFaqArticle;
use App\Models\CsFaqCategory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 后台 FAQ 分类与文章管理（CS-110）
 *
 * 全部接口挂 `permission:cs.faq.manage`。写操作记 sys_operation_log。
 */
class CsFaqController extends Controller
{
    use ApiResponse;

    // ---------- 分类 ----------

    /** GET /api/admin/cs/faq/categories */
    public function categories(): JsonResponse
    {
        $list = CsFaqCategory::query()
            ->withCount(['articles', 'publishedArticles as published_count'])
            ->orderBy('sort')->orderBy('id')
            ->get();

        return $this->success($list);
    }

    /** POST /api/admin/cs/faq/categories */
    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'unique:cs_faq_category,name'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $category = CsFaqCategory::create([
            'name' => $data['name'],
            'sort' => $data['sort'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->log($request, 'cs_faq_category_create', 'cs_faq_category', $category->id, '创建分类 '.$category->name);

        return $this->success($category, '已创建', 201);
    }

    /** PUT /api/admin/cs/faq/categories/{id} */
    public function updateCategory(Request $request, int $id): JsonResponse
    {
        $category = CsFaqCategory::findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:64', 'unique:cs_faq_category,name,'.$id],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $category->update(array_filter($data, fn ($v) => $v !== null));
        $this->log($request, 'cs_faq_category_update', 'cs_faq_category', $id, '编辑分类 '.$category->name);

        return $this->success($category, '已更新');
    }

    /** DELETE /api/admin/cs/faq/categories/{id} —— 有已发布文章则拒绝 */
    public function destroyCategory(int $id): JsonResponse
    {
        $category = CsFaqCategory::findOrFail($id);

        if ($category->publishedArticles()->exists()) {
            throw \App\Exceptions\BusinessException::conflict('该分类下存在已发布文章，请先下架或迁移后再删除');
        }

        $category->delete();
        $this->log(request(), 'cs_faq_category_delete', 'cs_faq_category', $id, '删除分类 '.$category->name);

        return $this->success(null, '已删除');
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

    // ---------- 文章 ----------

    /** GET /api/admin/cs/faq/articles */
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
