<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\CategoryAttribute;
use App\Support\ApiResponse;
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
            'category_id' => ['nullable', 'integer'],
            'filterable' => ['nullable', 'in:0,1'],
            'type' => ['nullable', 'in:spec,param'],
        ]);

        $query = Attribute::query()->with('values');

        if (! empty($data['category_id'])) {
            $order = CategoryAttribute::where('category_id', $data['category_id'])
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
        if (! empty($data['category_id']) && isset($order)) {
            $list = $list->sortBy(fn ($item) => array_search($item['id'], $order, true))->values();
        }

        return $this->success($list);
    }

    /** 品牌列表（在售品牌） */
    public function brands(): JsonResponse
    {
        $list = Brand::query()
            ->enabled()
            ->orderByDesc('sort')->orderBy('id')
            ->get(['id', 'name', 'logo'])
            ->map(fn (Brand $b) => ['id' => $b->id, 'name' => $b->name, 'logo' => $b->logo])
            ->values();

        return $this->success($list);
    }
}
