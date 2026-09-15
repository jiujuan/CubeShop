<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\CategoryAttribute;
use App\Models\ProductSku;
use App\Models\ProductAttributeValue;
use App\Services\Common\OperationLogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 属性库管理（V1.1 E01 / T-008）
 * 权限：product.update
 */
class AttributeController extends Controller
{
    use ApiResponse;

    public function __construct(private OperationLogService $opLog)
    {
    }

    /** 属性列表（含属性值），可按类型 / 可筛标记过滤 */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', 'in:spec,param'],
            'is_filterable' => ['nullable', 'in:0,1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $paginator = Attribute::query()
            ->with('values')
            ->when($data['keyword'] ?? null, fn ($q, $kw) => $q->where('name', 'like', '%'.$kw.'%'))
            ->when($data['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when(isset($data['is_filterable']), fn ($q) => $q->where('is_filterable', (bool) $data['is_filterable']))
            ->orderByDesc('sort')->orderBy('id')
            ->paginate(min($data['page_size'] ?? 50, 200), ['*'], 'page', $data['page'] ?? 1);

        $paginator->through(fn (Attribute $a) => $this->format($a));

        return $this->paginated($paginator);
    }

    public function show(int $id): JsonResponse
    {
        $attribute = Attribute::with('values')->find($id);
        if (! $attribute) {
            throw BusinessException::notFound('属性不存在');
        }

        return $this->success($this->format($attribute));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:attributes,name'],
            'type' => ['required', 'in:spec,param'],
            'is_filterable' => ['nullable', 'boolean'],
            'is_multiple' => ['nullable', 'boolean'],
            'allow_custom' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'integer'],
            'values' => ['nullable', 'array'],
            'values.*' => ['string', 'max:64'],
        ]);

        $attribute = DB::transaction(function () use ($data) {
            $attribute = Attribute::create([
                'name' => $data['name'],
                'type' => $data['type'],
                'is_filterable' => $data['is_filterable'] ?? false,
                'is_multiple' => $data['is_multiple'] ?? false,
                'allow_custom' => $data['allow_custom'] ?? false,
                'sort' => $data['sort'] ?? 0,
            ]);

            $this->syncValues($attribute, $data['values'] ?? []);

            return $attribute;
        });

        $this->opLog->record($request->user()?->id, 'attribute', 'create', 'Attribute', $attribute->id, ['name' => $attribute->name]);

