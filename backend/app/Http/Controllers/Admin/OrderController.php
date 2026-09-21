<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\UserBalanceLog;
use App\Services\Common\OperationLogService;
use App\Services\Order\OrderService;
use App\Support\ApiResponse;
use App\Support\Mask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 后台订单管理（API 文档 8.3 / Roadmap P6）
 */
class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OrderService $orders,
        private readonly \App\Support\Shipping\Kuaidi100AutoNumber $autoNumber,
    ) {}

    /**
     * 订单列表（多条件筛选，权限 order.view）
     * GET /admin/orders?order_no=&user_id=&status=&start_time=&end_time=&page=&page_size=
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_no' => ['nullable', 'string', 'max:32'],
            'user_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_keys(Order::STATUS_LABELS))],
            'start_time' => ['nullable', 'date'],
            'end_time' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->buildQuery($data)
            ->orderByDesc('id')
            ->paginate(min($data['page_size'] ?? 20, 100), ['*'], 'page', $data['page'] ?? 1);

        $paginator->through(fn (Order $order) => $this->brief($order));

        return $this->paginated($paginator);
    }

    /**
     * 订单详情（权限 order.view）
     * GET /admin/orders/{id}
     */
    public function show(int $id): JsonResponse
    {
        // 详情页需要优惠与物流，一并预加载（detail() 内 loadMissing 仅作兜底）
        $order = Order::query()->with(['items', 'coupon', 'shipping.traces'])->find($id);

        if (! $order) {
            throw BusinessException::notFound('订单不存在');
        }

        return $this->success($this->detail($order));
    }

    /**
     * 发货（pending_ship → shipped，权限 order.ship）
     * POST /admin/orders/{id}/ship  body: { express_company_code, tracking_no, remark? }
     *
     * V1.1 T-043 升级：必须录入快递公司编码（启用字典）与运单号（8~32 位字母数字短横线）。
     * V1.0 仅传 remark 的调用方式已废弃（破坏性变更，见二期发布说明）。
     */
    public function ship(Request $request, int $id): JsonResponse
    {
        // 粘贴自动去空格（T-047 前端同步该行为），验证与落库统一使用预处理后的单号
        $request->merge(['tracking_no' => preg_replace('/\s+/u', '', (string) $request->input('tracking_no', ''))]);

        $data = $request->validate([
            'express_company_code' => ['required', 'string', 'max:20'],
            'tracking_no' => ['required', 'string', 'regex:'.\App\Support\ShippingRules::TRACKING_NO_REGEX],
            'remark' => ['nullable', 'string', 'max:200'],
        ]);

        // 快递公司编码必须存在于启用字典
        $company = \App\Models\ExpressCompany::enabled()->where('code', $data['express_company_code'])->first();
        if (! $company) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'express_company_code' => ['快递公司编码无效或已停用'],
            ]);
        }

        $order = Order::query()->find($id);
        if (! $order) {
            throw BusinessException::notFound('订单不存在');
        }

        // 同快递公司运单号不能挂到第二个订单（表级组合唯一索引兜底）
        $dup = \App\Models\Shipping::where('company_code', $company->code)
            ->where('tracking_no', $data['tracking_no'])
            ->exists();
        if ($dup) {
            throw BusinessException::conflict('该运单号已被其他订单使用');
        }

        $order = $this->orders->shipForShipment(
            $order,
            $company->code,
            $company->name,
            $data['tracking_no'],
            // 流水备注含快递公司与单号（T-043）
            trim(($data['remark'] ?? '商家已发货').'（'.$company->name.' '.$data['tracking_no'].'）'),
            $request->user()->id,
            \App\Models\OrderLog::OPERATOR_ADMIN,
        );

        // V1.1 F02 / T-018：发货后通知买家 — 事件由 OrderService::shipForShipment
        // 在事务提交后统一派发（T-044 起单笔/批量共用，避免批量路径漏发）

        return $this->success($this->detail($order->load('items')), '发货成功');
    }

    /**
     * 运单号智能识别快递公司（V1.1 三期，权限 order.ship）
     * POST /admin/orders/detect-company  body: { tracking_no }
     *
     * 仅作**提示**：快递100 官方不保证 100% 准确，前端展示须标注「由快递100猜测」
     * 并允许手改。渠道未配置或调用失败时返回空候选，由前端退回手动选择。
     */
    public function detectCompany(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tracking_no' => ['required', 'string', 'regex:'.\App\Support\ShippingRules::TRACKING_NO_REGEX],
        ]);

        $candidates = $this->autoNumber->detect($data['tracking_no']);

        return $this->success([
            'tracking_no' => $data['tracking_no'],
            'candidates' => $candidates,
            'guess' => $candidates[0]['code'] ?? null,
        ], $candidates === [] ? '未能识别该单号，请手动选择快递公司' : '识别完成（结果仅供参考）');
    }

    /**
     * 受理备货（paid → pending_ship，权限 order.ship）
     * POST /admin/orders/{id}/accept  body: { remark? }
     *
     * 正常路径由系统在支付成功后自动流转到「待发货」；本接口是异常滞留订单
     * （自动流转失败、退款被驳回回流到「已支付」）的人工兜底。
     */
    public function accept(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'remark' => ['nullable', 'string', 'max:200'],
        ]);

        $order = Order::query()->find($id);
        if (! $order) {
            throw BusinessException::notFound('订单不存在');
        }

        $order = $this->orders->acceptForShipment(
            $order,
            $request->user()->id,
            \App\Models\OrderLog::OPERATOR_ADMIN,
            $data['remark'] ?? '商家受理备货',
        );

        return $this->success($this->detail($order->load('items')), '订单已进入发货队列');
    }

    /**
     * 订单导出（CSV，UTF-8 BOM，Excel 可直接打开；权限 order.export）
     * GET /admin/orders/export（同列表筛选条件，最多导出 5000 条）
     */
    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'order_no' => ['nullable', 'string', 'max:32'],
            'user_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_keys(Order::STATUS_LABELS))],
            'start_time' => ['nullable', 'date'],
            'end_time' => ['nullable', 'date'],
        ]);

        $orders = $this->buildQuery($data)
            ->with('items')
            ->orderByDesc('id')
            ->limit(5000)
            ->get();

        // SEC-09：导出审计（谁、何时、导出多少条、何种筛选）
        app(OperationLogService::class)->record(
            $request->user()->id,
            'order',
            'export',
            'orders',
            null,
            ['count' => $orders->count(), 'filter' => $data],
        );

        $filename = 'orders-'.now()->format('YmdHis').'.csv';

        return response()->streamDownload(function () use ($orders) {
            // UTF-8 BOM：保证 Excel 直接打开不乱码
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');

            // T-043：新增快递公司、运单号、确认收货时间、是否系统自动确认
            fputcsv($out, ['订单号', '用户ID', '状态', '商品', '商品合计', '运费', '实付金额', '快递公司', '运单号', '收货人', '联系电话', '收货地址', '备注', '下单时间', '支付时间', '发货时间', '确认收货时间', '系统自动确认', '取消原因']);

            foreach ($orders as $order) {
                $goods = $order->items->map(fn ($item) => sprintf('%s x%d', $item->product_title, $item->quantity))->implode('；');
                $addr = $order->address_snapshot ?? [];

                fputcsv($out, [
                    $order->order_no,
                    $order->user_id,
                    Order::STATUS_LABELS[$order->status] ?? $order->status,
                    $goods,
                    (string) $order->total_amount,
                    (string) $order->freight_amount,
                    (string) $order->pay_amount,
                    (string) $order->express_company,
                    (string) $order->tracking_no,
                    $addr['contact_name'] ?? '',
                    Mask::phone($addr['contact_phone'] ?? ''),
                    $addr['full_address'] ?? '',
                    (string) $order->remark,
                    $order->created_at?->format('Y-m-d H:i:s'),
                    $order->paid_at?->format('Y-m-d H:i:s'),
                    $order->shipped_at?->format('Y-m-d H:i:s'),
                    $order->completed_at?->format('Y-m-d H:i:s'),
                    $order->auto_completed ? '是' : '否',
                    (string) $order->cancel_reason,
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** 列表/导出共用筛选 */
    private function buildQuery(array $data)
    {
        return Order::query()
            ->when($data['order_no'] ?? null, fn ($q, $no) => $q->where('order_no', 'like', '%'.$no.'%'))
            ->when($data['user_id'] ?? null, fn ($q, $uid) => $q->where('user_id', $uid))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['start_time'] ?? null, fn ($q, $start) => $q->where('created_at', '>=', $start))
            ->when($data['end_time'] ?? null, fn ($q, $end) => $q->where('created_at', '<=', $end));
    }

    /** 列表项结构 */
    private function brief(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'user_id' => $order->user_id,
            'status' => $order->status,
            'status_label' => Order::STATUS_LABELS[$order->status] ?? $order->status,
            'total_amount' => $order->total_amount,
            'freight_amount' => $order->freight_amount,
            'pay_amount' => $order->pay_amount,
            // T-043：物流冗余双号
            'express_company' => $order->express_company,
            'tracking_no' => $order->tracking_no,
            'item_count' => (int) $order->items->sum('quantity'),
            'items' => $order->items->map(fn ($item) => [
                'product_title' => $item->product_title,
                'sku_specs' => $item->sku_specs ?? [],
                'price' => $item->price,
                'quantity' => $item->quantity,
                'total_amount' => $item->total_amount,
            ])->all(),
            'created_at' => $order->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 详情结构（brief + 优惠明细 + 物流轨迹，供后台订单详情页使用）
     *
     * 优惠口径以 `amount_details`（T-035 落库）为唯一来源，V1.0 老单无该字段时
     * 回退订单级 `discount_amount` / `promotion_discount`。
     */
    private function detail(Order $order): array
    {
        // 关联按需补齐（ship/accept 同样复用本方法，调用方未必预加载）
        $order->loadMissing(['coupon', 'shipping.traces']);

        // T-043：发货后补充 trace_status（取最新 shipping 记录）
        $traceStatus = $order->shipping->sortByDesc('id')->first()?->trace_status;

        // 注意：必须用 array_merge 而非 `+` —— PHP 数组 `+` 左侧优先，brief 已有的
        // items 键不会被覆盖，会导致下面带分摊的 items 被静默丢弃
        return array_merge($this->brief($order), [
            'remark' => $order->remark,
            'address_snapshot' => $order->address_snapshot,
            'cancel_reason' => $order->cancel_reason,
            'paid_at' => $order->paid_at?->format('Y-m-d H:i:s'),
            'shipped_at' => $order->shipped_at?->format('Y-m-d H:i:s'),
            'completed_at' => $order->completed_at?->format('Y-m-d H:i:s'),
            'cancelled_at' => $order->cancelled_at?->format('Y-m-d H:i:s'),
            'trace_status' => $traceStatus,
            'auto_completed' => (bool) $order->auto_completed,
            // 优惠金额（整单口径）
            'discount_amount' => $order->discount_amount,
            'promotion_discount' => $order->promotion_discount,
            'amount_details' => $order->amount_details,
            'coupon' => $order->coupon ? [
                'id' => $order->coupon->id,
                'name' => $order->coupon->name,
                'type' => $order->coupon->type,
                'amount' => $order->coupon->amount,
                'percent' => $order->coupon->percent,
                'min_spend' => $order->coupon->min_spend,
            ] : null,
            // 商品行覆盖 brief：附带券 / 满减分摊（T-035），详情页需展示每行实付
            'items' => $order->items->map(fn ($item) => [
                'product_title' => $item->product_title,
                'sku_specs' => $item->sku_specs ?? [],
                'price' => $item->price,
                'quantity' => $item->quantity,
                'total_amount' => $item->total_amount,
                'coupon_share' => $item->coupon_share,
                'promotion_share' => $item->promotion_share,
            ])->all(),
            // 物流与轨迹（traces 关联按发生时间倒序，最新在前）
            'shipping' => $order->shipping->map(fn ($s) => [
                'id' => $s->id,
                'company_code' => $s->company_code,
                'company_name' => $s->company_name,
                'tracking_no' => $s->tracking_no,
                'trace_status' => $s->trace_status,
                'shipped_at' => $s->shipped_at?->format('Y-m-d H:i:s'),
                'delivered_at' => $s->delivered_at?->format('Y-m-d H:i:s'),
                'pull_fail_count' => (int) $s->pull_fail_count,
                'last_fail_message' => $s->last_fail_message,
                'traces' => $s->traces->map(fn ($t) => [
                    'context' => $t->context,
                    'occurred_at' => $t->occurred_at?->format('Y-m-d H:i:s'),
                ])->all(),
            ])->all(),
            // WMS 履约只读摘要（WMS 计划 P3 / Step 7：无发货单为 null，前端据此隐藏）
            'wms_fulfillment' => $this->wmsFulfillmentSummary($order),
        ]);
    }

    /**
     * 订单资金视图（G7：统一「资金流水」聚合，权限 order.view）
     * GET /admin/orders/{id}/funds
     *
     * 一站式聚合与本单相关的全部资金记录：支付单（含每次支付事件）、余额流水、
     * 退款单（含优惠构成快照），配合 amount_details 金额快照；纠纷排查不再需要
     * 跨 payments / payment_logs / user_balance_logs / refunds / orders 五张表人工拼接。
     *
     * 口径约定：
     * - 余额流水经 related_type=payment 关联到本单支付单（BalanceGateway 唯一入出口）；
     * - summary.balance_* 仅统计余额渠道的真实资金进出，券/满减是商家让利不占资金；
     * - net_amount = 成功收款 − 成功退款（余额支付时该值近似 0 属正常，钱未出余额账户体系）。
     */
    public function funds(int $id): JsonResponse
    {
        $order = Order::query()->find($id);

        if (! $order) {
            throw BusinessException::notFound('订单不存在');
        }

        $payments = Payment::query()
            ->with('logs')
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();

        $paymentIds = $payments->pluck('id')->all();

        $balanceLogs = $paymentIds === []
            ? collect()
            : UserBalanceLog::query()
                ->where('related_type', 'payment')
                ->whereIn('related_id', $paymentIds)
                ->orderBy('id')
                ->get();

        $refunds = Refund::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();

        $paySuccess = $payments->where('status', Payment::STATUS_SUCCESS)->sum('amount');
        $refundSuccess = $refunds->where('status', Refund::STATUS_SUCCESS)->sum('amount');

        return $this->success([
            'order' => [
                'id' => $order->id,
                'order_no' => $order->order_no,
                'status' => $order->status,
                'status_label' => Order::STATUS_LABELS[$order->status] ?? $order->status,
                'user_id' => $order->user_id,
                'total_amount' => (string) $order->total_amount,
                'freight_amount' => (string) $order->freight_amount,
                'discount_amount' => (string) $order->discount_amount,
                'promotion_discount' => (string) $order->promotion_discount,
                'pay_amount' => (string) $order->pay_amount,
                // G1/G2 快照随 amount_details 一并下发（券/满减规则、运费明细均在其中）
                'amount_details' => $order->amount_details,
                'created_at' => $order->created_at?->format('Y-m-d H:i:s'),
                'paid_at' => $order->paid_at?->format('Y-m-d H:i:s'),
            ],
            'payments' => $payments->map(fn (Payment $p) => [
                'id' => $p->id,
                'payment_no' => $p->payment_no,
                'channel' => $p->channel,
                'channel_label' => $p->channel_label,
                'amount' => (string) $p->amount,
                'status' => $p->status,
                'status_label' => $p->status_label,
                'channel_trade_no' => $p->channel_trade_no,
                'paid_at' => $p->paid_at?->format('Y-m-d H:i:s'),
                'created_at' => $p->created_at?->format('Y-m-d H:i:s'),
            ])->all(),
            // 支付单事件流水（创建/回调/核账/查单/关闭…），事件原文可在支付日志页查看
            'payment_events' => $payments->flatMap(fn (Payment $p) => $p->logs
                ->sortBy('id')
                ->values()
                ->map(fn ($log) => [
                    'payment_no' => $p->payment_no,
                    'event' => $log->event,
                    'event_label' => $log->event_label,
                    'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
                ]))->values()->all(),
            'balance_logs' => $balanceLogs->map(fn (UserBalanceLog $log) => [
                'type' => $log->type,
                'type_label' => $log->type_label,
                'amount' => (string) $log->amount,
                'balance_before' => (string) $log->balance_before,
                'balance_after' => (string) $log->balance_after,
                'remark' => $log->remark,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
            ])->all(),
            'refunds' => $refunds->map(fn (Refund $r) => [
                'refund_no' => $r->refund_no,
                'type' => $r->type,
                'amount' => (string) $r->amount,
                'status' => $r->status,
                'status_label' => $r->status_label,
                // 优惠构成快照（G1-G3）：退款时固化到 refund_details，逐行可追溯
                'refund_details' => $r->refund_details,
                'reason' => $r->reason,
                'created_at' => $r->created_at?->format('Y-m-d H:i:s'),
                'processed_at' => $r->processed_at?->format('Y-m-d H:i:s'),
            ])->all(),
            'summary' => [
                // 渠道成功收款 / 成功退款（不含 pending / failed / closed）
                'pay_success_amount' => self::money($paySuccess),
                'refund_success_amount' => self::money($refundSuccess),
                // 余额渠道真实进出（consume 为负字符串，取绝对值口径，便于展示）
                'balance_consume_amount' => self::money($balanceLogs->where('type', UserBalanceLog::TYPE_CONSUME)->sum(fn ($l) => abs((float) $l->amount))),
                'balance_refund_amount' => self::money($balanceLogs->where('type', UserBalanceLog::TYPE_REFUND)->sum('amount')),
                // 净入账 = 成功收款 − 成功退款
                'net_amount' => self::money(max(0.0, $paySuccess - $refundSuccess)),
            ],
        ]);
    }

    /** 金额归一（两位小数字符串，容错 Collection sum 的浮点累加） */
    private static function money(float|int|string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * WMS 履约只读摘要（有发货单才返回）。
     *
     * @return array<string, mixed>|null
     */
    private function wmsFulfillmentSummary(Order $order): ?array
    {
        $fo = \App\Models\FulfillmentOrder::query()
            ->where('order_id', $order->id)
            ->first();

        if (! $fo) {
            return null;
        }

        return [
            'id' => $fo->id,
            'outbound_no' => $fo->outbound_no,
            'status' => $fo->status,
            'status_label' => $fo->statusLabel(),
            'warehouse_id' => (int) $fo->warehouse_id,
            'push_times' => (int) $fo->push_times,
            'last_push_error' => $fo->last_push_error,
            'wms_outbound_no' => $fo->wms_outbound_no,
            'carrier_code' => $fo->carrier_code,
            'tracking_no' => $fo->tracking_no,
        ];
    }
}
