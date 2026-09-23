<?php

namespace App\Support\Shipping;

use App\Support\CarrierCode;
use Illuminate\Support\Facades\Http;

/**
 * 快递100 电子面单渠道（出单侧）
 *
 * 接口：POST {order_url}（默认 https://poll.kuaidi100.com/poll/order.do，form-urlencoded）
 * 签名：strtoupper(md5(param + key + customer))，与轨迹查询同源。
 *
 * 设计要点：
 * - 内部 code 经 {@see CarrierCode::forChannel(code, kuaidi100)} 转成快递100 编码；
 * - 响应优先取 data.kuaidinum（实时出单标准字段），兼容顶层 kuaidinum；
 * - 面单打印数据取 data.printTemplate / printTemplateBase64；
 * - 一切异常收敛为 WaybillResult::fail()，由调用方（OrderService）决定回落或报错。
 *
 * ⚠️ 现网需签协议方可调用；未签约时通过 WAYBILL_CHANNEL=mock 走 Mock 版。
 * 本实现仅占位，真实联调时按快递100 最新文档核对字段（printType、templateType 等）。
 */
class Kuaidi100WaybillChannel implements WaybillChannelInterface
{
    public function available(): bool
    {
        return $this->key() !== '' && $this->customer() !== '';
    }

    public function channelName(): string
    {
        return 'kuaidi100';
    }

    public function issue(WaybillRequest $request): WaybillResult
    {
        $com = CarrierCode::forChannel($request->companyCode, CarrierCode::KUAIDI100);
        if ($com === '') {
            return WaybillResult::fail('快递公司未配置渠道编码：'.$request->companyCode);
        }

        $param = json_encode([
            'orderId' => $request->orderNo,
            'kuaidiCom' => $com,
            'sendMan' => [
                'name' => $request->senderName,
                'mobile' => $request->senderPhone,
                'addr' => $request->senderAddress,
            ],
            'recMan' => [
                'name' => $request->recipientName,
                'mobile' => $request->recipientPhone,
                'addr' => $request->recipientAddress,
            ],
            'cargo' => $request->cargoName,
            'weight' => max(0.01, round($request->weightGram / 1000, 3)),
            'count' => 1,
            'printType' => 'HTML',
        ], JSON_UNESCAPED_UNICODE);

        $payload = [
            'customer' => $this->customer(),
            'param' => $param,
            'sign' => strtoupper(md5($param.$this->key().$this->customer())),
        ];

        $timeout = max(1, (int) config('services.waybill.timeout', 8));

        try {
            $response = Http::asForm()
                ->timeout($timeout)
                ->connectTimeout(min(3, $timeout))
                ->post((string) config('services.waybill.order_url'), $payload);
        } catch (\Throwable $e) {
            return WaybillResult::fail('快递100 出单请求异常：'.$e->getMessage());
        }

        if (! $response->successful()) {
            return WaybillResult::fail('快递100 出单 HTTP '.$response->status(), ['status' => $response->status()]);
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?: [];
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;

        $trackingNo = (string) ($data['kuaidinum'] ?? $body['kuaidinum'] ?? '');
        if ($trackingNo === '') {
            return WaybillResult::fail((string) ($body['message'] ?? '快递100 出单失败（未返回运单号）'), $body);
        }

        $labelData = $data['printTemplate'] ?? $data['printTemplateBase64'] ?? $body['printTemplate'] ?? null;
        $labelData = is_string($labelData) ? $labelData : null;

        return WaybillResult::ok(
            trackingNo: $trackingNo,
            labelData: $labelData,
            raw: $body,
        );
    }

    private function key(): string
    {
        return trim((string) config('services.waybill.key'));
    }

    private function customer(): string
    {
        return trim((string) config('services.waybill.customer'));
    }
}
