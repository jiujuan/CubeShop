<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Common\OperationLogService;
use App\Services\Order\BatchShipService;
use App\Support\ApiResponse;
use App\Support\ShippingRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 批量发货（V1.1 T-044，E03）
 *
 * 策略：预校验 → 全部行通过才执行（单事务）；失败行明细返回（前端可下载清单）。
 * 单次上限 500 行（ShippingRules::BATCH_SHIP_MAX_ROWS）。
 */
class BatchShipController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BatchShipService $batchShip,
        private readonly OperationLogService $operationLog,
    ) {}

    /**
     * 批量发货导入
     * POST /admin/orders/batch-ship  (multipart: file)
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx,xls'],
        ]);

        // PhpSpreadsheet 直读（formatData=false 保留原始值，避免单号被 Excel 格式化）
        $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
        $spreadsheet->disconnectWorksheets();

        if (count($rows) < 2) {
            return $this->fail('文件为空或缺少数据行', 40000);
        }

        // 表头校验（列序固定）
        $header = array_map(fn ($h) => trim((string) $h), array_slice($rows[0], 0, 3));
        if ($header !== ShippingRules::BATCH_SHIP_HEADERS) {
            return $this->fail('表头不符合模板（要求：'.implode('、', ShippingRules::BATCH_SHIP_HEADERS).'）', 40000);
        }

        $dataRows = array_slice($rows, 1);
        if (count($dataRows) > ShippingRules::BATCH_SHIP_MAX_ROWS) {
            return $this->fail('单次最多 '.ShippingRules::BATCH_SHIP_MAX_ROWS.' 行，当前 '.count($dataRows).' 行', 40000);
        }

        $result = $this->batchShip->validateRows($dataRows);

        if (! empty($result['failed'])) {
            // 预校验失败：全量不执行，返回失败明细
            return $this->fail('校验未全部通过，未执行任何发货', 40000, [
                'success' => 0,
                'total' => count($dataRows),
                'failed' => $result['failed'],
            ]);
        }

        $success = $this->batchShip->execute($result['valid'], $request->user()->id);

        $this->operationLog->record($request->user()->id, 'order', 'batch_ship', 'order', 0, [
            'filename' => $request->file('file')->getClientOriginalName(),
            'total' => count($dataRows),
            'success' => $success,
        ]);

        return $this->success([
            'success' => $success,
            'total' => count($dataRows),
            'failed' => [],
        ], "批量发货完成：成功 {$success} 单");
    }

    /**
     * 模板下载（xlsx，含示例行 + 快递公司编码对照 sheet）
     * GET /admin/orders/batch-ship/template
     */
    public function template(): BinaryFileResponse
    {
        $path = storage_path('app/batch-ship-template.xlsx');

        if (! is_file($path)) {
            $this->generateTemplate($path);
        }

        return response()->download($path, '批量发货模板.xlsx');
    }

    /** 生成模板（表头 + 示例行 + 快递公司对照 sheet） */
    private function generateTemplate(string $path): void
    {
        $companies = DB::table('express_companies')->where('status', 1)->orderBy('sort')->get(['code', 'name']);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('批量发货');
        $sheet->fromArray(ShippingRules::BATCH_SHIP_HEADERS, null, 'A1');
        $sheet->fromArray([['CS20260917000001', 'SF', 'SF0000000001'], ['CS20260917000002', 'ZTO', 'ZTO0000000002']], null, 'A2');

        $dict = $spreadsheet->createSheet();
        $dict->setTitle('快递公司编码对照');
        $dict->fromArray(['编码', '名称'], null, 'A1');
        $dict->fromArray($companies->map(fn ($c) => [$c->code, $c->name])->all(), null, 'A2');

        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();
    }
}