        return $this->success(['id' => $attribute->id], '创建成功');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $attribute = Attribute::find($id);
        if (! $attribute) {
            throw BusinessException::notFound('属性不存在');
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:50', 'unique:attributes,name,'.$id],
            'type' => ['sometimes', 'in:spec,param'],
            'is_filterable' => ['nullable', 'boolean'],
            'is_multiple' => ['nullable', 'boolean'],
            'allow_custom' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'integer'],
        ]);

        // 已参与 SKU 规格的属性不允许把类型从 spec 改为 param（会破坏既有 SKU 语义）
        if (isset($data['type']) && $data['type'] !== $attribute->type) {
            if ($attribute->type === Attribute::TYPE_SPEC && $this->specKeyInUse($attribute->name)) {
                throw BusinessException::conflict('该属性已在 SKU 规格中使用，不能修改类型');
            }
        }

        $before = $attribute->only(['name', 'type', 'is_filterable', 'is_multiple', 'sort']);
        $attribute->fill($data)->save();

        $this->opLog->record($request->user()?->id, 'attribute', 'update', 'Attribute', $id, [
            'before' => $before,
            'after' => $attribute->only(['name', 'type', 'is_filterable', 'is_multiple', 'sort']),
        ]);

        return $this->success(null, '更新成功');
    }

    /** 删除（被分类模板 / 商品参数 / SKU 规格引用时拒绝） */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $attribute = Attribute::find($id);
        if (! $attribute) {
            throw BusinessException::notFound('属性不存在');
        }

        $templates = CategoryAttribute::where('attribute_id', $id)->count();
        if ($templates > 0) {
            throw BusinessException::conflict(sprintf('该属性已被 %d 个分类模板引用，无法删除', $templates));
        }

        $productValues = ProductAttributeValue::where('attribute_id', $id)->count();
        if ($productValues > 0) {
            throw BusinessException::conflict(sprintf('该属性已被 %d 个商品参数引用，无法删除', $productValues));
        }

        if ($attribute->type === Attribute::TYPE_SPEC && $this->specKeyInUse($attribute->name)) {
            throw BusinessException::conflict('该属性已在 SKU 规格中使用，无法删除');
        }

        $attribute->delete();
        $this->opLog->record($request->user()?->id, 'attribute', 'delete', 'Attribute', $id, ['name' => $attribute->name]);

        return $this->success(null, '删除成功');
    }

    // ---------- 属性值 ----------

    public function values(Request $request, int $id): JsonResponse
    {
        $attribute = Attribute::find($id);
        if (! $attribute) {
            throw BusinessException::notFound('属性不存在');
        }

        return $this->success(
            $attribute->values()->get()->map(fn (AttributeValue $v) => [
                'id' => $v->id,
                'value' => $v->value,
                'sort' => $v->sort,
            ])->all(),
        );
    }

    public function storeValue(Request $request, int $id): JsonResponse
    {
        $attribute = Attribute::find($id);
        if (! $attribute) {
            throw BusinessException::notFound('属性不存在');
        }

        $data = $request->validate([
            'value' => ['required', 'string', 'max:64'],
            'sort' => ['nullable', 'integer'],
        ]);

        $exists = AttributeValue::where('attribute_id', $id)->where('value', $data['value'])->exists();
        if ($exists) {
            throw BusinessException::conflict('该属性值已存在');
        }

        $value = AttributeValue::create([
            'attribute_id' => $id,
            'value' => $data['value'],
            'sort' => $data['sort'] ?? 0,
        ]);

        return $this->success(['id' => $value->id], '添加成功');
    }

    public function updateValue(Request $request, int $id, int $valueId): JsonResponse
    {
        $value = AttributeValue::where('attribute_id', $id)->find($valueId);
        if (! $value) {
            throw BusinessException::notFound('属性值不存在');
        }

        $data = $request->validate([
            'value' => ['sometimes', 'string', 'max:64'],
            'sort' => ['nullable', 'integer'],
        ]);

        if (isset($data['value']) && $data['value'] !== $value->value) {
            $dup = AttributeValue::where('attribute_id', $id)->where('value', $data['value'])->exists();
            if ($dup) {
                throw BusinessException::conflict('该属性值已存在');
            }
        }

        $value->fill($data)->save();

        return $this->success(null, '更新成功');
    }

    /** 删除属性值（被商品参数或 SKU 规格引用时拒绝） */
    public function destroyValue(Request $request, int $id, int $valueId): JsonResponse
    {
        $value = AttributeValue::where('attribute_id', $id)->find($valueId);
        if (! $value) {
            throw BusinessException::notFound('属性值不存在');
        }

        $used = ProductAttributeValue::where('attribute_id', $id)->where('value', $value->value)->count();
        if ($used > 0) {
            throw BusinessException::conflict(sprintf('该属性值已被 %d 个商品参数引用，无法删除', $used));
        }

        if ($this->specValueInUse($value->value)) {
            throw BusinessException::conflict('该属性值已在 SKU 规格中使用，无法删除');
        }

        $value->delete();

        return $this->success(null, '删除成功');
    }

    /** 批量保存属性值（覆盖语义：不在列表中的值若未被引用则删除） */
    public function batchValues(Request $request, int $id): JsonResponse
    {
        $attribute = Attribute::find($id);
        if (! $attribute) {
            throw BusinessException::notFound('属性不存在');
        }

        $data = $request->validate([
            'values' => ['required', 'array'],
            'values.*' => ['string', 'max:64'],
        ]);

        $result = DB::transaction(fn () => $this->syncValues($attribute, $data['values']));

        return $this->success($result, '保存成功');
    }

    // ---------- internals ----------

    /**
     * 同步属性值（幂等）：
     * - 新增缺失值；
     * - 已存在的跳过；
     * - 列表移除的值：被引用则保留并计入 retained，未被引用则删除。
     *
     * @param  array<int, string>  $values
     * @return array{created:int, removed:int, retained:array<int, string>}
     */
    private function syncValues(Attribute $attribute, array $values): array
    {
        $values = array_values(array_unique(array_filter(array_map('trim', $values), fn ($v) => $v !== '')));
        $created = 0;

        foreach ($values as $idx => $value) {
            $existing = AttributeValue::where('attribute_id', $attribute->id)->where('value', $value)->first();
            if ($existing) {
                $existing->sort = count($values) - $idx;
                $existing->save();

                continue;
            }
            AttributeValue::create([
                'attribute_id' => $attribute->id,
                'value' => $value,
                'sort' => count($values) - $idx,
            ]);
            $created++;
        }

        $removed = 0;
        $retained = [];
        $stale = AttributeValue::where('attribute_id', $attribute->id)->whereNotIn('value', $values)->get();
        foreach ($stale as $item) {
            $inUse = ProductAttributeValue::where('attribute_id', $attribute->id)->where('value', $item->value)->exists()
                || $this->specValueInUse($item->value);

            if ($inUse) {
                $retained[] = $item->value;

                continue;
            }

            $item->delete();
            $removed++;
        }

        return ['created' => $created, 'removed' => $removed, 'retained' => $retained];
    }

    /** 维度名（属性名）是否已在 SKU specs 的 key 中出现 */
    private function specKeyInUse(string $name): bool
    {
        return $this->specScan(fn (array $specs) => array_key_exists($name, $specs));
    }

    /** 值是否已在 SKU specs 的任一 value 中出现 */
    private function specValueInUse(string $value): bool
    {
        return $this->specScan(fn (array $specs) => in_array($value, array_values($specs), true));
    }

    /** 扫描全部 SKU 的 specs（数据量可控：仅用于删除前的引用校验） */
    private function specScan(callable $predicate): bool
    {
        foreach (ProductSku::query()->select(['id', 'specs'])->cursor() as $sku) {
            $specs = $sku->specs;
            if (is_array($specs) && $predicate($specs)) {
                return true;
            }
        }

        return false;
    }

    private function format(Attribute $attribute): array
    {
        return [
            'id' => $attribute->id,
            'name' => $attribute->name,
            'type' => $attribute->type,
            'type_label' => Attribute::TYPE_LABELS[$attribute->type] ?? $attribute->type,
            'is_filterable' => $attribute->is_filterable,
            'is_multiple' => $attribute->is_multiple,
            'allow_custom' => $attribute->allow_custom,
            'sort' => $attribute->sort,
            'values' => $attribute->relationLoaded('values')
                ? $attribute->values->map(fn (AttributeValue $v) => [
                    'id' => $v->id,
                    'value' => $v->value,
                    'sort' => $v->sort,
                ])->values()->all()
                : [],
        ];
    }
}
