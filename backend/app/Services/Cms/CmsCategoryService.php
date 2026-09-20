<?php

namespace App\Services\Cms;

use App\Exceptions\BusinessException;
use App\Models\CsFaqCategory;
use App\Support\CmsListStyle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 内容中心 CMS 栏目树服务（CMS-103）
 *
 * 把「树」的复杂度（层深 / 物化路径 / 移动防环 / 子树级联 / 删除前置校验）收敛在这里，
 * 控制器与前台只做参数校验和转发。
 *
 * 物化路径约定：根节点 `path = /{id}/`，子节点 `path = 父 path + {id}/`。
 * 该约定让子树查询退化为一次前缀扫描，也让「移到自己后代下」这种自环错误
 * 在比较 path 前缀时直接判出。
 */
class CmsCategoryService
{
    /** 单页 slug 保留字：避免与既有前台路由撞车（`/p/:slug` 之外的根级路径） */
    public const RESERVED_SLUGS = [
        'login', 'register', 'cart', 'checkout', 'orders', 'search', 'product',
        'category', 'account', 'notifications', 'announcements', 'service-center',
        'coupons', 'balance', 'pay', 'p', 'api', 'admin',
    ];

    /** update() 允许写入的键 */
    private const UPDATABLE = [
        'name', 'sort', 'is_active', 'type', 'slug', 'template', 'list_style', 'show_in_nav', 'icon',
        'seo_title', 'seo_keywords', 'seo_description',
    ];

    /** 允许被清空（空串归一为 null）的可选文本列 */
    private const NULLABLE_TEXT = ['slug', 'template', 'icon', 'seo_title', 'seo_keywords', 'seo_description'];

    /** 列表形态（CMS 新闻中心，一期）：channel 栏目可切 card/list，落库归一为合法值或默认 */
    private const LIST_STYLE_KEY = 'list_style';

    /**
     * 构建栏目树
     *
     * `exclude_ids` 用于把某些栏目（连同其整棵子树）从结果里摘掉 —— 调用方给出的是
     * 「不该出现在这个列表里」的栏目 id（如用户端类目列表要排除「公告」承载栏目），
     * 服务本身不关心为什么排除，故这里只做通用的 id 过滤。
     *
     * @param  array{parent_id?:int, active_only?:bool, nav_only?:bool, type?:string, exclude_ids?:list<int>}  $options
     * @return list<array<string, mixed>>
     */
    public function tree(array $options = []): array
    {
        $query = CsFaqCategory::query()
            ->withCount(['articles', 'publishedArticles as published_count'])
            ->orderBy('sort')
            ->orderBy('id');

        if (! empty($options['active_only'])) {
            $query->where('is_active', true);
        }

        if (! empty($options['type'])) {
            $query->where('type', $options['type']);
        }

        if (! empty($options['exclude_ids'])) {
            $query->whereNotIn('id', (array) $options['exclude_ids']);
        }

        $tree = $this->buildTree($query->get()->all(), (int) ($options['parent_id'] ?? 0));

        // 导航模式：只保留标记了「显示在导航」的根节点（其子孙不受该标记影响）
        if (! empty($options['nav_only'])) {
            $tree = array_values(array_filter(
                $tree,
                fn (array $node) => (bool) ($node['show_in_nav'] ?? false),
            ));
        }

        return $tree;
    }

