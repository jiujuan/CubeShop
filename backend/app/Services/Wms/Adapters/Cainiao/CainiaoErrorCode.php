<?php

namespace App\Services\Wms\Adapters\Cainiao;

/**
 * 奇门错误归类（WMS 计划 P2 / F7、Step 4）
 *
 * 把「HTTP 状态 + flag + code」三要素翻译成上层的三种处置：
 *
 * | 归类 | 判定 | 上层动作 |
 * |---|---|---|
 * | **可重试** | 网络异常（无 HTTP 状态）/ 408 / 429 / 5xx / 明确的重试码 | 交队列退避重试，超限转 `push_failed` |
 * | **不可重试** | `flag=failure` 且非重试码 / 4xx（凭证、参数错误） | 直接 `push_failed` 转人工，不再打扰对方 |
 * | **幂等成功** | code 命中 `duplicate_codes`（单据已存在） | 视为成功，记 `wms_outbound_no`，**绝不重复建单** |
 *
 * 设计取舍：**判据全部来自显式规则表**，不做「消息里含'超时'就重试」这类文本嗅探——
 * 中文错误消息会随对方文案变化，嗅探出来的行为无法回归测试。
 */
final class CainiaoErrorCode
{
    public const FLAG_SUCCESS = 'success';

    public const FLAG_FAILURE = 'failure';

    /** 与「单号重复」等价的业务码（可经 `wms.providers.cainiao.duplicate_codes` 扩充） */
    public const DEFAULT_DUPLICATE_CODES = [
        'S07',
        'ORDER_ALREADY_EXISTS',
        'DELIVERY_ORDER_EXISTS',
    ];

    /**
     * HTTP 层是否可重试：无状态码（网络/超时/DNS）、408 请求超时、429 限流、5xx 服务端错误。
     */
    public static function isRetryableHttp(?int $httpStatus): bool
    {
        if ($httpStatus === null) {
            return true;
        }

        return $httpStatus === 408
            || $httpStatus === 429
            || $httpStatus >= 500;
    }

    /**
     * 业务码是否属于「单号已存在」（幂等成功）。
     *
     * 用不区分大小写的比对：对方不同环境返回大小写不一致。
     *
     * @param  list<string>  $duplicateCodes
     */
    public static function isDuplicate(?string $code, array $duplicateCodes = self::DEFAULT_DUPLICATE_CODES): bool
    {
        if ($code === null || $code === '') {
            return false;
        }

        $normalized = strtoupper(trim($code));

        foreach ($duplicateCodes as $candidate) {
            if (strtoupper(trim((string) $candidate)) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * 默认判为「对方暂时不可用」的业务码（可重试）。
     *
     * ⚠️ 为什么只放这几个：`S0x` 系列**各家网关含义不一致**（同一份 S01 在不同开放平台
     * 分别是「系统异常」「非法 JSON」「非法签名」），硬编码一张 S0x 表等于把某家的方言
     * 当成通用标准。这里只保留语义无歧义的**通用系统码**，并按仓配接口常见口径纳入
     * S01/S02/S05/S06（系统异常 / 系统繁忙 / 限流 / 超时）。
     * 其余 S0x 语义随网关而异，联调时用 `wms.providers.cainiao.retryable_codes` 增补。
     *
     * 明确**不**可重试的两类（任何口径下都是客户端/数据问题，重试一万次也一样）：
     * - S03 参数非法；S04 签名/凭证错误。
     */
    public const DEFAULT_RETRYABLE_CODES = [
        'SYSTEM_ERROR',
        'SERVICE_UNAVAILABLE',
        'TIMEOUT',
        'S01', 'S02', 'S05', 'S06',
    ];

    /**
     * 业务码是否属于「对方暂时不可用」这类可重试错误。
     *
     * 判据 = 默认集合 ∪ `wms.providers.cainiao.retryable_codes`（联调期按实际报文增补，无需改代码）。
     */
    public static function isRetryableCode(?string $code): bool
    {
        if ($code === null || $code === '') {
            return false;
        }

        $normalized = strtoupper(trim($code));

        $configured = array_map(
            static fn ($c) => strtoupper(trim((string) $c)),
            (array) config('wms.providers.cainiao.retryable_codes', []),
        );

        return in_array($normalized, array_merge(self::DEFAULT_RETRYABLE_CODES, $configured), true);
    }

    /**
     * 把「HTTP 状态 + 业务码」折算成最终是否可重试。
     *
     * 优先级：4xx（非 408/429）→ 不可重试（凭证/参数错，重试一万次也一样）；
     * 其余按 HTTP 规则 + 业务码兜底。
     */
    public static function retryable(?int $httpStatus, ?string $code): bool
    {
        if ($httpStatus !== null && $httpStatus >= 400 && $httpStatus < 500
            && $httpStatus !== 408 && $httpStatus !== 429) {
            return false;
        }

        return self::isRetryableHttp($httpStatus) || self::isRetryableCode($code);
    }

    /**
     * 给常见错误码补一句**运维看得懂**的中文提示（附加在对方 message 之后，不覆盖它）。
     *
     * 覆盖不全没关系——原文始终保留，这里只是降低排障门槛。
     */
    public static function hint(?string $code): ?string
    {
        $normalized = strtoupper(trim((string) $code));

        return [
            'S07' => '单据已存在，按幂等成功处理',
            'S01' => '对方系统异常，稍后重试',
            'S02' => '对方服务不可用，稍后重试',
            'S03' => '请求参数被拒绝，请核对货主/仓库编码与商品编码',
            'S04' => '签名或凭证校验失败，请核对 AppKey/AppSecret 与环境',
            'S05' => '超出对方限流阈值，稍后重试',
            'S08' => '单据状态不允许当前操作（如已出库后取消）',
            'S10' => '库存不足，需人工确认',
            'S11' => '仓库不存在或已停用，请核对仓库编码',
            'S12' => '货品编码不存在，请核对 SKU 映射',
            'RETURN_ORDER_NOT_EXISTS' => '退货入库单（或其关联的原出库单）不存在，请核对单号',
            'RETURN_ORDER_EXISTS' => '退货入库单已存在，按幂等成功处理',
        ][$normalized] ?? null;
    }

    /** 拼装「对方原文 + 中文提示」的可读错误串 */
    public static function describe(?string $code, ?string $message): string
    {
        $parts = array_filter([
            $code !== null && $code !== '' ? "[{$code}]" : null,
            $message !== null && $message !== '' ? $message : null,
            self::hint($code),
        ]);

        return $parts ? implode(' ', $parts) : '对方未返回可读错误信息';
    }
}
