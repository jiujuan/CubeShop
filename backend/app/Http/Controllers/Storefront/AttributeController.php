<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\CategoryAttribute;
use App\Models\CategoryBrand;
use App\Support\ApiResponse;
use App\Support\PublicId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 前台只读：品牌与属性（V1.1 E01 / T-008）
 *
 * 供详情页参数表、列表页筛选面板使用；无需登录。
 */
class AttributeController extends Controller
{
    use ApiResponse;

    /**
     * 属性列表
     * GET /api/attributes?category_id=&filterable=1
     *
     * - 传 category_id：只返回该分类模板中的属性（顺序按模板 sort）
     * - filterable=1：只返回可用于筛选的属性
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['nullable'],
            'filterable' => ['nullable', 'in:0,1'],
            'type' => ['nullable', 'in:spec,param'],
        ]);

        // P2-11：category_id 接受 public_id 或历史 int 主键
        $categoryId = isset($data['category_id']) && $data['category_id'] !== ''
            ? PublicId::resolve(PublicId::SCOPE_CATEGORY, $data['category_id'])
            : null;

        $query = Attribute::query()->with('values');

        if ($categoryId !== null) {
            $order = CategoryAttribute::where('category_id', $categoryId)
                ->orderByDesc('sort')->orderBy('id')
                ->pluck('attribute_id')
                ->all();

            if ($order === []) {
                // 该分类未配置模板 → 返回空（前端据此不渲染筛选面板）
                return $this->success([]);
            }

            $query->whereIn('id', $order);
        }

        if (($data['filterable'] ?? null) === '1') {
            $query->where('is_filterable', true);
        }

        if (! empty($data['type'])) {
            $query->where('type', $data['type']);
        }

        $list = $query->orderByDesc('sort')->orderBy('id')->get()
            ->map(fn (Attribute $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type,
                'is_filterable' => $a->is_filterable,
                'is_multiple' => $a->is_multiple,
                'values' => $a->values->map(fn (AttributeValue $v) => [
                    'id' => $v->id,
                    'value' => $v->value,
                ])->values()->all(),
            ])
            ->values();

        // 按分类模板顺序重排（保持与后台配置一致）
        if ($categoryId !== null && isset($order)) {
            $list = $list->sortBy(fn ($item) => array_search($item['id'], $order, true))->values();
        }

        return $this->success($list);
    }

    /**
     * 品牌列表（在售品牌；P2-11：对外只暴露 public_id）
     *
     * 传 category_id：只返回后台为该分类配置的品牌（category_brands，按配置排序）；
     * 该分类未配置品牌则返回空数组。
     * 品牌与分类是两个**正交**维度、互不隶属，此处过滤仅用于前台按分类收敛品牌范围。
     */
    public function brands(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['nullable'],
        ]);

        // P2-11：category_id 接受 public_id 或历史 int 主键
        $categoryId = isset($data['category_id']) && $data['category_id'] !== ''
            ? PublicId::resolve(PublicId::SCOPE_CATEGORY, $data['category_id'])
            : null;

        if ($categoryId !== null) {
            $list = CategoryBrand::with('brand')
                ->where('category_id', $categoryId)
                ->orderByDesc('sort')->orderBy('id')
                ->get()
                ->map(fn (CategoryBrand $row) => $row->brand)
                ->filter(fn (?Brand $b) => $b !== null && (int) $b->status === 1)
                ->map(fn (Brand $b) => ['id' => $b->public_id, 'name' => $b->name, 'logo' => $b->logo])
                ->values();

            return $this->success($list);
        }

        $list = Brand::query()
            ->enabled()
            ->orderByDesc('sort')->orderBy('id')
            ->get(['id', 'name', 'logo', 'public_id'])
            ->map(fn (Brand $b) => ['id' => $b->public_id, 'name' => $b->name, 'logo' => $b->logo])
            ->values();

        return $this->success($list);
    }
}
