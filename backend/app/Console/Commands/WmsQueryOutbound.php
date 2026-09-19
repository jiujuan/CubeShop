<?php

namespace App\Console\Commands;

use App\Models\FulfillmentOrder;
use App\Models\WmsConfig;
use App\Services\Wms\Callback\Handlers\DeliveryOrderConfirmHandler;
use App\Services\Wms\Dto\CancelOutboundDto;
use App\Services\Wms\WmsAdapterFactory;
use App\Services\Wms\WmsApiLogService;
use App\Support\WmsProvider;
use Illuminate\Console\Command;

/**
 * 主动查询出库单状态（WMS 计划 P3 / Step 5，回调丢失补偿）
 *
 * 用法：`php artisan wms:query-outbound {outboundNo}`
 *
 * 场景：回调丢失 / 顺序错乱时，人工或调度按出库单号主动向 WMS 查询；
 * 仓方状态为 SHIPPED 时按 confirm 语义补录运单与包裹（复用回调处理器，零特判）。
 */
class WmsQueryOutbound extends Command
{
    protected $signature = 'wms:query-outbound
        {outboundNo : 平台出库单号（fulfillment_orders.outbound_no）}
        {--sync : 同步执行（默认立即执行，本命令本来就是同步场景）}';

    protected $description = 'WMS 主动查询出库单状态（回调丢失补偿），SHIPPED 时按回传语义补录运单';

    public function handle(
        WmsAdapterFactory $factory,
        WmsApiLogService $apiLogs,
        DeliveryOrderConfirmHandler $confirmHandler,
    ): int {
        $outboundNo = trim((string) $this->argument('outboundNo'));

        $fo = FulfillmentOrder::query()->where('outbound_no', $outboundNo)->first();
        if (! $fo) {
            $this->error("找不到发货单：{$outboundNo}");

            return self::FAILURE;
        }

        $config = WmsConfig::query()->where('warehouse_id', $fo->warehouse_id)->first();
        if (! $config) {
            $this->error("仓库 {$fo->warehouse_id} 未配置 WMS");

            return self::FAILURE;
        }

        $adapter = $factory->make($config);
        $this->info('适配器：'.($adapter->isMock() ? 'Mock' : $adapter->provider()));

        $started = microtime(true);
        $result = $adapter->queryOutbound(new CancelOutboundDto(
            warehouseId: (int) $fo->warehouse_id,
            bizNo: (string) $fo->outbound_no,
            wmsOutboundNo: $fo->wms_outbound_no ? (string) $fo->wms_outbound_no : null,
        ));

        $apiLogs->record(
            $config,
            'queryOutbound',
            $result,
            (int) round((microtime(true) - $started) * 1000),
            null,
            (string) $fo->outbound_no,
        );

        if (! $result->success) {
            $this->error("查询失败：{$result->error}");

            return self::FAILURE;
        }

        $status = (string) ($result->data['status'] ?? '');
        $this->info("仓方状态：{$status}");

        if ($status !== 'SHIPPED') {
            $this->line('非已发货状态，无需补录运单。');

            return self::SUCCESS;
        }

        if ($fo->status === FulfillmentOrder::STATUS_SHIPPED) {
            $this->line('发货单已是已发货状态，跳过补录。');

            return self::SUCCESS;
        }

        // 按 confirm 回传语义补录（复用处理器：运单落库 + 订单发货）
        $confirmHandler->handle($fo, [
            'msg_type' => 'confirm',
            'raw_status' => 'SHIPPED',
            'status_key' => 'shipped',
            'biz_no' => (string) $fo->outbound_no,
            'wms_no' => (string) ($result->data['wms_order_no'] ?? ''),
            'order' => [],
            'packages' => (array) ($result->data['packages'] ?? []),
        ], ['log_id' => null, 'provider' => WmsProvider::CAINIAO]);

        $fo->refresh();
        $this->info("已补录：发货单 → {$fo->status}，运单号 {$fo->tracking_no}");

        return self::SUCCESS;
    }
}
