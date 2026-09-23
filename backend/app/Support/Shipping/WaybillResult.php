<?php

namespace App\Support\Shipping;

/**
 * 电子面单申请结果（与轨迹侧 TraceResult 同形）
 *
 * - success=false 时 message 给可操作提示，调用方据此报错而非静默；
 * - tracking_no 即运单号（写回 shippings.tracking_no）；
 * - waybillNo 与 tracking_no 通常相同，部分渠道单号与面单号分离时区分；
 * - labelData 面单打印数据（HTML 模板 / 图片 base64），供后台重打；
 * - raw 第三方原始报文，落 shippings.waybill_data。
 */
final class WaybillResult
{
    private function __construct(
        public readonly bool $success,
        public readonly string $trackingNo,
        public readonly string $waybillNo,
        public readonly ?string $labelData,
        public readonly ?array $raw,
        public readonly string $message,
    ) {
    }

    public static function ok(string $trackingNo, ?string $labelData = null, ?array $raw = null, string $waybillNo = ''): self
    {
        return new self(true, $trackingNo, $waybillNo !== '' ? $waybillNo : $trackingNo, $labelData, $raw, '');
    }

    public static function fail(string $message, ?array $raw = null): self
    {
        return new self(false, '', '', null, $raw, $message);
    }
}
