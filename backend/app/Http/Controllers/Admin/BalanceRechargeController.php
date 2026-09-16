<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\BalanceRecharge;
use App\Models\Payment;
use App\Models\UserBalanceLog;
use App\Services\Common\OperationLogService;
use App\Services\Payment\PaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 后台余额充值单管理（收银台方案 §5.2 / §6.5，权限 balance.recharge.view）
 *
 * - 列表 / 详情：单号、用户、本金、赠送、渠道、状态、时间
 * - 核账：payment.offline.review，仅 reviewing 可核账；通过 → 入账，驳回必填原因
 */
class BalanceRechargeController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PaymentService $payments,
        private readonly OperationLogService $opLog,
    ) {}

    /**
     * 充值单列表（支持状态 / 渠道筛选与导出）
     * GET /admin/balance-recharges
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recharge_no' => ['nullable', 'string', 'max:32'],
            'user_id' => ['nullable', 'integer'],
            'channel' => ['nullable', 'string', 'in:'.implode(',', array_keys(BalanceRecharge::CHANNEL_LABELS))],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_keys(BalanceRecharge::STATUS_LABELS))],
            'start_time' => ['nullable', 'date'],
            'end_time' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->buildQuery($data)
            ->with('user:id,username,nickname')
            ->orderByDesc('id');

        $paginator = $query->paginate(min($data['page_size'] ?? 20, 100), ['*'], 'page', $data['page'] ?? 1);

        return $this->success([
            'list' => $paginator->through(fn (BalanceRecharge $r) => $this->row($r))->items(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * 充值单详情（含关联支付单、凭证、余额流水）
     * GET /admin/balance-recharges/{id}
     */
    public function show(int $id): JsonResponse
    {
        $recharge = BalanceRecharge::query()->with('user:id,username,nickname')->find($id);
        if (! $recharge) {
            throw BusinessException::notFound('充值单不存在');
        }

        $payment = $recharge->payment_id ? Payment::query()->find($recharge->payment_id) : null;
        $balanceLog = UserBalanceLog::query()
            ->where('related_type', 'recharge')
            ->where('related_id', $recharge->id)
            ->first();

        return $this->success($this->row($recharge) + [
            'payment' => $payment ? [
                'id' => $payment->id,
                'payment_no' => $payment->payment_no,
                'channel' => $payment->channel,
                'channel_label' => $payment->channel_label,
                'status' => $payment->status,
                'status_label' => $payment->status_label,
                'channel_trade_no' => $payment->channel_trade_no,
                'review_remark' => $payment->review_remark,
                'reviewed_at' => $payment->reviewed_at?->format('Y-m-d H:i:s'),
            ] : null,
            'voucher_url' => $recharge->voucher_url,
            'payer_name' => $recharge->payer_name,
            'payer_account' => $recharge->payer_account,
            'transfer_no' => $recharge->transfer_no,
            'transferred_at' => $recharge->transferred_at?->format('Y-m-d H:i:s'),
            'balance_log' => $balanceLog ? [
                'amount' => (string) $balanceLog->amount,
                'balance_after' => (string) $balanceLog->balance_after,
                'created_at' => $balanceLog->created_at?->format('Y-m-d H:i:s'),
            ] : null,
        ]);
    }

    /**
     * 线下充值核账（通过 / 驳回）
     * POST /admin/balance-recharges/{id}/review  body: { pass:bool, remark? }
     */
    public function review(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'pass' => ['required', 'boolean'],
            'remark' => ['nullable', 'string', 'max:200'],
        ]);

        $recharge = BalanceRecharge::query()->find($id);
        if (! $recharge) {
            throw BusinessException::notFound('充值单不存在');
        }
        if (! $recharge->payment_id) {
            throw BusinessException::notFound('充值单未关联支付单');
        }

        $payment = Payment::query()->find($recharge->payment_id);
        if (! $payment) {
            throw BusinessException::notFound('支付单不存在');
        }

        $before = $payment->status;
        $payment = $this->payments->review($payment, $request->user()->id, (bool) $data['pass'], $data['remark'] ?? null);

        $this->opLog->record($request->user()->id, 'balance_recharge', $data['pass'] ? 'review_pass' : 'review_reject', 'balance_recharge', $recharge->id, [
            'recharge_no' => $recharge->recharge_no,
            'payment_no' => $payment->payment_no,
            'before' => $before,
            'after' => $payment->status,
            'remark' => $data['remark'] ?? null,
        ]);

        return $this->success($this->row($recharge->fresh()), $data['pass'] ? '核账通过，已入账' : '已驳回');
    }

    /**
     * 充值单导出（CSV）
     * GET /admin/balance-recharges/export
     */
    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'recharge_no' => ['nullable', 'string', 'max:32'],
            'user_id' => ['nullable', 'integer'],
            'channel' => ['nullable', 'string', 'in:'.implode(',', array_keys(BalanceRecharge::CHANNEL_LABELS))],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_keys(BalanceRecharge::STATUS_LABELS))],
            'start_time' => ['nullable', 'date'],
            'end_time' => ['nullable', 'date'],
        ]);

        $rows = $this->buildQuery($data)->with('user:id,username')->orderByDesc('id')->limit(5000)->get();
        $filename = 'balance-recharges-'.now()->format('YmdHis').'.csv';

        return response()->streamDownload(function () use ($rows) {
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            fputcsv($out, ['充值单号', '用户', '本金', '赠送', '到账', '渠道', '状态', '时间']);

            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->recharge_no,
                    $r->user?->username,
                    (string) $r->amount,
                    (string) $r->gift_amount,
                    bcadd((string) $r->amount, (string) $r->gift_amount, 2),
                    $r->channel_label,
                    $r->status_label,
                    $r->created_at?->format('Y-m-d H:i:s'),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function buildQuery(array $data)
    {
        return BalanceRecharge::query()
            ->when($data['recharge_no'] ?? null, fn ($q, $v) => $q->where('recharge_no', 'like', '%'.$v.'%'))
            ->when($data['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($data['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['start_time'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($data['end_time'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v));
    }

    private function row(BalanceRecharge $r): array
    {
        return [
            'id' => $r->id,
            'recharge_no' => $r->recharge_no,
            'user_id' => $r->user_id,
            'user_name' => $r->user?->username,
            'amount' => (string) $r->amount,
            'gift_amount' => (string) $r->gift_amount,
            'total' => bcadd((string) $r->amount, (string) $r->gift_amount, 2),
            'channel' => $r->channel,
            'channel_label' => $r->channel_label,
            'status' => $r->status,
            'status_label' => $r->status_label,
            'paid_at' => $r->paid_at?->format('Y-m-d H:i:s'),
            'created_at' => $r->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
