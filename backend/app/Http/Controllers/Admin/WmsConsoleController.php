<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * 后台履约中心共用下拉数据（WMS 计划 P6 / Step 1）
 *
 * 仓库下拉是「发货单 / 退货入库单 / 库存差异」三个页面的共同筛选条件，但
 * `GET /admin/wms/warehouses` 挂在 `wms.config.manage`（配置治理）下——只读运营
 * 或退货管理员不该因为要筛个仓库就去碰配置。故此接口单独放行
 * `wms.order.view | wms.return.manage | wms.config.manage` 任一，且只返回 id/name。
 */
class WmsConsoleController extends Controller
{
    use ApiResponse;

    /** GET /api/admin/wms/warehouse-options —— 仓库下拉（id + 名称） */
    public function warehouseOptions(): JsonResponse
    {
        return $this->success(
            Warehouse::query()->orderBy('id')->get(['id', 'name'])
                ->map(fn (Warehouse $w) => ['id' => $w->id, 'name' => $w->name])
                ->values()
                ->all(),
        );
    }
}
