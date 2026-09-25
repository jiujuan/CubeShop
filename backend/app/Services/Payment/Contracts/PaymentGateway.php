<?php

namespace App\Services\Payment\Contracts;

use App\Models\Payment;
use App\Services\Payment\Dto\CallbackResult;
use App\Services\Payment\Dto\PayParams;
use App\Services\Payment\Dto\QueryResult;
use App\Services\Payment\Dto\RefundResult;
use App\Services\Payment\Dto\TestResult;
use App\Services\Payment\Dto\StatementResult;
use Illuminate\Http\Request;

/**
 * 支付渠道网关契约（收银台方案 §3.2）
 *
 * 关键约束：网关不得直接改订单状态，只返回标准化结果；
 * 订单状态流转统一由 PaymentService 驱动。
 */
interface PaymentGateway
{
    /** 渠道标识：wechat / alipay / balance / offline / mock */
    public function channel(): string;

    /**
     * 发起支付：返回给前端的调起参数
     *
     * @param  array  $context  {order?: Order, recharge?: BalanceRecharge, extra: array}
     */
    public function create(Payment $payment, array $context, array $config): PayParams;

    /** 解析并验签渠道回调 */
    public function verifyCallback(Request $request, array $config): CallbackResult;

    /** 主动查单（回调丢失补偿、结果页轮询兜底） */
    public function query(Payment $payment, array $config): QueryResult;

    /** 退款 */
    public function refund(Payment $payment, string $amount, string $reason, array $config): RefundResult;

    /** 配置连通性自检（后台「测试连接」） */
    public function testConnection(array $config): TestResult;
    /**
     * 拉取渠道日账单（A7-支付渠道对账）
     *
     * 返回该渠道指定日期的交易流水（ChannelTransaction[]）；余额/线下等无远程账单的渠道返回 unsupported。
     */
    public function downloadBill(string $billDate, array $config): StatementResult;

}
