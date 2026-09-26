<?php

namespace App\Services\Refund;

use App\Services\Common\ConfigService;

/**
 * 退款策略配置（refund.*）唯一读口
 *
 * 架构约定：每个配置前缀一个唯一读口（同 SearchConfig / SmsSettings），
 * 默认值与类型归一在此收口；业务侧禁止直接 ConfigService::get('refund.*')。
 *
 * 键清单（RefundConfigSeeder 播种，后台「退款策略」页可改）：
 * - refund.auto_approve_amount   自动同意阈值（元），'0.00' = 不启用
 * - refund.max_retry             渠道退款失败自动重试上限（次），超过转人工
 * - refund.dispute_sla_hours     纠纷处理 SLA（小时），超时后台列表标记催办
 * - refund.return_address_template 退货地址模板（纯文本，支持换行）
 */
class RefundSettings
{
    /** 退款重试上限兜底默认值（与 RefundService::MAX_RETRY 同源） */
    public const DEFAULT_MAX_RETRY = 3;

    /** 纠纷处理 SLA 兜底默认值（小时） */
    public const DEFAULT_DISPUTE_SLA_HOURS = 48;

    public function __construct(private ConfigService $configs)
    {
    }

    /** 自动同意阈值（元）：退款金额 ≤ 阈值时买家申请后系统自动审核通过；'0.00' 表示不启用 */
    public function autoApproveAmount(): string
    {
        return $this->configs->getDecimal('refund.auto_approve_amount', '0.00');
    }

    /** 渠道退款失败自动重试上限（次），夹取 0-10 防误配 */
    public function maxRetry(): int
    {
        return max(0, min(10, $this->configs->getInt('refund.max_retry', self::DEFAULT_MAX_RETRY)));
    }

    /** 纠纷处理 SLA（小时），夹取 1-8760 */
    public function disputeSlaHours(): int
    {
        return max(1, min(8760, $this->configs->getInt('refund.dispute_sla_hours', self::DEFAULT_DISPUTE_SLA_HOURS)));
    }

    /** 退货地址模板（纯文本），供买家退货退款时展示；未配置返回空串 */
    public function returnAddressTemplate(): string
    {
        return (string) ($this->configs->get('refund.return_address_template') ?? '');
    }
}
