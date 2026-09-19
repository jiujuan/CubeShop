<?php

namespace App\Services\Wms\Contracts;

use App\Services\Wms\Dto\CancelOutboundDto;
use App\Services\Wms\Dto\InventoryQueryDto;
use App\Services\Wms\Dto\OutboundDto;
use App\Services\Wms\Dto\ReturnInboundDto;
use App\Services\Wms\Dto\WmsResult;

/**
 * WMS 适配器契约（WMS 计划 P0 / README §3-D1、D9）
 *
 * 设计要点：
 * - **不带 tenant 参数**（CubeShop 单商户，见 README D1）；
 * - 方法粒度对齐设计文档 §7 的奇门接口，菜鸟与京东各自实现，业务层只依赖本接口；
 * - 入参一律用 DTO，避免数组散弹；出参统一 {@see WmsResult}，网络/业务失败以结果表达。
 *
 * P0 提供 Mock；P2 提供菜鸟（{@see \App\Services\Wms\Adapters\CainiaoAdapter}）；京东见 P8。
 */
interface WmsAdapter
{
    /** 服务商标识（cainiao / jd_cloud / mock） */
    public function provider(): string;

    /** 是否为 Mock 实现（后台连通性测试据此标注「Mock」） */
    public function isMock(): bool;

    /** 查询 WMS 库存（P0 用于连通性测试；P5 库存同步复用） */
    public function queryInventory(InventoryQueryDto $dto): WmsResult;

    /** 创建出库单（P2） */
    public function createOutbound(OutboundDto $dto): WmsResult;

    /**
     * 取消出库单（P2，出库前才允许）。
     *
     * P2 由 `string $bizNo` 改为 DTO：奇门的 `deliveryOrderId`（= `wms_outbound_no`）
     * 是条件必填，只给平台单号可能定位不到单据。
     */
    public function cancelOutbound(CancelOutboundDto $dto): WmsResult;

    /**
     * 主动查询单据状态（WMS 计划 P3 / Step 5，回调丢失补偿）
     *
     * 复用 {@see CancelOutboundDto}：两者都是「按平台单号 + 仓方单号定位出库单」。
     * 成功时 data 含 `status`（如 SHIPPED）与包裹/运单信息（各服务商结构，见实现）。
     */
    public function queryOutbound(CancelOutboundDto $dto): WmsResult;

    /** 创建退货入库单（P4） */
    public function createReturnInbound(ReturnInboundDto $dto): WmsResult;

    /** 取消退货入库单（P4） */
    public function cancelReturnInbound(string $bizNo): WmsResult;
}
