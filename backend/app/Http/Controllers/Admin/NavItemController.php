<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\NavItem;
use App\Services\Common\OperationLogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * 后台导航管理（权限 nav.manage）
 *
 * 编排前台顶部导航：条目分「商品分类引用」与「自定义链接」两类，位置由 sort 决定
 * （越大越前，与 categories.sort 体例一致）。
 *
 * ⚠️ 与分类是**引用**关系：后台存 category_id，标题与链接由分类派生。
 *    故这里存/改的是编排，不是文案 —— 分类改名后导航自动跟随。
 *
 * ⚠️ 分类是软删除：删除条目用**物理删除**（nav_items 无软删列），
 *    分类被软删时**不动导航条目**，由公开接口聚合时跳过 —— 恢复启用后位置还在。
 */
class NavItemController extends Controller
{
    use ApiResponse;

    public function __construct(private OperationLogService $opLog)
    {
    }

    /** 列表（含引用分类的当前名称，便于后台核对） */
    public function index(): JsonResponse
    {
        $items = NavItem::query()
            ->orderByDesc('sort')
            ->orderBy('id')
            ->get();

        // ⚠️ Category 有 SoftDeletes：这里的查询自动排除软删，
        // 故「分类已被删除」的引用会拿到 null，后台据此提示（而不是假装它还在）
        $categories = Category::query()
            ->whereIn('id', $items->pluck('category_id')->filter()->unique()->all() ?: [0])
            ->get(['id', 'name'])
            ->keyBy('id');

        return $this->success($items->map(fn (NavItem $item) => [
            'id' => $item->id,
            'type' => $item->type,
            'type_label' => NavItem::TYPE_LABELS[$item->type] ?? $item->type,
            'title' => $item->title,
            'url' => $item->url,
            'category_id' => $item->category_id,
            // 引用型条目的当前展示名（改名自动跟随）与失效标记
            'category_name' => $item->category_id ? ($categories->get((int) $item->category_id)?->name) : null,
            'category_missing' => $item->category_id !== null && ! $categories->has((int) $item->category_id),
            'target' => $item->target,
            'sort' => (int) $item->sort,
            'is_active' => (bool) $item->is_active,
        ])->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(NavItem::TYPE_LABELS))],
            'category_id' => ['nullable', 'integer', 'required_if:type,category', $this->categoryRule()],
            'title' => ['nullable', 'string', 'max:64', 'required_if:type,custom'],
            'url' => ['nullable', 'string', 'max:255', 'required_if:type,custom'],
            'target' => ['nullable', Rule::in(NavItem::TARGETS)],
            'sort' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $conflict = $this->findCategoryConflict((int) ($data['category_id'] ?? 0));
        if ($conflict !== null) {
            return $this->fail('该分类已在导航中', 40000);
        }

        $data['sort'] ??= $this->nextSort(); // 未指定则排到末尾

        $item = NavItem::create($this->normalize($data));

        $this->opLog->record($request->user()?->id, 'nav', 'create', 'NavItem', $item->id);

        return $this->success($item, '创建成功');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $item = NavItem::find($id);
        if (! $item) {
            return $this->fail('导航条目不存在', 40004);
        }

        $data = $request->validate([
            'type' => ['sometimes', Rule::in(array_keys(NavItem::TYPE_LABELS))],
            'category_id' => ['nullable', 'integer', 'required_if:type,category', $this->categoryRule()],
            'title' => ['nullable', 'string', 'max:64', 'required_if:type,custom'],
            'url' => ['nullable', 'string', 'max:255', 'required_if:type,custom'],
            'target' => ['nullable', Rule::in(NavItem::TARGETS)],
            'sort' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $type = $data['type'] ?? $item->type;
        $categoryId = (int) ($data['category_id'] ?? $item->category_id);

        if ($type === NavItem::TYPE_CATEGORY) {
            $conflict = $this->findCategoryConflict($categoryId, $item->id);
            if ($conflict !== null) {
                return $this->fail('该分类已在导航中', 40000);
            }
        }

        $item->fill($this->normalize($data, $item))->save();

        $this->opLog->record($request->user()?->id, 'nav', 'update', 'NavItem', $item->id);

        return $this->success($item, '更新成功');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $item = NavItem::find($id);
        if (! $item) {
            return $this->fail('导航条目不存在', 40004);
        }

        $item->delete();

        $this->opLog->record($request->user()?->id, 'nav', 'delete', 'NavItem', $id);

        return $this->success(null, '删除成功');
    }

    /**
     * 按类型归一化：只保留该类型该有的字段，另一类字段一律清空。
     *
     * 否则「从分类改成自定义」后会残留 category_id，占着唯一索引还让聚合错乱。
     *
     * ⚠️ 未出现在 $data 里的字段沿用 $current（局部更新语义），不能回落默认值 ——
     *    否则只改 is_active 会把 sort 洗成 0。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, ?NavItem $current = null): array
    {
        $type = $data['type'] ?? $current?->type ?? NavItem::TYPE_CUSTOM;
        $isCategory = $type === NavItem::TYPE_CATEGORY;

        return [
            'type' => $type,
            'title' => $isCategory ? null : ($data['title'] ?? $current?->title),
            'url' => $isCategory ? null : ($data['url'] ?? $current?->url),
            'category_id' => $isCategory ? ($data['category_id'] ?? $current?->category_id) : null,
            'target' => $isCategory ? '_self' : ($data['target'] ?? $current?->target ?? '_self'),
            'is_active' => (bool) ($data['is_active'] ?? $current?->is_active ?? true),
            'sort' => $data['sort'] ?? $current?->sort ?? 0,
        ];
    }

    /** 分类存在性：⚠️ 排除软删（exists 规则直接查表，默认不认 SoftDeletes） */
    private function categoryRule(): Exists
    {
        return Rule::exists('categories', 'id')->where(fn ($q) => $q->whereNull('deleted_at'));
    }

    /** 该分类是否已被其它条目登记（唯一索引的友好前置校验） */
    private function findCategoryConflict(int $categoryId, ?int $excludeId = null): ?NavItem
    {
        if ($categoryId <= 0) {
            return null;
        }

        return NavItem::query()
            ->where('category_id', $categoryId)
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->first();
    }

    /** 新条目默认排到末尾（sort 越小越靠后） */
    private function nextSort(): int
    {
        $min = NavItem::query()->min('sort');

        return $min === null ? 0 : (int) $min - 10;
    }
}
