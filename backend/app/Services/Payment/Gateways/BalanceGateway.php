<?php

namespace App\Services\Payment\Gateways;

use App\Exceptions\BusinessException;
use App\Models\Payment;
use App\Models\UserBalanceLog;
use App\Services\Payment\BalanceService;
use App\Services\Payment\Contracts\PaymentGateway;
use App\Services\Payment\Dto\CallbackResult;
use App\Services\Payment\Dto\PayParams;
use App\Services\Payment\Dto\QueryResult;
use App\Services\Payment\Dto\RefundResult;
use App\Services\Payment\Dto\TestResult;
use App\Services\Payment\Dto\StatementResult;
use Illuminate\Http\Request;

/**
 * 余额支付网关（收银台方案 §6.2）
 *
 * 同步完成：create() 内行锁扣减余额并写流水，返回 PayParams::direct；
 * 支付单置为 success 与订单状态机由 PaymentService 统一驱动（网关不改订单）。
 */
class BalanceGateway implements PaymentGateway
{
    public function __construct(private readonly BalanceService $balances)
    {
    }

    public function channel(): string
    {
        return Payment::CHANNEL_BALANCE;
    }

    public function create(Payment $payment, array $context, array $config): PayParams
    {
        // 行锁 + 余额校验 + 扣减 + 流水（related_type=payment，便于按支付单反查）
        $this->balances->debit(
            $payment->user_id,
            (string) $payment->amount,
            UserBalanceLog::TYPE_CONSUME,
            'payment',
            $payment->id,
            '余额支付 '.$payment->payment_no,
        );

        return PayParams::direct(now()->format('Y-m-d H:i:s'));
    }

    public function verifyCallback(Request $request, array $config): CallbackResult
    {
        return CallbackResult::fail('余额支付无渠道回调');
    }

    public function query(Payment $payment, array $config): QueryResult
    {
        return QueryResult::success($payment->status, null, (string) $payment->amount);
    }

    /** 余额支付退款：退回用户余额 */
    public function refund(Payment $payment, string $amount, string $reason, array $config): RefundResult
    {
        try {
            $log = $this->balances->credit(
                $payment->user_id,
                $amount,
                UserBalanceLog::TYPE_REFUND,
                'payment',
                $payment->id,
                '订单退款退回余额：'.$reason,
            );
        } catch (BusinessException $e) {
            return RefundResult::fail($e->getMessage());
        }

        return RefundResult::success((string) $log->id);
    }

    public function testConnection(array $config): TestResult
    {
        return TestResult::ok('余额支付无需外部连通性测试');
    }
    /**
     * 余额支付无远程账单（A7-支付渠道对账）
     */
    public function downloadBill(string $billDate, array $config): StatementResult
    {
        return StatementResult::unsupported('余额支付无远程账单，对账改走本地日志源');
    }

}
