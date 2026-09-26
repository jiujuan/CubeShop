<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\InventoryCheck;
use App\Models\InventoryCheckItem;
use App\Models\SysUser;
use App\Services\Inventory\InventoryCheckService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 库存盘点（后台）
 *
 * 权限分两档（有意设计的职责分离）：
 * - inventory.check：建单 / 实盘录入 / 导入导出 / 作废（盘点员）
 * - inventory.manage：过账（会直接改写库存，与手工调整库存同一授权口径）
 *
 * ⚠️ 盘点不冻结库存：开单到过账期间的正常出入库照常发生，
 *    过账按「实时库存」计算差异，开单快照仅供展示。
 */
class InventoryCheckController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly InventoryCheckService $checks) {}

    /**
     * 列表：GET /admin/inventory-checks
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'check_no' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'string', Rule::in(array_keys(InventoryCheck::STATUS_LABELS))],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = InventoryCheck::query()
            ->when($data['check_no'] ?? null, fn ($q, $v) => $q->where('check_no', 'like', '%'.$v.'%'))
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id');

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate(
            $data['page_size'] ?? 20,
            ['*'],
            'page',
            $data['page'] ?? 1,
        );

        $paginator->through(fn (InventoryCheck $check) => $this->row($check));

        return $this->paginated($paginator);
    }

    /**
     * 建单：POST /admin/inventory-checks
     *
     * custom 模式可带 file（xlsx，第一列为 SKU 编码）作为选品清单。
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:128'],
            'scope_type' => ['required', 'string', Rule::in(array_keys(InventoryCheck::SCOPE_LABELS))],
            'scope_value' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string', 'max:500'],
            'file' => ['nullable', 'file', 'extensions:xlsx,xls'],
        ]);

        $skuCodes = [];
        if (($data['scope_type'] ?? null) === InventoryCheck::SCOPE_CUSTOM) {
            if (! $request->hasFile('file')) {
                return $this->fail('自定义清单模式必须上传含 SKU 编码的 xlsx 文件', 40000);
            }
            $skuCodes = $this->parseSkuCodeFile($request->file('file')->getRealPath());
        }

        try {
            $check = $this->checks->create($data, $request->user()->id, $skuCodes);
        } catch (BusinessException $e) {
            return $this->fail($e->getMessage(), 40000);
        }

        return $this->success($this->row($check), '盘点单已创建');
    }

    /**
     * 详情（含明细分页）：GET /admin/inventory-checks/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $check = InventoryCheck::find($id);
        if (! $check) {
            return $this->fail('盘点单不存在', 40004);
        }

        $data = $request->validate([
            'item_status' => ['nullable', 'string', Rule::in(array_keys(InventoryCheckItem::STATUS_LABELS))],
            'only_diff' => ['nullable', 'in:1'],
            'keyword' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $check->items()
            ->when($data['item_status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(isset($data['only_diff']), fn ($q) => $q
                ->whereNotNull('counted_qty')
                ->whereRaw('counted_qty <> system_qty'))
            ->when($data['keyword'] ?? null, fn ($q, $v) => $q
                ->where(function ($sub) use ($v): void {
                    // ⚠️ PG 的 LIKE 大小写敏感：跨库一致必须两侧包 lower()
                    $sub->whereRaw('lower(sku_code) LIKE ?', ['%'.mb_strtolower($v).'%'])
                        ->orWhereRaw('lower(product_title) LIKE ?', ['%'.mb_strtolower($v).'%']);
                }))
            ->orderBy('id');

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate(
            $data['page_size'] ?? 20,
            ['*'],
            'page',
            $data['page'] ?? 1,
        );

        $paginator->through(fn (InventoryCheckItem $item) => [
            'id' => $item->id,
            'sku_id' => $item->sku_id,
            'sku_code' => $item->sku_code,
            'product_title' => $item->product_title,
            'specs_text' => $item->specs_text,
            'system_qty' => $item->system_qty,
            'locked_qty' => $item->locked_qty,
            'counted_qty' => $item->counted_qty,
            'diff_qty' => $item->diff_qty,
            'status' => $item->status,
            'status_label' => InventoryCheckItem::STATUS_LABELS[$item->status] ?? $item->status,
            'remark' => $item->remark,
        ]);

        return $this->success([
            'check' => $this->row($check),
            'items' => $this->paginated($paginator)->getData(true)['data'],
        ]);
    }

    /**
     * 录入实盘：POST /admin/inventory-checks/{id}/count
     * body: { items: [{ item_id, counted_qty, remark? }] }
     */
    public function count(Request $request, int $id): JsonResponse
    {
        $check = InventoryCheck::find($id);
        if (! $check) {
            return $this->fail('盘点单不存在', 40004);
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.counted_qty' => ['required', 'integer', 'min:0'],
            'items.*.remark' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->checks->record($check, $data['items'], $request->user()->id);
        } catch (BusinessException $e) {
            return $this->fail($e->getMessage(), 40000);
        }

        return $this->success($result, '实盘已保存');
    }

    /**
     * 导入实盘：POST /admin/inventory-checks/{id}/import
     * 文件列：SKU编码 | 实盘数量 | 备注
     */
    public function import(Request $request, int $id): JsonResponse
    {
        $check = InventoryCheck::find($id);
        if (! $check) {
            return $this->fail('盘点单不存在', 40004);
        }

        $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx,xls'],
        ]);

        $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
        $spreadsheet->disconnectWorksheets();

        if (count($rows) < 2) {
            return $this->fail('文件为空或缺少数据行', 40000);
        }

        try {
            $result = $this->checks->importCounted($check, array_slice($rows, 1));
        } catch (BusinessException $e) {
            return $this->fail($e->getMessage(), 40000);
        }

        if ($result['failed'] !== []) {
            return $this->fail('校验未全部通过，未写入任何实盘数量', 40000, [
                'updated' => 0,
                'failed' => $result['failed'],
            ]);
        }

        return $this->success($result, sprintf('已导入 %d 行实盘数量', $result['updated']));
    }

    /**
     * 导出明细（CSV）：GET /admin/inventory-checks/{id}/export
     */
    public function export(int $id): StreamedResponse
    {
        $check = InventoryCheck::findOrFail($id);
        $rows = $this->checks->exportRows($check);
        $filename = $check->check_no.'.csv';

        return response()->streamDownload(function () use ($rows): void {
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * 过账：POST /admin/inventory-checks/{id}/post（权限 inventory.manage）
     */
    public function post(Request $request, int $id): JsonResponse
    {
        $check = InventoryCheck::find($id);
        if (! $check) {
            return $this->fail('盘点单不存在', 40004);
        }

        try {
            $result = $this->checks->post($check, $request->user()->id);
        } catch (BusinessException $e) {
            return $this->fail($e->getMessage(), 40000);
        }

        $message = sprintf('过账完成：调整 %d 个 SKU、无差异 %d 个', $result['adjusted'], $result['unchanged']);
        if ($result['skipped'] > 0) {
            $message .= sprintf('，%d 个因可用库存不足已跳过', $result['skipped']);
        }

        return $this->success($result + ['check' => $this->row($check->fresh())], $message);
    }

    /**
     * 作废：POST /admin/inventory-checks/{id}/cancel
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $check = InventoryCheck::find($id);
        if (! $check) {
            return $this->fail('盘点单不存在', 40004);
        }

        try {
            $this->checks->cancel($check, $request->user()->id);
        } catch (BusinessException $e) {
            return $this->fail($e->getMessage(), 40000);
        }

        return $this->success(null, '盘点单已作废');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(InventoryCheck $check): array
    {
        $names = SysUser::whereIn('id', array_filter([$check->created_by, $check->posted_by]))
            ->pluck('nickname', 'id');

        return [
            'id' => $check->id,
            'check_no' => $check->check_no,
            'title' => $check->title,
            'scope_type' => $check->scope_type,
            'scope_label' => InventoryCheck::SCOPE_LABELS[$check->scope_type] ?? $check->scope_type,
            'scope_value' => $check->scope_value,
            'status' => $check->status,
            'status_label' => InventoryCheck::STATUS_LABELS[$check->status] ?? $check->status,
            'item_count' => $check->item_count,
            'counted_count' => $check->counted_count,
            'diff_count' => $check->diff_count,
            'total_diff_qty' => $check->total_diff_qty,
            'remark' => $check->remark,
            'created_by_name' => $check->created_by ? ($names[$check->created_by] ?? null) : null,
            'posted_by_name' => $check->posted_by ? ($names[$check->posted_by] ?? null) : null,
            'posted_at' => $check->posted_at?->format('Y-m-d H:i:s'),
            'created_at' => $check->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 解析选品清单文件（第一列为 SKU 编码）
     *
     * @return array<int, string>
     */
    private function parseSkuCodeFile(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
        $spreadsheet->disconnectWorksheets();

        // 表头白名单：只有这些写法才丢弃首行。
        // ⚠️ 不能宽松地用「含 sku 字样」判断——真实 SKU 编码可能就叫 SKU-xxxx，会被误当表头丢掉。
        $headers = ['sku编码', 'sku码', 'skucode', 'sku_code', '编码', 'sku'];

        $codes = [];
        foreach ($rows as $index => $row) {
            $code = trim((string) ($row[0] ?? ''));
            if ($code === '') {
                continue;
            }
            $normalized = strtolower(preg_replace('/\s+/', '', $code) ?? '');
            if ($index === 0 && in_array($normalized, $headers, true)) {
                continue;
            }
            $codes[] = $code;
        }

        return array_values(array_unique($codes));
    }
}
