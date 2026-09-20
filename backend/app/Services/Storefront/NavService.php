<?php

namespace App\Services\Storefront;

use App\Models\Category;
use App\Models\NavItem;

/**
 * 前台顶部导航聚合（导航可管理化）
 *
 * 后台存的是「编排」(nav_items)，这里按 sort 排序后**一次性展开成可渲染的最终列表**，
 * 前端拿到即渲染，不做任何合并 —— 后续新增条目类型时前端不必跟着改。
 *
 * 两类条目的展开规则：
 * - `category`：**引用**分类，标题与链接当场从分类派生（不缓存，改名即跟随）；
 *   分类被软删 / 停用 / 已不存在 → **静默跳过**，不返回空壳项
 *   （分类恢复启用后自动回来，运营排的位置不会丢）
 * - `custom`：直出 title / url / target
 */
class NavService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        $items = NavItem::query()
            ->where('is_active', true)
            ->orderByDesc('sort')
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            return [];
        }

        $categoryIds = $items
            ->filter(fn (NavItem $item) => $item->isCategory() && $item->category_id !== null)
            ->pluck('category_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        // ⚠️ Category 用 SoftDeletes：这里不加 withTrashed，软删分类自然查不到 → 走跳过分支
        $categories = $categoryIds === []
            ? collect()
            : Category::query()
                ->whereIn('id', $categoryIds)
                ->where('status', 1)
                ->get(['id', 'public_id', 'name'])
                ->keyBy('id');

        $out = [];

        foreach ($items as $item) {
            if ($item->isCategory()) {
                $category = $categories->get((int) $item->category_id);
                if (! $category) {
                    continue; // 失效引用：软删 / 停用 / 不存在
                }

                $out[] = [
                    'id' => $item->id,
                    'type' => NavItem::TYPE_CATEGORY,
                    'title' => $category->name,
                    'url' => '/category/'.$category->public_id,
                    'target' => '_self',
                    // 前端高亮用：与现有分类页路由 /category/{public_id} 同口径
                    'category_public_id' => $category->public_id,
                ];

                continue;
            }

            $out[] = [
                'id' => $item->id,
                'type' => NavItem::TYPE_CUSTOM,
                'title' => (string) $item->title,
                'url' => (string) $item->url,
                // ⚠️ category 型恒为站内，custom 型按后台设置直出
                'target' => in_array($item->target, NavItem::TARGETS, true) ? $item->target : '_self',
            ];
        }

        return $out;
    }
}
