<?php

namespace App\Services\Order;

use App\Models\ExpressCompany;
use App\Models\Order;
use App\Models\Shipping;
use App\Support\ShippingRules;
use Illuminate\Support\Facades\DB;

/**
 * 批量发货（V1.1 T-044，E03）
 *
 * 执行策略（评审结论）：**预校验 → 全部行通过才执行**（单事务提交），
 * 避免「部分成功」带来的对账困难；校验失败明细返回给前端下载修正后重传。
 *
 * Excel 列序（ShippingRules::BATCH_SHIP_HEADERS）：订单号、快递公司编码、运单号。
 */
class BatchShipService
{
    public function __construct(private readonly OrderService $orders)
    {
    }

    /**
     * 逐行校验（不落库）。
     *
     * @param  array<int, array{0:string,1:string,2:string}>  $rows  数据行（不含表头）
     * @return array{valid: list<array{order:Order, company_code:string, company_name:string, tracking_no:string}>, failed: list<array{row:int, order_no:string, reason:string}>}
     */
    public function validateRows(array $rows): array
    {
        $valid = [];
        $failed = [];
        $seenOrderNos = [];
        $seenTracking = [];

        // 预取：启用字典 + 本批涉及的订单（一次查询，避免逐行 N+1）
        $companyMap = ExpressCompany::enabled()->pluck('name', 'code');
        $orderNos = array_values(array_unique(array_filter(array_map(
            fn ($r) => trim((string) ($r[0] ?? '')),
            $rows
        ))));
        $orders = Order::whereIn('order_no', $orderNos)->get()->keyBy('order_no');
        // 已被占用的运单号（同公司组合唯一）
        $trackingNos = array_values(array_unique(array_filter(array_map(
            fn ($r) => strtoupper(trim((string) ($r[2] ?? ''))),
            $rows
        ))));
        $occupied = Shipping::whereIn('tracking_no', $trackingNos)
            ->get(['company_code', 'tracking_no'])
            ->keyBy(fn ($s) => $s->company_code.'|'.strtoupper($s->tracking_no));

        foreach (array_values($rows) as $index => $row) {
            $rowNo = $index + 2; // Excel 行号（第 1 行表头）
            $orderNo = trim((string) ($row[0] ?? ''));
            $companyCode = trim((string) ($row[1] ?? ''));
            $trackingNo = strtoupper(trim((string) ($row[2] ?? '')));

            $fail = function (string $reason) use (&$failed, $rowNo, $orderNo) {
                $failed[] = ['row' => $rowNo, 'order_no' => $orderNo, 'reason' => $reason];
            };

            if ($orderNo === '' || $companyCode === '' || $trackingNo === '') {
                $fail('存在空单元格（订单号/快递公司编码/运单号均必填）');

                continue;
            }

            // 文件内重复
            if (isset($seenOrderNos[$orderNo])) {
                $fail('订单号在文件内重复');

                continue;
            }
            $trackingKey = $companyCode.'|'.$trackingNo;
            if (isset($seenTracking[$trackingKey])) {
                $fail('运单号在文件内重复');

                continue;
            }

            // 订单存在 + 状态
            /** @var Order|null $order */
            $order = $orders[$orderNo] ?? null;
            if (! $order) {
                $fail('订单不存在');

                continue;
            }
            if ($order->status !== Order::STATUS_PENDING_SHIP) {
                $fail('订单当前状态非「待发货」（paid=货款到账异常态，需先受理备货）');

                continue;
            }

            // 公司编码有效
            if (! $companyMap->has($companyCode)) {
                $fail('快递公司编码无效或已停用');

                continue;
            }

            // 单号格式
            if (! preg_match(ShippingRules::TRACKING_NO_REGEX, $trackingNo)) {
                $fail('运单号格式错误（要求 8~32 位字母数字与短横线）');

                continue;
            }

            // 库内运单号占用
            if ($occupied->has($trackingKey)) {
                $fail('运单号已被其他订单使用');

                continue;
            }

            $seenOrderNos[$orderNo] = true;
            $seenTracking[$trackingKey] = true;
            $valid[] = [
                'order' => $order,
                'company_code' => $companyCode,
                'company_name' => $companyMap[$companyCode],
                'tracking_no' => $trackingNo,
            ];
        }

        return ['valid' => $valid, 'failed' => $failed];
    }

    /**
     * 执行发货（仅在校验全部通过后调用，单事务）。
     *
     * @param  list<array{order:Order, company_code:string, company_name:string, tracking_no:string}>  $valid
     * @return int 成功数
     */
    public function execute(array $valid, ?int $operatorId): int
    {
        $success = 0;

        DB::transaction(function () use ($valid, $operatorId, &$success) {
            foreach ($valid as $item) {
                $this->orders->shipForShipment(
                    $item['order'],
                    $item['company_code'],
                    $item['company_name'],
                    $item['tracking_no'],
                    '批量发货（'.$item['company_name'].' '.$item['tracking_no'].'）',
                    $operatorId,
                    \App\Models\OrderLog::OPERATOR_ADMIN,
                );
                $success++;
            }
        });

        return $success;
    }
}