    /** 新建栏目：自动计算 level 与物化路径 */
    public function create(array $data): CsFaqCategory
    {
        return DB::transaction(function () use ($data) {
            $parentId = (int) ($data['parent_id'] ?? 0);
            $parent = $parentId > 0 ? CsFaqCategory::find($parentId) : null;

            if ($parentId > 0 && ! $parent) {
                throw ValidationException::withMessages(['parent_id' => ['父栏目不存在']]);
            }

            $this->assertSlugAvailable($data['slug'] ?? null, null);

            $category = CsFaqCategory::create([
                'name' => $data['name'],
                'parent_id' => $parentId,
                'level' => $parent ? $parent->level + 1 : 1,
                'path' => '',
                'type' => $data['type'] ?? CsFaqCategory::TYPE_CHANNEL,
                'slug' => ($data['slug'] ?? '') ?: null,
                'template' => ($data['template'] ?? '') ?: null,
                'list_style' => $this->normalizeListStyle($data['list_style'] ?? null),
                'show_in_nav' => (bool) ($data['show_in_nav'] ?? false),
                'icon' => ($data['icon'] ?? '') ?: null,
                'seo_title' => ($data['seo_title'] ?? '') ?: null,
                'seo_keywords' => ($data['seo_keywords'] ?? '') ?: null,
                'seo_description' => ($data['seo_description'] ?? '') ?: null,
                'sort' => $data['sort'] ?? $this->nextSort($parentId),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            // path 依赖自身 id，插入后补写
            $category->update(['path' => $this->buildPath($parent, $category->id)]);

            return $category->fresh();
        });
    }

    /** 更新栏目属性（不含换父，换父请用 move()） */
    public function update(int $id, array $data): CsFaqCategory
    {
        $category = CsFaqCategory::findOrFail($id);

        if (array_key_exists('slug', $data)) {
            $this->assertSlugAvailable($data['slug'], $id);
        }

        $payload = array_intersect_key($data, array_flip(self::UPDATABLE));

        // slug / template / icon / seo_* 允许清空（空串归一为 null，slug 空即表示「非单页」）
        foreach (self::NULLABLE_TEXT as $nullableKey) {
            if (array_key_exists($nullableKey, $payload)) {
                $payload[$nullableKey] = $payload[$nullableKey] ?: null;
            }
        }

        // list_style 归一：非法/空 ⇒ 默认 list（仅 channel 有意义，page 忽略也无妨）
        if (array_key_exists(self::LIST_STYLE_KEY, $payload)) {
            $payload[self::LIST_STYLE_KEY] = $this->normalizeListStyle($payload[self::LIST_STYLE_KEY] ?? null);
        }

        $category->fill($payload)->save();

        return $category->fresh();
    }

    /** 移动栏目：防环 + 级联重写整棵子树的 level / path */
    public function move(int $id, int $parentId): CsFaqCategory
    {
        return DB::transaction(function () use ($id, $parentId) {
            $node = CsFaqCategory::findOrFail($id);

            if ($id === $parentId) {
                throw ValidationException::withMessages(['parent_id' => ['不能把栏目移动到自身']]);
            }

            $parent = null;
            if ($parentId > 0) {
                $parent = CsFaqCategory::findOrFail($parentId);

                // 防环：目标父位于本节点的子树内（其 path 以本节点 path 为前缀）
                if (str_starts_with($parent->path, $node->path)) {
                    throw ValidationException::withMessages(
                        ['parent_id' => ['不能把栏目移动到自己的子栏目下']]
                    );
                }
            }

            $node->parent_id = $parentId;
            $node->level = $parent ? $parent->level + 1 : 1;
            $node->path = $this->buildPath($parent, $node->id);
            $node->save();

            $this->recalcSubtree($node);

            return $node->fresh();
        });
    }

    /**
     * 删除栏目
     *
     * - 有子栏目 → 拒绝（先删/先移）
     * - 单页 → 内容寄生于本栏目，连带删除其内容行
     * - 栏目 → 有已发布文章则拒绝（沿用既有语义）
     */
    public function delete(int $id): void
    {
        $category = CsFaqCategory::findOrFail($id);

        if (CsFaqCategory::where('parent_id', $id)->exists()) {
            throw BusinessException::conflict('该栏目下存在子栏目，请先删除或移动子栏目');
        }

        if ($category->isPage()) {
            DB::transaction(function () use ($category) {
                $category->articles()->delete();
                $category->delete();
            });

            return;
        }

        if ($category->publishedArticles()->exists()) {
            throw BusinessException::conflict('该分类下存在已发布文章，请先下架或迁移后再删除');
        }

        $category->delete();
    }

    /** 同级排序取最大值 +10（留出插队空间） */
    public function nextSort(int $parentId): int
    {
        return (int) CsFaqCategory::where('parent_id', $parentId)->max('sort') + 10;
    }

    /** slug 合法性 + 唯一性（$ignoreId 用于更新场景排除自身） */
    public function assertSlugAvailable(?string $slug, ?int $ignoreId): void
    {
        if ($slug === null || $slug === '') {
            return;
        }

        if (! preg_match('/^[a-z0-9-]{2,64}$/', $slug)) {
            throw ValidationException::withMessages(
                ['slug' => ['slug 只能包含小写字母、数字与连字符，长度 2~64']]
            );
        }

        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            throw ValidationException::withMessages(
                ['slug' => ['slug「'.$slug.'」是系统保留路径，请更换']]
            );
        }

        $taken = CsFaqCategory::where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['slug' => ['slug「'.$slug.'」已被占用']]);
        }
    }

    /**
     * 按 parent_id 分组后递归装配
     *
     * @param  list<CsFaqCategory>  $rows
     * @return list<array<string, mixed>>
     */
    private function buildTree(array $rows, int $rootParentId): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->parent_id][] = $row;
        }

        $build = function (int $parentId) use (&$build, $grouped): array {
            $nodes = [];
            foreach ($grouped[$parentId] ?? [] as $row) {
                $nodes[] = array_merge($row->toArray(), ['children' => $build($row->id)]);
            }

            return $nodes;
        };

        return $build($rootParentId);
    }

    private function buildPath(?CsFaqCategory $parent, int $id): string
    {
        return ($parent?->path ?? '/').$id.'/';
    }

    /** list_style 归一：非法/空值回落默认（防御性，控制器另有 in 校验兜底） */
    private function normalizeListStyle(?string $style): string
    {
        if ($style !== null && CmsListStyle::isValid($style)) {
            return $style;
        }

        return CmsListStyle::DEFAULT;
    }

    /** 递归重写子孙的 level 与 path（栏目量级小，递归足够且直观） */
    private function recalcSubtree(CsFaqCategory $node): void
    {
        foreach (CsFaqCategory::where('parent_id', $node->id)->get() as $child) {
            $child->level = $node->level + 1;
            $child->path = $node->path.$child->id.'/';
            $child->save();

            $this->recalcSubtree($child);
        }
    }
}
