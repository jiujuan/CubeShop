<?php

namespace App\Console\Commands;

use App\Exceptions\BusinessException;
use App\Models\Warehouse;
use App\Models\WmsConfig;
use App\Services\Wms\Dto\InventoryQueryDto;
use App\Services\Wms\WmsAdapterFactory;
use App\Services\Wms\WmsApiLogService;
use Illuminate\Console\Command;

/**
 * WMS 连通性探针（WMS 计划 P2 / Step 7）
 *
 * 排障用：对指定仓库发起一次 `inventory.query`，把「走的是 Mock 还是真实菜鸟、
 * 耗时多少、回执如何、每个货品可用量」一次打全。
 *
 * 与后台「测试连通性」按钮的区别：那个只看成功与否，这个把**每条明细**摊开，
 * 且可以直接按 SKU 编码筛——联调时最常问的「这个编码仓方到底认不认」当场就有答案。
 *
 * 用法：
 * ```
 * php artisan wms:probe 1                          # 探仓库 1（全仓查询）
 * php artisan wms:probe 1 --sku=CN-SKU-001 --sku=X # 只查指定货品编码
 * ```
 */
class WmsProbe extends Command
{
    protected $signature = 'wms:probe
        {warehouse : 仓库 ID}
        {--sku=* : 要查询的 WMS 货品编码，可重复；留空则全仓查询}';

    protected $description = '探测仓库的 WMS 连通性并打印库存样例（排障用）';

    public function handle(): int
    {
        $warehouseId = (int) $this->argument('warehouse');

        $warehouse = Warehouse::find($warehouseId);
        if (! $warehouse) {
            $this->error("仓库不存在：{$warehouseId}");

            return self::FAILURE;
        }

        $config = WmsConfig::where('warehouse_id', $warehouseId)->first();
        if (! $config) {
            $this->error("仓库「{$warehouse->name}」尚未配置 WMS 对接（后台：仓库与物流 → WMS 对接）");

            return self::FAILURE;
        }

        $skuCodes = array_values(array_filter((array) $this->option('sku'), static fn ($v) => trim((string) $v) !== ''));

        $this->line("仓库：<info>{$warehouse->name}</info> (#{$warehouse->id})");
        $this->line("服务商：<info>{$config->provider}</info>　环境：<info>{$config->api_env}</info>　仓库编码：".($config->warehouse_code ?: '（未配置）'));

        $factory = app(WmsAdapterFactory::class);
        $apiLogs = app(WmsApiLogService::class);
        $started = microtime(true);

        try {
            $adapter = $factory->make($config);
            $result = $adapter->queryInventory(new InventoryQueryDto($warehouseId, $skuCodes, 'probe'));
        } catch (BusinessException $e) {
            // 配置类错误（缺网关/缺凭证/缺仓库货主编码）直接打出来，不做无谓重试
            $apiLogs->record($config, 'queryInventory', \App\Services\Wms\Dto\WmsResult::fail($e->getMessage()), $apiLogs->elapsedMs($started), null, 'probe', ['sku_codes' => $skuCodes]);
            $this->newLine();
            $this->error('调用被拒绝：'.$e->getMessage());

            return self::FAILURE;
        }

        $duration = $apiLogs->elapsedMs($started);
        $apiLogs->record($config, 'queryInventory', $result, $duration, $result->data['request_id'] ?? null, 'probe', ['sku_codes' => $skuCodes]);

        $mode = $adapter->isMock() ? '<comment>Mock（未配置真实凭证）</comment>' : '<info>真实菜鸟网关</info>';
        $this->line("模式：{$mode}　耗时：{$duration} ms");

        if (! $result->success) {
            $this->newLine();
            $this->error('调用失败：'.(string) $result->error);

            return self::FAILURE;
        }

        $items = (array) ($result->data['items'] ?? []);
        if ($items === []) {
            $this->warn('回执成功但没有库存明细（可能是全仓查询而对方未返回数据）');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['货品编码', '可用量', '锁定量'],
            array_map(static fn (array $row) => [
                $row['sku_code'] ?? '-',
                (string) ($row['quantity'] ?? 0),
                (string) ($row['lock_quantity'] ?? 0),
            ], $items),
        );

        return self::SUCCESS;
    }
}
