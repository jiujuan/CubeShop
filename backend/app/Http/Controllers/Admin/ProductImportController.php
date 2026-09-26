<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Services\Common\OperationLogService;
use App\Services\Product\ProductImportService;
use App\Support\ApiResponse;
use App\Support\ImportRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 商品批量导入（V1）
 *
 * 与批量发货（BatchShipController）同构：预校验 → 全部行通过才单事务执行 → 失败明细返回。
 * 单次行数上限见 {@see ImportRules}（create 300 / update 1000），超限整批拒绝。
 */
class ProductImportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProductImportService $imports,
        private readonly OperationLogService $operationLog,
    ) {}

    /**
     * 模板下载（xlsx：主表 + 填写说明 + 分类对照 + 品牌对照）
     * GET /admin/products/import/template?mode=create|update
     */
    public function template(Request $request): BinaryFileResponse
    {
        $mode = $this->resolveMode($request);

        $path = tempnam(sys_get_temp_dir(), 'pimport').'.xlsx';
        $this->generateTemplate($path, $mode);

        return response()
            ->download($path, sprintf('商品导入模板-%s.xlsx', $mode === ImportRules::MODE_UPDATE ? '更新' : '新建'))
            ->deleteFileAfterSend(true);
    }

    /**
     * 导入执行
     * POST /admin/products/import  (multipart: file, mode)
     */
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx,xls'],
            'mode' => ['nullable', 'string', Rule::in(ImportRules::MODES)],
        ]);

        $mode = $data['mode'] ?? ImportRules::MODE_CREATE;

        // PhpSpreadsheet 直读（formatData=false 保留原始值，避免编码被 Excel 格式化成科学计数）
        $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
        $spreadsheet->disconnectWorksheets();

        if (count($rows) < 2) {
            return $this->fail('文件为空或缺少数据行', 40000);
        }

        $header = array_map(fn ($h): string => trim((string) $h), array_slice($rows[0], 0, count(ImportRules::PRODUCT_HEADERS)));
        if ($header !== ImportRules::PRODUCT_HEADERS) {
            return $this->fail('表头不符合模板，请先下载模板再填写（要求：'.implode('、', ImportRules::PRODUCT_HEADERS).'）', 40000);
        }

        $records = $this->imports->parse($rows);

        $maxRows = ImportRules::maxRows($mode);
        if (count($records) > $maxRows) {
            return $this->fail(sprintf('单次最多 %d 行，当前 %d 行，请拆分文件后分次导入', $maxRows, count($records)), 40000);
        }

        $result = $this->imports->validate($mode, $records);

        if ($result['failed'] !== []) {
            // 预校验失败：全量不执行
            return $this->fail('校验未全部通过，未导入任何数据', 40000, [
                'mode' => $mode,
                'total' => count($records),
                'success' => 0,
                'failed' => $result['failed'],
            ]);
        }

        $stat = $this->imports->execute($mode, $result['valid'], $request->user()->id);

        $this->operationLog->record($request->user()->id, 'product', 'import_'.$mode, 'Product', 0, [
            'filename' => $request->file('file')->getClientOriginalName(),
            'total' => count($records),
            'rows' => $stat['rows'],
            'products' => $stat['products'],
        ]);

        $message = $mode === ImportRules::MODE_UPDATE
            ? sprintf('导入完成：更新 %d 个 SKU', $stat['rows'])
            : sprintf('导入完成：新建 %d 个商品、共 %d 个 SKU', $stat['products'], $stat['rows']);

        return $this->success([
            'mode' => $mode,
            'total' => count($records),
            'success' => $stat['rows'],
            'products' => $stat['products'],
            'failed' => [],
        ], $message);
    }

    private function resolveMode(Request $request): string
    {
        $mode = (string) $request->query('mode', ImportRules::MODE_CREATE);

        return in_array($mode, ImportRules::MODES, true) ? $mode : ImportRules::MODE_CREATE;
    }

    /** 生成模板：主表 + 填写说明 + 分类对照 + 品牌对照 */
    private function generateTemplate(string $path, string $mode): void
    {
        $spreadsheet = new Spreadsheet();

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('商品导入');
        $sheet->fromArray(ImportRules::PRODUCT_HEADERS, null, 'A1');
        $sheet->fromArray($this->sampleRows($mode), null, 'A2');

        $this->fillNotesSheet($spreadsheet);
        $this->fillDictionarySheets($spreadsheet);

        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function sampleRows(string $mode): array
    {
        if ($mode === ImportRules::MODE_UPDATE) {
            return [
                ['', '', '', '', '', '', '', '', '', '', 'CS-1-1', '', '89.00', '80', '启用'],
                ['', '', '', '', '', '', '', '', '', '', 'CS-1-2', '', '99.00', '', '停用'],
            ];
        }

        // 同一商品编码的多行 = 同一商品的多个规格；第二行起商品级字段可留空
        return [
            ['P0001', '示例商品-纯棉T恤', '夏季新款', '男装', '示例品牌', '', '支持 Markdown 的商品详情', '上架', '200', '0', 'SKU0001', '颜色:白色|尺码:M', '99.00', '100', '启用'],
            ['P0001', '', '', '', '', '', '', '', '', '', 'SKU0002', '颜色:黑色|尺码:L', '109.00', '50', '启用'],
        ];
    }

    private function fillNotesSheet(Spreadsheet $spreadsheet): void
    {
        $notes = [
            ['填写说明'],
            ['1. '.ImportRules::limitNote()],
            ['2. 表头禁止修改、删除或调整列序，否则导入会被拒绝'],
            ['3. 「新建商品」模式：同一商品编码（编码留空时按商品标题）的多行会合并为一个商品，每行生成一个 SKU'],
            ['4. 「更新」模式：只认 SKU编码 / 销售价 / 库存 / SKU状态 四列，其余列忽略；未填写的列保持原值'],
            ['5. 分类可填名称或 ID；名称存在同名分类时会提示改填 ID（见「分类对照」页）'],
            ['6. 规格写法：颜色:白色|尺码:M；单规格商品留空即可'],
            ['7. 主图请填写已上传的相对路径（如 products/2026/09/xx.jpg），暂不支持外链图片自动下载'],
            ['8. 任一行为校验失败，整批都不会导入，可按返回的失败明细修改后重传'],
        ];

        $notesSheet = $spreadsheet->createSheet();
        $notesSheet->setTitle('填写说明');
        $notesSheet->fromArray($notes, null, 'A1');
    }

    private function fillDictionarySheets(Spreadsheet $spreadsheet): void
    {
        // 分类：parent_id=0 为一级，其余为二级；输出「父/子」便于区分同名二级分类
        $parents = Category::where('parent_id', 0)->orderBy('sort')->get(['id', 'name'])->keyBy('id');
        $children = Category::where('parent_id', '>', 0)->orderBy('sort')->get(['id', 'name', 'parent_id']);

        $categoryRows = [];
        foreach ($parents as $parent) {
            $categoryRows[] = [(int) $parent->id, $parent->name];
            foreach ($children->where('parent_id', $parent->id) as $child) {
                $categoryRows[] = [(int) $child->id, $parent->name.' / '.$child->name];
            }
        }

        $categorySheet = $spreadsheet->createSheet();
        $categorySheet->setTitle('分类对照');
        $categorySheet->fromArray(['分类ID', '分类名称'], null, 'A1');
        $categorySheet->fromArray($categoryRows, null, 'A2');

        $brandSheet = $spreadsheet->createSheet();
        $brandSheet->setTitle('品牌对照');
        $brandSheet->fromArray(['品牌ID', '品牌名称'], null, 'A1');
        $brandSheet->fromArray(
            Brand::orderBy('sort')->get(['id', 'name'])->map(fn ($b) => [(int) $b->id, $b->name])->all(),
            null,
            'A2',
        );
    }
}
