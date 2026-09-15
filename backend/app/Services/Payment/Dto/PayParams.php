<?php

namespace App\Services\Payment\Dto;

/**
 * 发起支付返回给前端的调起参数（收银台方案 §6.3）
 *
 * 前端按 type 分支渲染：
 * - qrcode  ：微信 Native，渲染 code_url 二维码
 * - redirect / form：支付宝跳转或自动提交表单
 * - direct  ：余额支付，同步完成
 * - voucher ：线下转账，展示收款账户 + 凭证表单
 * - mock    ：L1 本地模拟（沙箱 / 测试）
 */
class PayParams
{
    public const TYPE_QRCODE = 'qrcode';
    public const TYPE_REDIRECT = 'redirect';
    public const TYPE_FORM = 'form';
    public const TYPE_DIRECT = 'direct';
    public const TYPE_VOUCHER = 'voucher';
    public const TYPE_MOCK = 'mock';

    public function __construct(
        public readonly string $type,
        public readonly array $payload = [],
    ) {}

    public static function qrcode(string $codeUrl, ?string $expireAt = null): self
    {
        return new self(self::TYPE_QRCODE, array_filter([
            'code_url' => $codeUrl,
            'expire_at' => $expireAt,
        ]));
    }

    public static function redirect(string $payUrl): self
    {
        return new self(self::TYPE_REDIRECT, ['pay_url' => $payUrl]);
    }

    public static function form(string $formHtml): self
    {
        return new self(self::TYPE_FORM, ['form_html' => $formHtml]);
    }

    public static function direct(string $paidAt): self
    {
        return new self(self::TYPE_DIRECT, ['paid_at' => $paidAt]);
    }

    public static function voucher(array $receipt): self
    {
        return new self(self::TYPE_VOUCHER, ['receipt' => $receipt]);
    }

    public static function mock(string $sandboxPayUrl, array $extra = []): self
    {
        return new self(self::TYPE_MOCK, array_merge([
            'sandbox_pay_url' => $sandboxPayUrl,
        ], $extra));
    }

    /** @return array{type: string, ...} */
    public function toArray(): array
    {
        return array_merge(['type' => $this->type], $this->payload);
    }
}
