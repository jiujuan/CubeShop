<?php

namespace App\Services\Payment;

use App\Exceptions\BusinessException;
use App\Models\BalanceRecharge;
use App\Models\Payment;
use App\Services\Common\ConfigService;
use App\Services\Common\NoGeneratorService;
use Illuminate\Support\Carbon;

/**
 * 余额充值服务（收银台方案 §6.5）
 *
 * 职责：风控校验（金额区间 / 单日累计）→ 命中赠送规则 → 建充值单 →
 *       委托 PaymentService 建支付单并调网关，返回与订单支付完全一致的 PayParams。
 *
 * 资金流仍统一落在 payments：充值单只记录业务字段，真正的支付/回调/查单/对账复用订单支付链路。
 * 入账（唯一口）在 BalanceService::creditForRecharge()，由支付成功后按 biz_type 触发。
 */
class BalanceRechargeService
{
    /** 计入单日累计的充值单状态（失败/关闭不占用额度） */
    private const DAILY_COUNTED_STATUSES = [
        BalanceRecharge::STATUS_PENDING,
        BalanceRecharge::STATUS_REVIEWING,
        BalanceRecharge::STATUS_SUCCESS,
    ];

    public function __construct(
        private readonly NoGeneratorService $noGenerator,
        private readonly ConfigService $config,
        private readonly PaymentChannelService $channels,
        private readonly PaymentService $payments,
    ) {
    }

    /**
     * 发起充值：建充值单 + 建支付单 + 调网关，返回 PayParams（§6.5 下单）
     *
     * @param  array{amount: string|int|float, channel: string, extra?: array}  $data
     * @return array{recharge: BalanceRecharge, payment: Payment, pay_params: array}
     */
    public function create(int $userId, array $data): array
    {
        $amount = $this->normalizeAmount($data['amount'] ?? null);
        $channel = (string) ($data['channel'] ?? '');
        $extra = (array) ($data['extra'] ?? []);

        $this->assertRechargeEnabled();
        $this->assertChannel($channel);
        $this->assertAmountWithinLimits($userId, $amount);

        $gift = $this->giftFor($amount);
        $timeout = $this->config->getInt('payment.recharge_timeout_minutes', 30);

        $recharge = BalanceRecharge::create([
            'recharge_no' => $this->noGenerator->generateRechargeNo(),
            'user_id' => $userId,
            'amount' => $amount,
            'gift_amount' => $gift,
            'channel' => $channel,
            'status' => BalanceRecharge::STATUS_PENDING,
            'expired_at' => Carbon::now()->addMinutes($timeout),
        ]);

        // 建支付单 + 调网关（biz_type=recharge，order_id 为空）；PayParams 与订单支付完全一致
        [$payment, $payParams] = $this->payments->createRechargePayment($recharge, $channel, $extra);

        return [
            'recharge' => $recharge->fresh(),
            'payment' => $payment,
            'pay_params' => $payParams,
        ];
    }

    /**
     * 赠送金额：命中「门槛 ≤ 充值金额」的最高档；未命中不送（§6.5）
     */
    public function giftFor(string $amount): string
    {
        $rules = $this->giftRules();
        $gift = '0.00';

        foreach ($rules as $rule) {
            $threshold = isset($rule['amount']) ? (string) $rule['amount'] : '0';
            $bonus = isset($rule['gift']) ? (string) $rule['gift'] : '0';

            if ($threshold !== '' && bccomp($amount, $threshold, 2) >= 0) {
                // 命中更高档才覆盖（规则数组不保证有序）
                if (bccomp($bonus, $gift, 2) > 0) {
                    $gift = number_format((float) $bonus, 2, '.', '');
                }
            }
        }

        return $gift;
    }

    /** 单日已发生的充值本金累计（不含失败/关闭单） */
    public function dailyRecharged(int $userId): string
    {
        $sum = BalanceRecharge::query()
            ->where('user_id', $userId)
            ->whereIn('status', self::DAILY_COUNTED_STATUSES)
            ->whereDate('created_at', Carbon::today())
            ->sum('amount');

        return number_format((float) $sum, 2, '.', '');
    }

    /**
     * 当前用户充值记录（分页，§9.4）
     *
     * @param  array{status?: string, page?: int, page_size?: int}  $filters
     */
    public function listForUser(int $userId, array $filters = [])
    {
        return BalanceRecharge::query()
            ->where('user_id', $userId)
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')
            ->paginate(
                min((int) ($filters['page_size'] ?? 10), 50),
                ['*'],
                'page',
                (int) ($filters['page'] ?? 1),
            );
    }

    /* ------------------------------------------------------------------ */

    /** 解析并规范充值金额（两位小数字符串，最小 0.01） */
    private function normalizeAmount(mixed $amount): string
    {
        if (! is_numeric($amount)) {
            throw BusinessException::badRequest('请输入正确的充值金额');
        }

        $value = number_format((float) $amount, 2, '.', '');
        if (bccomp($value, '0', 2) <= 0) {
            throw BusinessException::badRequest('充值金额必须大于 0');
        }

        return $value;
    }

    private function assertRechargeEnabled(): void
    {
        if ($this->config->get('payment.recharge_enabled', '1') !== '1') {
            throw BusinessException::badRequest('余额充值功能暂未开放');
        }
    }

    /** 充值仅支持在线渠道与线下转账，不支持余额支付（§6.5） */
    private function assertChannel(string $channel): void
    {
        $allowed = [Payment::CHANNEL_WECHAT, Payment::CHANNEL_ALIPAY, Payment::CHANNEL_OFFLINE, Payment::CHANNEL_MOCK];

        if (! in_array($channel, $allowed, true)) {
            throw BusinessException::badRequest('充值不支持的支付渠道');
        }
        if (! $this->channels->isEnabled($channel)) {
            throw BusinessException::badRequest('支付渠道未启用');
        }
    }

    /** 金额区间 + 单日累计限额（§6.5 风控） */
    private function assertAmountWithinLimits(int $userId, string $amount): void
    {
        $min = $this->config->getDecimal('payment.recharge_min_amount', '10.00');
        $maxSingle = $this->config->getDecimal('payment.recharge_max_single', '5000.00');
        $maxDaily = $this->config->getDecimal('payment.recharge_max_daily', '20000.00');

        if (bccomp($amount, $min, 2) < 0) {
            throw BusinessException::badRequest("单笔充值不得低于 {$min} 元");
        }
        if (bccomp($amount, $maxSingle, 2) > 0) {
            throw BusinessException::badRequest("单笔充值不得超过 {$maxSingle} 元");
        }

        $daily = bcadd($this->dailyRecharged($userId), $amount, 2);
        if (bccomp($daily, $maxDaily, 2) > 0) {
            throw BusinessException::badRequest("单日累计充值不得超过 {$maxDaily} 元");
        }
    }

    /** 赠送规则（容错：非法 JSON 视为不送） */
    private function giftRules(): array
    {
        $data = json_decode((string) $this->config->get('payment.recharge_gift_rules', '[]'), true);

        return is_array($data) ? $data : [];
    }
}
