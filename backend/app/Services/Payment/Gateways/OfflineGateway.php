<?php

namespace App\Services\Payment\Gateways;

use App\Exceptions\BusinessException;
use App\Models\Payment;
use App\Services\Payment\Contracts\PaymentGateway;
use App\Services\Payment\Dto\CallbackResult;
use App\Services\Payment\Dto\PayParams;
use App\Services\Payment\Dto\QueryResult;
use App\Services\Payment\Dto\RefundResult;
use App\Services\Payment\Dto\RefundQueryResult;
use App\Services\Payment\Dto\TestResult;
use App\Services\Payment\Dto\StatementResult;
use App\Services\Payment\PaymentChannelService;
use Illuminate\Http\Request;

/**
 * 线下转账网关（收银台方案 §6.2）
 *
 * 提交后支付单进入 reviewing（待核账），不驱动订单状态；
 * 后台核账通过 → PaymentService::review() → success → 订单 paid。
 */
class OfflineGateway implements PaymentGateway
{
    public function __construct(private readonly PaymentChannelService $channels)
    {
    }

    public function channel(): string
    {
        return Payment::CHANNEL_OFFLINE;
    }

    /**
     * @param  array  $context  含 extra：payer_name/payer_account/transfer_no/transferred_at/voucher_url
     */
    public function create(Payment $payment, array $context, array $config): PayParams
    {
        $extra = (array) ($context['extra'] ?? []);

        foreach (['payer_name' => '付款人姓名', 'transfer_no' => '转账流水号', 'voucher_url' => '转账凭证'] as $field => $label) {
            if (trim((string) ($extra[$field] ?? '')) === '') {
                throw BusinessException::badRequest("请填写{$label}");
            }
        }

        return PayParams::voucher($this->channels->offlineReceipt());
    }

    public function verifyCallback(Request $request, array $config): CallbackResult
    {
        return CallbackResult::fail('线下转账无渠道回调，需后台核账');
    }

    public function query(Payment $payment, array $config): QueryResult
    {
        return QueryResult::success($payment->status, null, (string) $payment->amount);
    }

    /** 银行转账不可逆，不支持原路退款（§7.5） */
    public function refund(Payment $payment, string $amount, string $reason, array $config, ?string $outRefundNo = null): RefundResult
    {
        return RefundResult::fail('线下转账不支持原路退款，请线下处理后人工标记');
    }

    /** 线下转账无远程退款查单 */
    public function queryRefund(Payment $payment, string $outRefundNo, array $config): RefundQueryResult
    {
        return RefundQueryResult::fail('unsupported');
    }

    public function testConnection(array $config): TestResult
    {
        return TestResult::ok('线下转账无需外部连通性测试');
    }
    /**
     * 线下转账无远程账单（A7-支付渠道对账）
     */
    public function downloadBill(string $billDate, array $config): StatementResult
    {
        return StatementResult::unsupported('线下转账无远程账单，对账改走本地日志源');
    }

}
