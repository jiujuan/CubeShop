<?php

namespace App\Support\Shipping;

/**
 * Mock 出单渠道：WAYBILL_CHANNEL=mock 时启用，本地演示与联调。
 *
 * 不调用任何第三方，确定性生成运单号（同一「订单 + 公司」组合恒定），模拟真实出单返回的
 * tracking_no 与面单数据，便于打通「发货即写回单号」全链路，无需签约快递100。
 */
class MockWaybillChannel implements WaybillChannelInterface
{
    public function issue(WaybillRequest $request): WaybillResult
    {
        $seed = crc32($request->orderNo.'|'.$request->companyCode);
        $trackingNo = 'MOCK'.strtoupper(dechex($seed));

        $labelData = sprintf(
            '<div class="mock-waybill">订单 %s 经 %s 发往 %s（%s），重量 %d g</div>',
            $request->orderNo,
            $request->companyCode,
            $request->recipientName,
            $request->recipientAddress,
            $request->weightGram,
        );

        return WaybillResult::ok(
            trackingNo: $trackingNo,
            labelData: $labelData,
            raw: ['channel' => 'mock', 'order_no' => $request->orderNo, 'company' => $request->companyCode],
        );
    }

    public function available(): bool
    {
        return true;
    }

    public function channelName(): string
    {
        return 'mock';
    }
}
