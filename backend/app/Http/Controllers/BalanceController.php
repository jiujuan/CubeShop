<?php

namespace App\Http\Controllers;

use App\Models\BalanceRecharge;
use App\Models\UserBalanceLog;
use App\Services\Payment\BalanceRechargeService;
use App\Services\Payment\BalanceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 前台余额与充值（收银台方案 §6.5 / §9.4，Roadmap P6）
 *
 * - GET  /user/balance            余额汇总（可用/冻结/累计充值/累计消费）
 * - POST /user/balance/recharges  发起充值（返回与订单支付一致的 PayParams）
 * - GET  /user/balance/recharges  充值记录（分页）
 * - GET  /user/balance/logs       余额流水（分页）
 */
class BalanceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BalanceService $balances,
        private readonly BalanceRechargeService $recharges,
    ) {
    }

    /** 余额汇总：GET /user/balance */
    public function show(Request $request): JsonResponse
    {
        $account = $this->balances->account($request->user()->id);

        return $this->success([
            'balance' => (string) $account->balance,
            'frozen' => (string) $account->frozen,
            'total_recharge' => (string) $account->total_recharge,
            'total_consume' => (string) $account->total_consume,
        ]);
    }

    /**
     * 发起充值：POST /user/balance/recharges body: {amount, channel, extra?}
     *
     * 返回结构与订单支付一致（payment_no / biz_type=recharge / pay_params），
     * 前端复用同一套调起与结果页逻辑。
     */
    public function storeRecharge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'channel' => ['required', 'string', 'in:wechat,alipay,offline,mock'],
            'extra' => ['nullable', 'array'],
        ]);

        $result = $this->recharges->create($request->user()->id, $data);

        return $this->success([
            'recharge_no' => $result['recharge']->recharge_no,
            'payment_no' => $result['payment']->payment_no,
            'biz_type' => $result['payment']->biz_type,
            'amount' => (string) $result['recharge']->amount,
            'gift_amount' => (string) $result['recharge']->gift_amount,
            'channel' => $result['payment']->channel,
            'status' => $result['payment']->status,
            'pay_params' => $result['pay_params'],
        ]);
    }

    /** 充值记录：GET /user/balance/recharges */
    public function recharges(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', array_keys(BalanceRecharge::STATUS_LABELS))],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $paginator = $this->recharges->listForUser($request->user()->id, $filters);

        return $this->paginated($paginator->through(fn (BalanceRecharge $r) => [
            'id' => $r->id,
            'recharge_no' => $r->recharge_no,
            'amount' => (string) $r->amount,
            'gift_amount' => (string) $r->gift_amount,
            'total' => bcadd((string) $r->amount, (string) $r->gift_amount, 2),
            'channel' => $r->channel,
            'channel_label' => $r->channel_label,
            'status' => $r->status,
            'status_label' => $r->status_label,
            'paid_at' => $r->paid_at?->format('Y-m-d H:i:s'),
            'created_at' => $r->created_at?->format('Y-m-d H:i:s'),
        ]));
    }

    /** 余额流水：GET /user/balance/logs */
    public function logs(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', 'string', 'in:'.implode(',', array_keys(UserBalanceLog::TYPE_LABELS))],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $paginator = UserBalanceLog::query()
            ->where('user_id', $request->user()->id)
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->orderByDesc('id')
            ->paginate(
                min((int) ($filters['page_size'] ?? 10), 50),
                ['*'],
                'page',
                (int) ($filters['page'] ?? 1),
            );

        return $this->paginated($paginator->through(fn (UserBalanceLog $log) => [
            'id' => $log->id,
            'type' => $log->type,
            'type_label' => $log->type_label,
            'amount' => (string) $log->amount,
            'balance_before' => (string) $log->balance_before,
            'balance_after' => (string) $log->balance_after,
            'related_type' => $log->related_type,
            'remark' => $log->remark,
            'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
        ]));
    }
}
